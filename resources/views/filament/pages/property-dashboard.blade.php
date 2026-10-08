<x-filament-panels::page>
    <div wire:poll.visible.{{ config('noise.dashboard.poll_seconds') }}s class="space-y-6">
        @if ($property === null || $device === null)
            @include('filament.partials.onboarding', ['property' => $property])
        @else
            @php
                $tz = $property->timezone;
                $latest = $summary['latest'];
                $hb = $summary['heartbeat'];
            @endphp

            {{-- Filters --}}
            <x-filament::section compact>
                <div class="flex flex-wrap items-end gap-3">
                    @if ($this->properties()->count() > 1)
                        <label class="text-sm">
                            <span class="block text-xs text-gray-500">Property</span>
                            <x-filament::input.wrapper><x-filament::input.select wire:model.live="propertyUuid">
                                @foreach ($this->properties() as $option)
                                    <option value="{{ $option->uuid }}">{{ $option->name }}</option>
                                @endforeach
                            </x-filament::input.select></x-filament::input.wrapper>
                        </label>
                    @endif
                    <label class="text-sm">
                        <span class="block text-xs text-gray-500">Device</span>
                        <x-filament::input.wrapper><x-filament::input.select wire:model.live="deviceUuid">
                            @foreach ($this->devices() as $option)
                                <option value="{{ $option->uuid }}" @selected($option->is($device))>{{ $option->name }}</option>
                            @endforeach
                        </x-filament::input.select></x-filament::input.wrapper>
                    </label>
                    <label class="text-sm">
                        <span class="block text-xs text-gray-500">Date ({{ $tz }})</span>
                        <div class="flex items-center gap-1">
                            <x-filament::icon-button icon="heroicon-m-chevron-left" wire:click="shiftDate(-1)" label="Previous day" />
                            <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="date" /></x-filament::input.wrapper>
                            <x-filament::icon-button icon="heroicon-m-chevron-right" wire:click="shiftDate(1)" label="Next day" />
                        </div>
                    </label>
                    <label class="text-sm">
                        <span class="block text-xs text-gray-500">Range</span>
                        <x-filament::input.wrapper><x-filament::input.select wire:model.live="range">
                            <option value="15m">Last 15 minutes</option>
                            <option value="hour">Last hour</option>
                            <option value="6h">Last 6 hours</option>
                            <option value="day">Selected day</option>
                            <option value="week">Last 7 days</option>
                            <option value="30d">Last 30 days</option>
                        </x-filament::input.select></x-filament::input.wrapper>
                    </label>
                    <label class="text-sm">
                        <span class="block text-xs text-gray-500">Metric</span>
                        <x-filament::input.wrapper><x-filament::input.select wire:model.live="metric">
                            @foreach ($metrics as $option)
                                <option value="{{ $option->value }}">{{ $option->labelWithUnit() }}</option>
                            @endforeach
                        </x-filament::input.select></x-filament::input.wrapper>
                    </label>
                    <span class="ms-auto text-xs text-gray-500">Updates every {{ config('noise.dashboard.poll_seconds') }} s while visible · not a live meter</span>
                </div>
            </x-filament::section>

            {{-- Headline values --}}
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <x-filament::section compact>
                    <div class="text-xs uppercase tracking-wide text-gray-500">Latest LAeq (1 s)</div>
                    @if ($latest === null)
                        <div class="mt-2 text-sm text-gray-500">No readings received yet.</div>
                    @else
                        <div class="mt-1 text-3xl font-semibold nm-tabular">
                            @if ($latest['laeq'] !== null)
                                {{ number_format($latest['laeq'], 1) }} <span class="text-base font-normal">dBA</span>
                            @elseif ($latest['rms_dbfs'] !== null)
                                {{ number_format($latest['rms_dbfs'], 1) }} <span class="text-base font-normal">dBFS (digital level, not dBA)</span>
                            @else
                                <span class="text-base text-gray-500">Not available</span>
                            @endif
                        </div>
                        <div class="mt-1 text-xs text-gray-600 dark:text-gray-300">
                            Captured {{ \App\Support\LocalTime::displayShort($latest['captured_at'], $tz) }} ({{ \App\Support\LocalTime::age($latest['captured_at']) }})
                            · received {{ \App\Support\LocalTime::age($latest['received_at']) }}
                        </div>
                        <div class="mt-2 flex flex-wrap gap-1">
                            @if ($latest['stale'])
                                <x-filament::badge color="danger" size="sm">Stale (older than {{ $latest['stale_after_seconds'] }} s)</x-filament::badge>
                            @else
                                <x-filament::badge color="success" size="sm">Fresh</x-filament::badge>
                            @endif
                            @if ($latest['calibration_state'])
                                <x-filament::badge :color="$latest['calibration_state']->getColor()" size="sm">{{ $latest['calibration_state']->getLabel() }}</x-filament::badge>
                            @endif
                            @foreach ($latest['flags'] as $flag)
                                <x-filament::badge color="warning" size="sm">{{ $flag->getLabel() }}</x-filament::badge>
                            @endforeach
                        </div>
                    @endif
                </x-filament::section>

                <x-filament::section compact>
                    <div class="text-xs uppercase tracking-wide text-gray-500">Max LAFmax ({{ $date }})</div>
                    @forelse ($summary['today'] as $today)
                        <div class="mt-1 text-3xl font-semibold nm-tabular">
                            {{ $today['lafmax_max'] === null ? '—' : number_format($today['lafmax_max'], 1) }} <span class="text-base font-normal">dBA</span>
                        </div>
                        <div class="text-xs text-gray-600 dark:text-gray-300">
                            Highest fast-weighted A-level over {{ $today['lafmax_valid_minutes'] }} measured min of {{ $today['elapsed_minutes'] < $today['day_minutes'] ? $today['elapsed_minutes'].' elapsed' : $today['day_minutes'] }} min
                        </div>
                        <div class="text-xs text-gray-600 dark:text-gray-300">
                            Day LAeq {{ $today['laeq'] === null ? '—' : number_format($today['laeq'], 1).' dBA' }} over {{ $today['laeq_valid_minutes'] }} measured min
                        </div>
                        @if ($today['calibration_state'])
                            <x-filament::badge class="mt-1" :color="$today['calibration_state']->getColor()" size="sm">{{ $today['calibration_state']->getLabel() }}</x-filament::badge>
                        @endif
                    @empty
                        <div class="mt-2 text-sm text-gray-500">No processed readings for this date.</div>
                    @endforelse
                </x-filament::section>

                <x-filament::section compact>
                    <div class="text-xs uppercase tracking-wide text-gray-500">Events ({{ $date }})</div>
                    <div class="mt-1 grid grid-cols-2 gap-2">
                        <div><div class="text-3xl font-semibold nm-tabular">{{ $totals['all'] }}</div><div class="text-xs text-gray-500">detected</div></div>
                        <div><div class="text-3xl font-semibold text-danger-600 nm-tabular">{{ $totals['confirmed'] }}</div><div class="text-xs text-gray-500">reviewer-confirmed disturbances</div></div>
                    </div>
                    <div class="text-xs text-gray-600 dark:text-gray-300">{{ $totals['unreviewed'] }} unreviewed · {{ $totals['recording_pending'] }} awaiting audio</div>
                </x-filament::section>

                <x-filament::section compact>
                    <div class="text-xs uppercase tracking-wide text-gray-500">Device health — {{ $device->name }}</div>
                    <div class="mt-2 flex flex-wrap gap-1">
                        <x-filament::badge :color="$summary['online'] ? 'success' : 'danger'" size="sm">{{ $summary['online'] ? 'Reachable' : 'Offline' }}</x-filament::badge>
                        @if ($hb)
                            <x-filament::badge :color="$hb['microphone_fault'] ? 'danger' : 'success'" size="sm">Mic: {{ $hb['microphone_state']?->getLabel() }}</x-filament::badge>
                            <x-filament::badge :color="$hb['clock_problem'] ? 'danger' : 'success'" size="sm">Clock: {{ $hb['clock_sync_state']?->getLabel() }}{{ $hb['clock_offset_ms'] !== null ? ' ('.$hb['clock_offset_ms'].' ms)' : '' }}</x-filament::badge>
                            @if ($hb['storage_pressure'])
                                <x-filament::badge color="danger" size="sm">Storage pressure</x-filament::badge>
                            @endif
                        @else
                            <x-filament::badge color="gray" size="sm">No heartbeat yet</x-filament::badge>
                        @endif
                        @if ($summary['config_pending'])
                            <x-filament::badge color="warning" size="sm">Config r{{ $summary['desired_config_revision'] }} pending (applied r{{ $summary['applied_config_revision'] ?? '—' }})</x-filament::badge>
                        @endif
                    </div>
                    <div class="mt-2 text-xs text-gray-600 dark:text-gray-300">
                        Last contact {{ \App\Support\LocalTime::age($summary['last_contact_at']) }}
                        @if ($hb)
                            · backlog {{ number_format($hb['queued_measurement_count'] ?? 0) }} readings, {{ \Illuminate\Support\Number::fileSize($hb['pending_audio_bytes'] ?? 0) }} audio
                            @if ($hb['oldest_pending_capture_at']) (oldest {{ \App\Support\LocalTime::age($hb['oldest_pending_capture_at']) }}) @endif
                            @if ($hb['recent_dropped_intervals']) · {{ $hb['recent_dropped_intervals'] }} dropped intervals @endif
                        @endif
                    </div>
                    @if ($hb && $hb['last_capture_error'])
                        <div class="mt-1 text-xs text-danger-600">Last capture error: {{ \Illuminate\Support\Str::limit($hb['last_capture_error'], 160) }}</div>
                    @endif
                </x-filament::section>
            </div>

            {{-- Time chart --}}
            <x-filament::section>
                <x-slot name="heading">{{ $chart['metric']['axis'] }}</x-slot>
                <x-slot name="description">{{ \App\Support\LocalTime::displayShort($chartRange[0], $tz) }} to {{ \App\Support\LocalTime::displayShort($chartRange[1], $tz) }}</x-slot>
                <x-slot name="afterHeader">
                    <div class="flex flex-wrap gap-3 text-sm">
                        @if (\App\Enums\Metric::from($this->metric)->isEnergy())
                            <label class="flex items-center gap-1"><x-filament::input.checkbox wire:model.live="showMaxima" /> Show LAFmax maxima</label>
                        @endif
                        <label class="flex items-center gap-1"><x-filament::input.checkbox wire:model.live="showTable" /> Data table</label>
                    </div>
                </x-slot>
                <x-noise.time-chart :chart="$chart" :chart-key="$chartKey" :show-table="$showTable" />
            </x-filament::section>

            {{-- Recent events --}}
            <x-filament::section>
                <x-slot name="heading">Recent events</x-slot>
                <x-slot name="description">Detected by the device. "Confirmed disturbance" means a reviewer confirmed a disturbance — not proven ownership or a legal violation.</x-slot>
                @if ($recentEvents->isEmpty())
                    <p class="text-sm text-gray-500">No events reported yet.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="text-xs text-gray-500">
                                <tr><th class="pe-3">Start</th><th class="pe-3">Duration</th><th class="pe-3">Review / source</th><th class="pe-3">Recording</th><th>Quality</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($recentEvents as $event)
                                    <x-noise.event-row :event="$event" :timezone="$tz" />
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
