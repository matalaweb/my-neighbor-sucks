<x-filament::section>
    <x-slot name="heading">Set up monitoring</x-slot>
    <div class="prose prose-sm max-w-none dark:prose-invert">
        <ol class="list-decimal space-y-2 ps-5 text-sm">
            <li @class(['text-gray-400 line-through' => $property !== null])>
                <strong>Create a property</strong> and confirm its timezone (default America/Chicago).
                @if ($property === null && auth()->user()->canManage(\Filament\Facades\Filament::getTenant()))
                    <a class="text-primary-600 underline" href="{{ \App\Filament\Resources\Properties\PropertyResource::getUrl('create') }}">Create property</a>
                @endif
            </li>
            <li><strong>Create a device</strong> for the Raspberry Pi, then record the microphone placement. The Pi reports its own measurement profile (microphone, gain, processing versions, calibration state) and calibration; readings are assigned the placement in effect when they were captured.
                @if ($property !== null)
                    <a class="text-primary-600 underline" href="{{ \App\Filament\Resources\Devices\DeviceResource::getUrl('create') }}">Create device</a>
                @endif
            </li>
            <li><strong>Publish a configuration</strong> when you want to change the Pi's local defaults (reporting every 30 s, heartbeat every 60 s, recording pre-roll 10 s / post-roll 30 s, no detection rules). Detection thresholds are your choice; run an observation period before relying on one.</li>
            <li><strong>Issue a device credential</strong>. It is displayed once — copy it into the Pi agent configuration. The agent uses <code>/api/v1/device</code>.</li>
            <li>No Pi yet? Run the simulator: <code>php artisan noise:simulate --token=… --scenario=burst</code>. Synthetic data is clearly labelled.</li>
        </ol>
    </div>
</x-filament::section>
