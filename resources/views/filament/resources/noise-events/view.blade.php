@php
    use App\Support\LocalTime;
    use App\Enums\Metric;
    $tz = $timezone;
    $stream = $event->stream;
    $agent = $event->agent_summary ?? [];
    $server = $event->server_summary ?? null;
    $triggerMetric = Metric::from($event->trigger_metric);
@endphp
<x-filament-panels::page>
    <div class="space-y-6">
        {{-- State overview --}}
        <x-filament::section>
            <div class="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <div class="text-xs uppercase text-gray-500">Detection window</div>
                    <div>{{ LocalTime::display($event->started_at, $tz) }}</div>
                    <div>→ {{ $event->ended_at ? LocalTime::display($event->ended_at, $tz) : 'open (no end reported)' }}</div>
                    <div class="text-xs text-gray-500">UTC {{ \App\Support\Rfc3339::format($event->started_at) }} – {{ \App\Support\Rfc3339::format($event->ended_at) ?? 'open' }}</div>
                </div>
                <div>
                    <div class="text-xs uppercase text-gray-500">Device / channel</div>
                    <div>{{ $event->device->name }} · {{ $event->channel }}</div>
                    <div class="text-xs text-gray-500">Event ID <span class="font-mono">{{ $event->uuid }}</span></div>
                    <div class="text-xs text-gray-500">Duration {{ $event->isOpen() ? '—' : number_format($event->durationMs() / 1000, 1).' s' }}</div>
                </div>
                <div class="flex flex-wrap content-start gap-1">
                    <x-filament::badge :color="$event->isOpen() ? 'warning' : 'gray'">Detection: {{ $event->detection_state->getLabel() }}</x-filament::badge>
                    @if ($event->marked_incomplete_at)
                        <x-filament::badge color="warning">Marked incomplete</x-filament::badge>
                    @endif
                    <x-filament::badge :color="match ($event->completeness_state->value) { 'complete' => 'success', 'partial' => 'warning', 'unavailable' => 'danger', default => 'gray' }">Data: {{ $event->completeness_state->getLabel() }}</x-filament::badge>
                    <x-filament::badge :color="match ($event->recording_state?->value) { 'verified' => 'success', 'failed', 'missing' => 'danger', 'purged', null => 'gray', default => 'warning' }">Recording: {{ $event->recording_state?->getLabel() ?? 'none expected' }}</x-filament::badge>
                </div>
                <div class="flex flex-wrap content-start gap-1">
                    <x-filament::badge :color="$event->review_status->value === 'confirmed_disturbance' ? 'danger' : 'gray'">{{ $event->review_status->getLabel() }}</x-filament::badge>
                    @if ($event->source_label)
                        <x-filament::badge color="gray">{{ $event->source_certainty?->getLabel() }}: {{ $event->source_label->getLabel() }}</x-filament::badge>
                    @endif
                    @if ($event->review_confidence)
                        <x-filament::badge color="gray">Confidence: {{ $event->review_confidence->getLabel() }}</x-filament::badge>
                    @endif
                    @if ($event->keep)
                        <x-filament::badge color="info" icon="heroicon-s-lock-closed">Kept</x-filament::badge>
                    @endif
                    @foreach ($event->groups as $group)
                        <x-filament::badge color="gray" icon="heroicon-m-rectangle-group">{{ $group->name }}</x-filament::badge>
                    @endforeach
                    @foreach ($event->qualityFlagList() as $flag)
                        <x-filament::badge color="warning">{{ $flag->getLabel() }}</x-filament::badge>
                    @endforeach
                </div>
            </div>
        </x-filament::section>

        {{-- Chart --}}
        <x-filament::section>
            <x-slot name="heading">Readings around the event</x-slot>
            <x-slot name="description">Window: 10 s before detection to 30 s after its end, bounded by available data. The highlighted band is the detection window.</x-slot>
            <x-slot name="afterHeader">
                <div class="flex items-center gap-3 text-sm">
                    <x-filament::input.wrapper><x-filament::input.select wire:model.live="metric">
                        @foreach ($metrics as $option)
                            <option value="{{ $option->value }}">{{ $option->labelWithUnit() }}</option>
                        @endforeach
                    </x-filament::input.select></x-filament::input.wrapper>
                    <label class="flex items-center gap-1"><x-filament::input.checkbox wire:model.live="showTable" /> Data table</label>
                </div>
            </x-slot>
            <x-noise.time-chart :chart="$chart" :chart-key="$chartKey" :show-table="$showTable" height="16rem" id="event-chart" />
        </x-filament::section>

        <div class="grid gap-6 lg:grid-cols-2">
            {{-- Audio --}}
            <x-filament::section>
                <x-slot name="heading">Audio</x-slot>
                <x-slot name="description">Event clips only. Originals are preserved unmodified; playback uses short-lived authorized links.</x-slot>
                @if ($event->recordings->isEmpty())
                    <p class="text-sm text-gray-600 dark:text-gray-300">
                        @switch($event->recording_state?->value)
                            @case('missing') No recording was declared by the device within {{ config('noise.events.recording_missing_after_hours') }} hours. It can still arrive later. @break
                            @case('pending') A recording is expected; the device has not declared it yet. Audio may arrive hours after the event. @break
                            @default This event does not expect a recording.
                        @endswitch
                    </p>
                @endif
                <div class="space-y-4">
                    @foreach ($event->recordings as $recording)
                        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10" wire:key="rec-{{ $recording->uuid }}">
                            <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                                <div>
                                    <strong>Segment {{ $recording->segment_number }}</strong>
                                    · {{ strtoupper($recording->fileExtension()) }} {{ $recording->sample_rate_hz }} Hz {{ $recording->bit_depth ? $recording->bit_depth.'-bit' : '' }} mono
                                    · {{ number_format($recording->duration_ms / 1000, 1) }} s · {{ \Illuminate\Support\Number::fileSize($recording->byte_size) }}
                                </div>
                                <x-filament::badge :color="match ($recording->status->value) { 'verified' => 'success', 'failed' => 'danger', 'purged' => 'gray', default => 'warning' }">{{ $recording->status->getLabel() }}</x-filament::badge>
                            </div>
                            <div class="mt-1 text-xs text-gray-500">Capture start {{ LocalTime::display($recording->capture_started_at, $tz) }}</div>

                            @if ($recording->isPlayable())
                                <div class="mt-2 text-xs">Verified SHA-256 <span class="break-all font-mono">{{ $recording->verified_sha256 }}</span></div>
                                @if (isset($playbackUrls[$recording->uuid]))
                                    <audio class="mt-2 w-full" controls preload="metadata" src="{{ $playbackUrls[$recording->uuid] }}"
                                        x-data="{ origin: {{ $recording->capture_started_at->getTimestampMs() }} }"
                                        x-on:timeupdate="$dispatch('noise-cursor', origin + Math.round($el.currentTime * 1000))"
                                        aria-label="Event audio segment {{ $recording->segment_number }}"></audio>
                                    <p class="mt-1 text-xs text-gray-500">Playing the verified original. The chart cursor follows playback. Link expires in {{ config('noise.recordings.playback_url_ttl_minutes') }} minutes.</p>
                                @else
                                    <x-filament::button class="mt-2" size="sm" icon="heroicon-m-play" wire:click="loadPlayback('{{ $recording->uuid }}')">Load player</x-filament::button>
                                @endif
                                @can('downloadOriginal', $event)
                                    <x-filament::button class="mt-2" size="sm" color="gray" icon="heroicon-m-arrow-down-tray" wire:click="downloadOriginal('{{ $recording->uuid }}')">Download original</x-filament::button>
                                @endcan
                            @elseif ($recording->status->value === 'purged')
                                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">Purged {{ LocalTime::display($recording->purged_at, $tz) }} ({{ $recording->purge_reason }}). Identity and hash are retained: <span class="break-all font-mono text-xs">{{ $recording->verified_sha256 ?? $recording->reported_sha256 }}</span></p>
                            @elseif ($recording->status->value === 'failed')
                                <p class="mt-2 text-sm text-danger-600">Verification failed: {{ $recording->failure_reason }}. The upload is quarantined; the device may retry with a new upload attempt. Declared SHA-256 <span class="break-all font-mono text-xs">{{ $recording->reported_sha256 }}</span></p>
                            @else
                                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">Waiting for the device to upload and the server to verify this segment.</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-filament::section>

            {{-- Summaries --}}
            <x-filament::section>
                <x-slot name="heading">Summary</x-slot>
                <x-slot name="description">Agent-reported values are shown separately from values the server derived from stored readings.</x-slot>
                <div class="space-y-3 text-sm">
                    <div>
                        <div class="font-medium">Trigger (agent)</div>
                        <div>{{ $triggerMetric->getLabel() }} {{ $event->trigger_value_db === null ? '—' : number_format($event->trigger_value_db, 1).' '.$triggerMetric->unit() }}
                            · rule {{ $event->detection_rule_version }} ({{ str_replace('_', ' ', $event->trigger_kind) }}{{ $event->trigger_threshold_db !== null ? ', threshold '.number_format($event->trigger_threshold_db, 1).' dB' : '' }})</div>
                        <div>Baseline (agent-reported): {{ $event->baseline_db === null ? '—' : number_format($event->baseline_db, 1).' '.$triggerMetric->unit() }} · {{ $event->baseline_method ?? 'method not reported' }}</div>
                        @if ($event->levelAboveBaseline() !== null)
                            <div>{{ $event->levelAboveBaseline() }} dB above baseline <span class="text-xs text-gray-500">(simple level difference — not source isolation or a background-corrected exhaust level)</span></div>
                        @endif
                    </div>
                    <table class="w-full text-left text-xs nm-tabular">
                        <thead class="text-gray-500"><tr><th>Metric</th><th>Agent summary</th><th>Server-derived (detection window)</th></tr></thead>
                        <tbody>
                            @foreach (Metric::cases() as $m)
                                @php
                                    $a = $agent[$m->value] ?? null;
                                    $s = $server['metrics'][$m->value] ?? null;
                                @endphp
                                <tr class="border-t border-gray-100 dark:border-white/5">
                                    <td>{{ $m->labelWithUnit() }}</td>
                                    <td>{{ $a === null ? '—' : number_format($a, 1) }}</td>
                                    <td>{{ $s === null ? '—' : number_format($s, 1) }}
                                        @if ($a !== null && $s !== null && abs($a - $s) >= 1.0)
                                            <span class="text-warning-600">Δ {{ number_format($s - $a, 1) }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @if ($server)
                        <p class="text-xs text-gray-500">
                            Server values use {{ intdiv($server['detection_measured_ms'] ?? 0, 1000) }} s of stored one-second readings{{ $server['detection_expected_ms'] ? ' of '.intdiv($server['detection_expected_ms'], 1000).' s in the detection window' : '' }} under quality policy {{ $server['policy_version'] }}.
                            Differences can come from missing seconds, quality exclusions, the agent's own windowing, or its sub-second processing; the original agent values are never replaced.
                        </p>
                    @else
                        <p class="text-xs text-gray-500">The server has not derived a summary yet.</p>
                    @endif
                </div>
            </x-filament::section>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            {{-- Provenance --}}
            <x-filament::section>
                <x-slot name="heading">Provenance</x-slot>
                <dl class="grid grid-cols-3 gap-x-3 gap-y-1 text-sm">
                    <dt class="text-gray-500">Placement</dt><dd class="col-span-2">{{ $stream?->deployment?->label() }}<div class="text-xs text-gray-500">{{ $stream?->deployment?->placement_description }}</div></dd>
                    <dt class="text-gray-500">Profile</dt><dd class="col-span-2">{{ $stream?->profile?->label() }}<div class="text-xs text-gray-500">{{ $stream?->profile?->sample_rate_hz }} Hz · gain {{ $stream?->profile?->gain_db ?? '—' }} dB · weighting {{ $stream?->profile?->weighting_implementation_version }} · agent {{ $stream?->profile?->agent_processing_version }}</div></dd>
                    <dt class="text-gray-500">Calibration</dt><dd class="col-span-2">
                        <x-filament::badge size="sm" :color="$stream?->calibration_state->getColor()">{{ $stream?->calibration_state->getLabel() }}</x-filament::badge>
                        <div class="text-xs text-gray-500">{{ $stream?->calibration ? $stream->calibration->label() : 'No calibration record (dBFS only)' }}</div>
                        <div class="text-xs text-gray-500">{{ $stream?->calibration_state->getDescription() }}</div>
                    </dd>
                    <dt class="text-gray-500">Configuration</dt><dd class="col-span-2">revision r{{ $event->configuration_revision }}</dd>
                    <dt class="text-gray-500">Agent revision</dt><dd class="col-span-2">r{{ $event->current_revision }} of {{ $event->revisions->count() }} received</dd>
                </dl>
            </x-filament::section>

            {{-- Snapshot --}}
            <x-filament::section>
                <x-slot name="heading">Measurement snapshot</x-slot>
                @if ($snapshot === null)
                    <p class="text-sm text-gray-500">No snapshot yet.</p>
                @else
                    <div class="space-y-2 text-sm">
                        <div>Version {{ $snapshot->version }} · {{ $snapshot->isFrozen() ? 'frozen '.LocalTime::display($snapshot->frozen_at, $tz) : 'still collecting late readings' }}</div>
                        <div>{{ $snapshot->captured_intervals }} of {{ $snapshot->expected_intervals }} one-second intervals present · {{ $snapshot->completeness->getLabel() }}</div>
                        @if ($snapshot->limitations)
                            <div class="text-warning-600">{{ $snapshot->limitations }}</div>
                        @endif
                        @if ($snapshot->missing_ranges)
                            <details><summary class="cursor-pointer text-xs">Missing ranges ({{ count($snapshot->missing_ranges) }})</summary>
                                <ul class="text-xs">
                                    @foreach ($snapshot->missing_ranges as $gap)
                                        <li>{{ LocalTime::display(\Carbon\CarbonImmutable::parse($gap['start']), $tz) }} – {{ LocalTime::display(\Carbon\CarbonImmutable::parse($gap['end']), $tz) }}</li>
                                    @endforeach
                                </ul>
                            </details>
                        @endif
                        @if ($snapshot->content_hash)
                            <div class="text-xs text-gray-500">Snapshot SHA-256 <span class="break-all font-mono">{{ $snapshot->content_hash }}</span></div>
                        @endif
                    </div>
                @endif
                @if ($bands !== [])
                    <div class="mt-4">
                        <div class="text-sm font-medium">Third-octave bands — event average over the detection window</div>
                        <div class="text-xs text-gray-500">Energy-averaged per band over time; bands are not summed. Weighting and band definitions come from the measurement profile.</div>
                        <div class="mt-2 h-56" wire:ignore wire:key="bands-{{ md5(json_encode($bands)) }}" x-data="noiseChart('bands', @js(['bands' => $bands, 'label' => 'Event average', 'weighting' => $bands[0]['weighting'] ?? 'Z']))">
                            <canvas x-ref="canvas" role="img" aria-label="Third-octave band levels"></canvas>
                        </div>
                        <table class="mt-2 w-full text-xs nm-tabular"><tbody>
                            @foreach ($bands as $band)
                                <tr><td>{{ $band['center_hz'] }} Hz ({{ $band['weighting'] }})</td><td>{{ $band['level_db'] === null ? '—' : number_format($band['level_db'], 1).' dB' }}</td></tr>
                            @endforeach
                        </tbody></table>
                    </div>
                @endif
            </x-filament::section>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            {{-- Annotations --}}
            <x-filament::section>
                <x-slot name="heading">Review history</x-slot>
                <x-slot name="description">Append-only. Later entries supersede earlier ones; nothing is edited in place.</x-slot>
                @forelse ($event->annotations->sortByDesc('id') as $annotation)
                    <div class="border-t border-gray-100 py-2 text-sm first:border-t-0 dark:border-white/5">
                        <div class="flex flex-wrap items-center gap-1">
                            <span class="font-medium">{{ $annotation->author?->name ?? 'Unknown' }}</span>
                            <span class="text-xs text-gray-500">{{ LocalTime::display($annotation->created_at, $tz) }}</span>
                            <x-filament::badge size="sm" color="gray">{{ str_replace('_', ' ', $annotation->kind) }}</x-filament::badge>
                            @if ($annotation->review_status)
                                <x-filament::badge size="sm">{{ $annotation->review_status->getLabel() }}</x-filament::badge>
                            @endif
                            @if ($annotation->source_label)
                                <x-filament::badge size="sm" color="gray">{{ $annotation->source_certainty?->getLabel() }} {{ $annotation->source_label->getLabel() }}</x-filament::badge>
                            @endif
                            @if ($annotation->confidence)
                                <x-filament::badge size="sm" color="gray">{{ $annotation->confidence->getLabel() }} confidence</x-filament::badge>
                            @endif
                        </div>
                        @if ($annotation->notes)
                            <p class="mt-1 whitespace-pre-line">{{ $annotation->notes }}</p>
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-gray-500">Not reviewed yet.</p>
                @endforelse
            </x-filament::section>

            {{-- Timeline --}}
            <x-filament::section>
                <x-slot name="heading">Timeline (server receipt times)</x-slot>
                <ol class="space-y-2 text-sm">
                    @foreach ($timeline as $item)
                        <li>
                            <span class="text-xs text-gray-500">{{ LocalTime::display($item['at'], $tz) }}</span>
                            <div>{{ $item['label'] }}</div>
                            @if ($item['detail'])
                                <div class="break-all text-xs text-gray-500">{{ $item['detail'] }}</div>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
