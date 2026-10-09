<?php

namespace App\Livewire;

use App\Services\Devices\DeviceSharingService;
use App\Services\Measurements\PublicDeviceDashboard as PublicDashboardData;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Public, read-only device dashboard behind a share link. The token is
 * re-resolved on every render (including polls), so revoking or
 * regenerating the link takes effect immediately.
 */
class PublicDeviceDashboard extends Component
{
    #[Locked]
    public string $token;

    #[Url]
    public string $range = PublicDashboardData::DEFAULT_RANGE;

    public function mount(string $token): void
    {
        $this->token = $token;
        $this->range = $this->allowedRange($this->range);
    }

    public function setRange(string $range): void
    {
        $this->range = $this->allowedRange($range);
    }

    public function render(DeviceSharingService $sharing, PublicDashboardData $dashboard): View
    {
        $device = $sharing->resolve($this->token);

        abort_if($device === null, 404);

        $data = $dashboard->build($device, $this->allowedRange($this->range));

        return view('livewire.public-device-dashboard', [
            'dashboard' => $data,
            'ranges' => PublicDashboardData::RANGES,
            'chartKey' => md5(json_encode($data['chart'])),
            'pollSeconds' => (int) config('noise.sharing.poll_seconds'),
        ]);
    }

    private function allowedRange(string $range): string
    {
        return array_key_exists($range, PublicDashboardData::RANGES) ? $range : PublicDashboardData::DEFAULT_RANGE;
    }
}
