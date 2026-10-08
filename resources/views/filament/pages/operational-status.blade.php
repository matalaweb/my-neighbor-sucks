@php use App\Support\LocalTime; @endphp
<x-filament-panels::page>
    <div wire:poll.visible.30s class="grid gap-6 lg:grid-cols-2">
        <x-filament::section>
            <x-slot name="heading">Devices</x-slot>
            <table class="w-full text-left text-sm">
                <thead class="text-xs text-gray-500"><tr><th>Device</th><th>Contact</th><th>Latest capture</th><th>Backlog</th></tr></thead>
                <tbody>
                @forelse ($devices as $device)
                    @php $hb = $device->latestHeartbeat; @endphp
                    <tr class="border-t border-gray-100 dark:border-white/5">
                        <td class="py-1">{{ $device->name }}</td>
                        <td><x-filament::badge size="sm" :color="$device->isOnline() ? 'success' : 'danger'">{{ LocalTime::age($device->last_contact_at) }}</x-filament::badge></td>
                        <td>{{ LocalTime::age($device->latest_capture_at) }} @if ($device->measurementsAreStale()) <x-filament::badge size="sm" color="warning">stale</x-filament::badge> @endif</td>
                        <td class="text-xs">{{ number_format($hb?->queued_measurement_count ?? 0) }} readings · {{ \Illuminate\Support\Number::fileSize($hb?->pending_audio_bytes ?? 0) }} audio @if ($hb?->oldest_pending_capture_at) · oldest {{ LocalTime::age($hb->oldest_pending_capture_at) }} @endif</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="py-2 text-gray-500">No active devices.</td></tr>
                @endforelse
                </tbody>
            </table>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Queues and durable work</x-slot>
            <div class="flex flex-wrap gap-2 text-sm">
                @foreach ($queues as $name => $size)
                    <x-filament::badge color="gray">{{ $name }}: {{ $size ?? 'unknown' }} queued</x-filament::badge>
                @endforeach
            </div>
            <table class="mt-3 w-full text-left text-sm">
                <thead class="text-xs text-gray-500"><tr><th>Work</th><th>Status</th><th>Count</th><th>Oldest</th></tr></thead>
                <tbody>
                @forelse ($outbox as $row)
                    <tr class="border-t border-gray-100 dark:border-white/5"><td>{{ $row->kind->getLabel() }}</td><td>{{ $row->status->getLabel() }}</td><td>{{ $row->total }}</td><td>{{ LocalTime::age(\Carbon\CarbonImmutable::parse($row->oldest)) }}</td></tr>
                @empty
                    <tr><td colspan="4" class="py-2 text-gray-500">No outstanding derived work.</td></tr>
                @endforelse
                </tbody>
            </table>
            <p class="mt-3 text-xs text-gray-500">Last retention run: {{ $lastRetention ? LocalTime::display(\Carbon\CarbonImmutable::parse($lastRetention['at']), $timezone).' ('.($lastRetention['ok'] ? 'ok' : 'with failures').')' : 'not recorded yet' }}</p>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Device API (last 24 h)</x-slot>
            <table class="w-full text-left text-xs nm-tabular">
                <thead class="text-gray-500"><tr><th>Endpoint</th><th>OK</th><th>Rejected (4xx)</th><th>Failed (5xx)</th><th>Avg / max ms</th><th>Rejected rows</th></tr></thead>
                <tbody>
                @forelse ($metrics as $m)
                    <tr class="border-t border-gray-100 dark:border-white/5"><td>{{ $m->endpoint }}</td><td>{{ $m->ok }}</td><td>{{ $m->rejected }}</td><td>{{ $m->failed }}</td><td>{{ (int) $m->avg_ms }} / {{ $m->max_ms }}</td><td>{{ $m->rejected_rows }}</td></tr>
                @empty
                    <tr><td colspan="6" class="py-2 text-gray-500">No device requests in the last 24 hours.</td></tr>
                @endforelse
                </tbody>
            </table>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Failures needing attention</x-slot>
            <h3 class="text-sm font-semibold">Recording verification failures</h3>
            <ul class="text-sm">
                @forelse ($verificationFailures as $recording)
                    <li class="py-1"><a class="text-primary-600 hover:underline" href="{{ \App\Filament\Resources\NoiseEvents\NoiseEventResource::getUrl('view', ['record' => $recording->noiseEvent]) }}">Event {{ substr($recording->noiseEvent->uuid, 0, 8) }} segment {{ $recording->segment_number }}</a> — {{ $recording->failure_reason }}</li>
                @empty
                    <li class="text-gray-500">None.</li>
                @endforelse
            </ul>
            <h3 class="mt-3 text-sm font-semibold">Failed cleanup / derived work</h3>
            <ul class="text-xs">
                @forelse ($failedMaintenance as $job)
                    <li class="py-1">{{ $job->kind->getLabel() }} · {{ $job->attempts }} attempts · {{ str($job->last_error)->limit(160) }}</li>
                @empty
                    <li class="text-gray-500">None.</li>
                @endforelse
            </ul>
            <h3 class="mt-3 text-sm font-semibold">Failed queue jobs (all accounts)</h3>
            <ul class="text-xs">
                @forelse ($failedJobs as $job)
                    <li class="py-1">{{ $job->queue }} · {{ $job->failed_at }} · {{ $job->exception }}</li>
                @empty
                    <li class="text-gray-500">None.</li>
                @endforelse
            </ul>
        </x-filament::section>

        <x-filament::section class="lg:col-span-2">
            <x-slot name="heading">Recent audit events</x-slot>
            <x-slot name="description">Traceability log; not a tamper-proof chain of custody.</x-slot>
            <table class="w-full text-left text-xs">
                <thead class="text-gray-500"><tr><th>When</th><th>Actor</th><th>Action</th><th>Entity</th></tr></thead>
                <tbody>
                @foreach ($audit as $entry)
                    <tr class="border-t border-gray-100 dark:border-white/5">
                        <td class="py-1">{{ LocalTime::display($entry->created_at, $timezone) }}</td>
                        <td>{{ $entry->user?->name ?? ($entry->device ? 'device: '.$entry->device->name : 'system') }}</td>
                        <td>{{ $entry->action }}</td>
                        <td class="font-mono">{{ $entry->entity_type }} {{ $entry->entity_uuid ? substr($entry->entity_uuid, 0, 8) : '' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </x-filament::section>
    </div>
</x-filament-panels::page>
