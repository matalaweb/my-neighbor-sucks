<?php

namespace App\Console\Commands;

use App\Enums\CalibrationState;
use App\Enums\MembershipRole;
use App\Models\Account;
use App\Models\Device;
use App\Models\Property;
use App\Models\User;
use App\Services\Devices\DeviceConfigurationService;
use App\Services\Devices\DeviceCredentialService;
use App\Services\Devices\ProvenanceRecords;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Development helper: provisions a clearly labelled synthetic demo account
 * and device for the simulator. Never use for a real household.
 */
#[Signature('noise:demo:provision
    {--account=Demo (synthetic) : Name of the demo account to create or reuse}
    {--email=demo-owner@example.test : Owner email for the demo account}
    {--with-uncalibrated : Also provision an uncalibrated mic-2 channel (dBFS only)}
    {--force : Allow running in production (not recommended)}')]
#[Description('Provision a synthetic demo account, device, provenance, configuration, and device token (development only)')]
class ProvisionDemoDevice extends Command
{
    public function handle(ProvenanceRecords $provenance, DeviceConfigurationService $configurations, DeviceCredentialService $credentials): int
    {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('Refusing to provision synthetic demo data in production (use --force only for a throwaway environment).');

            return self::FAILURE;
        }

        $accountName = (string) $this->option('account');

        if (! str_contains(strtolower($accountName), 'demo')) {
            $accountName .= ' — demo (synthetic)';
        }

        $password = null;

        [$account, $owner, $property] = DB::transaction(function () use ($accountName, &$password): array {
            $account = Account::query()->firstOrCreate(['name' => $accountName]);
            $owner = User::query()->where('email', $this->option('email'))->first();

            if ($owner === null) {
                $password = Str::password(20);
                $owner = User::query()->create([
                    'name' => 'Demo owner',
                    'email' => $this->option('email'),
                    'password' => $password,
                    'current_account_id' => $account->id,
                ]);
            }

            if (! $account->users()->whereKey($owner->id)->exists()) {
                $account->users()->attach($owner, ['role' => MembershipRole::Owner->value]);
            }

            $property = Property::query()->firstOrCreate(
                ['account_id' => $account->id, 'name' => 'Demo house (synthetic)'],
                ['timezone' => config('noise.default_timezone')],
            );

            return [$account, $owner, $property];
        });

        $device = Device::query()->create([
            'account_id' => $account->id,
            'property_id' => $property->id,
            'name' => 'Simulated Pi '.Str::upper(Str::random(4)),
            'status' => 'active',
        ]);

        $deployment = $provenance->createDeployment($device, [
            'room' => 'Front bedroom (demo)',
            'location_type' => 'indoor',
            'placement_description' => 'Synthetic demo placement facing the driveway',
            'mounting_notes' => 'Simulated device; no physical microphone.',
            'effective_at' => now()->subDays(40)->toIso8601String(),
        ], $owner);

        $allMetrics = ['laeq_db', 'lafmax_db', 'lceq_db', 'lcpeak_db', 'low_frequency_leq_db', 'rms_dbfs'];

        $profile = $provenance->createProfile($device, [
            'channel' => 'mic-1',
            'name' => 'Simulated calibrated channel',
            'microphone_model' => 'SIMULATED measurement microphone',
            'microphone_serial' => 'SIM-0001',
            'sample_rate_hz' => 48000,
            'gain_db' => 0,
            'weighting_implementation_version' => 'simulator-weighting-1',
            'filter_implementation_version' => 'simulator-filters-1',
            'calibration_state' => CalibrationState::Calibrated,
            'calibration_application_method' => 'Synthetic offset (simulator)',
            'supported_metrics' => $allMetrics,
            'low_frequency_lower_hz' => 20,
            'low_frequency_upper_hz' => 125,
            'agent_processing_version' => 'simulator-1.0',
        ], $owner);

        $calibration = $provenance->createCalibration($device, [
            'channel' => 'mic-1',
            'calibration_state' => CalibrationState::Calibrated,
            'reference_method' => 'Synthetic reference (simulator; not a real calibration)',
            'reference_level_db' => 94,
            'reference_frequency_hz' => 1000,
            'notes' => 'Demo data only.',
        ], $owner);

        $settings = $configurations->defaults();
        $settings['relative_enabled'] = true;
        $settings['relative_delta_db'] = 15;
        $settings['channels'] = [[
            'channel' => 'mic-1',
            'enabled' => true,
            'metrics' => $allMetrics,
            'bands_enabled' => false,
            'measurement_profile_id' => $profile->uuid,
            'deployment_id' => $deployment->uuid,
            'calibration_id' => $calibration->uuid,
        ]];

        if ($this->option('with-uncalibrated')) {
            $uncalibrated = $provenance->createProfile($device, [
                'channel' => 'mic-2',
                'name' => 'Simulated uncalibrated channel',
                'microphone_model' => 'SIMULATED USB microphone',
                'sample_rate_hz' => 48000,
                'weighting_implementation_version' => 'simulator-weighting-1',
                'filter_implementation_version' => 'simulator-filters-1',
                'calibration_state' => CalibrationState::Uncalibrated,
                'supported_metrics' => ['rms_dbfs'],
                'agent_processing_version' => 'simulator-1.0',
            ], $owner);

            $settings['channels'][] = [
                'channel' => 'mic-2',
                'enabled' => true,
                'metrics' => ['rms_dbfs'],
                'bands_enabled' => false,
                'measurement_profile_id' => $uncalibrated->uuid,
                'deployment_id' => $deployment->uuid,
                'calibration_id' => null,
            ];
        }

        $configuration = $configurations->publish($device, $settings, $owner, 'Demo configuration (synthetic)');
        $token = $credentials->issue($device, $owner, 'Simulator credential')['token'];

        $this->components->info('Provisioned SYNTHETIC demo device. Readings it produces are not real measurements.');
        $this->table(['Item', 'Value'], array_filter([
            ['Account', $account->name.' ('.$account->uuid.')'],
            ['Owner login', $owner->email],
            $password ? ['Owner password (shown once)', $password] : null,
            ['Device', $device->name.' ('.$device->uuid.')'],
            ['Configuration revision', (string) $configuration->revision],
        ]));
        $this->newLine();
        $this->line('Device token (shown once; store it now):');
        $this->line($token);
        $this->newLine();
        $this->line('Example: php artisan noise:simulate --token='.$token.' --scenario=burst');

        return self::SUCCESS;
    }
}
