<?php

namespace App\Providers;

use App\Http\DeviceApi\DeviceTokenResolver;
use App\Models\DeviceCredential;
use App\Services\Storage\EvidenceStorage;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EvidenceStorage::class);
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());
        Model::automaticallyEagerLoadRelationships();

        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        Auth::viaRequest('device-token', $this->app->make(DeviceTokenResolver::class));

        $this->configureRateLimiting();

        // Re-apply route throttling to Livewire updates (polls on public share links).
        Livewire::addPersistentMiddleware([ThrottleRequests::class]);
    }

    private function configureRateLimiting(): void
    {
        $deviceKey = function (Request $request): string {
            $credential = $request->user('device');

            return $credential instanceof DeviceCredential ? 'device:'.$credential->device_id : 'ip:'.$request->ip();
        };

        // 60 batches/minute per device with a burst of 10 (fixed-window approximation).
        RateLimiter::for('device-measurements', fn (Request $request): array => [
            Limit::perMinute((int) config('noise.device_api.measurement_rate_per_minute'))->by('m:'.$deviceKey($request)),
            Limit::perSecond((int) config('noise.device_api.measurement_burst'), 10)->by('mb:'.$deviceKey($request)),
        ]);

        RateLimiter::for('device-control', fn (Request $request): Limit => Limit::perMinute((int) config('noise.device_api.control_rate_per_minute'))->by('c:'.$deviceKey($request)));

        RateLimiter::for('device-recordings', fn (Request $request): Limit => Limit::perMinute((int) config('noise.device_api.recording_rate_per_minute'))->by('r:'.$deviceKey($request)));

        RateLimiter::for('public-share', fn (Request $request): Limit => Limit::perMinute((int) config('noise.sharing.requests_per_minute'))->by('share:'.$request->ip()));

        RateLimiter::for('device-events', fn (Request $request): Limit => Limit::perMinute(120)->by('e:'.$deviceKey($request)));
    }
}
