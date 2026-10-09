<div wire:poll.visible.{{ $pollSeconds }}s class="mx-auto flex max-w-6xl flex-col gap-6 px-4 py-8 sm:px-6 sm:py-12 lg:px-8">
    @php
        $latest = $dashboard['latest'];
        $today = $dashboard['today'];
        $calibration = $dashboard['calibration'];
        $maxEventPeak = collect($dashboard['events'])->max('peak') ?: 1;
        $card = 'rounded-3xl border border-slate-200/80 bg-white/80 shadow-sm shadow-slate-900/5 backdrop-blur dark:border-white/10 dark:bg-white/[0.04] dark:shadow-none';
    @endphp

    {{-- Hero --}}
    <header class="{{ $card }} relative overflow-hidden p-6 sm:p-8">
        <div class="flex flex-col gap-8 lg:flex-row lg:items-end lg:justify-between">
            <div class="flex flex-col gap-4">
                <div class="flex flex-wrap items-center gap-2 text-xs font-medium">
                    @if ($dashboard['online'])
                        <span class="inline-flex items-center gap-2 rounded-full bg-emerald-500/10 px-3 py-1 text-emerald-700 ring-1 ring-emerald-500/20 ring-inset dark:text-emerald-300">
                            <span class="relative flex size-2">
                                <span class="absolute inline-flex size-full animate-ping rounded-full bg-emerald-500 opacity-60"></span>
                                <span class="relative inline-flex size-2 rounded-full bg-emerald-500"></span>
                            </span>
                            Live
                        </span>
                    @else
                        <span class="inline-flex items-center gap-2 rounded-full bg-amber-500/10 px-3 py-1 text-amber-700 ring-1 ring-amber-500/20 ring-inset dark:text-amber-300">
                            <span class="size-2 rounded-full bg-amber-500"></span>
                            Offline · last contact {{ $dashboard['last_contact'] }}
                        </span>
                    @endif
                    <span class="rounded-full bg-slate-900/5 px-3 py-1 text-slate-600 dark:bg-white/10 dark:text-slate-300">{{ $dashboard['timezone'] }} ({{ $dashboard['timezone_abbreviation'] }})</span>
                </div>
                <div>
                    <p class="text-sm font-medium tracking-wide text-teal-700 uppercase dark:text-teal-300">Noise monitor</p>
                    <h1 class="mt-1 text-3xl font-semibold tracking-tight text-balance sm:text-4xl">{{ $dashboard['title'] }}</h1>
                    <p class="mt-2 max-w-xl text-sm text-slate-600 dark:text-slate-400">Sound levels measured continuously at this location and shared read-only. Updates every {{ $pollSeconds }} seconds while this page is open.</p>
                </div>
            </div>

            <div class="w-full lg:max-w-sm" data-testid="current-level">
                <p class="text-sm text-slate-500 dark:text-slate-400">Current level (LAeq)</p>
                <div class="mt-1 flex items-baseline gap-2">
                    <span class="text-6xl font-semibold tracking-tight tabular-nums sm:text-7xl">{{ ($latest['laeq'] ?? null) === null ? '—' : number_format($latest['laeq'], 1) }}</span>
                    <span class="text-xl text-slate-500 dark:text-slate-400">dBA</span>
                </div>
                <p class="mt-1 text-sm {{ $latest !== null && $latest['stale'] ? 'text-amber-600 dark:text-amber-400' : 'text-slate-500 dark:text-slate-400' }}">
                    @if ($latest === null)
                        No readings yet
                    @else
                        {{ $latest['descriptor'] }} · measured {{ $latest['age'] }}{{ $latest['stale'] ? ' (not current)' : '' }}
                    @endif
                </p>
                <div class="mt-4">
                    <div class="relative h-2 rounded-full bg-gradient-to-r from-emerald-400 via-amber-400 to-rose-500">
                        @if (($latest['scale_percent'] ?? null) !== null)
                            <span class="absolute top-1/2 size-4 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white bg-slate-900 shadow transition-all duration-700 dark:border-slate-950 dark:bg-white" style="left: {{ $latest['scale_percent'] }}%"></span>
                        @endif
                    </div>
                    <div class="mt-2 flex justify-between text-[11px] text-slate-500 dark:text-slate-400">
                        <span>30 · quiet</span><span>55 · talking</span><span>75 · traffic</span><span>100</span>
                    </div>
                </div>
            </div>
        </div>
    </header>

    {{-- Today --}}
    <section aria-label="Today" class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <div class="{{ $card }} p-5">
            <p class="text-sm text-slate-500 dark:text-slate-400">Today's loudest moment</p>
            <p class="mt-2 text-3xl font-semibold tabular-nums">{{ $today['peak'] === null ? '—' : number_format($today['peak'], 1) }}<span class="ml-1 text-base font-normal text-slate-500">dBA</span></p>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Max LAFmax since midnight</p>
        </div>
        <div class="{{ $card }} p-5">
            <p class="text-sm text-slate-500 dark:text-slate-400">Today's average</p>
            <p class="mt-2 text-3xl font-semibold tabular-nums">{{ $today['leq'] === null ? '—' : number_format($today['leq'], 1) }}<span class="ml-1 text-base font-normal text-slate-500">dBA</span></p>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Energy-average LAeq</p>
        </div>
        <div class="{{ $card }} p-5">
            <p class="text-sm text-slate-500 dark:text-slate-400">Events today</p>
            <p class="mt-2 text-3xl font-semibold tabular-nums" data-testid="events-today">{{ $dashboard['events_today']['all'] }}</p>
            <p class="mt-1 text-xs">
                <span class="font-medium text-rose-600 dark:text-rose-400">{{ $dashboard['events_today']['confirmed'] }} confirmed</span>
                <span class="text-slate-500 dark:text-slate-400">by a reviewer</span>
            </p>
        </div>
        <div class="{{ $card }} flex items-center justify-between gap-3 p-5">
            <div>
                <p class="text-sm text-slate-500 dark:text-slate-400">Data coverage</p>
                <p class="mt-2 text-3xl font-semibold tabular-nums">{{ $today['coverage'] === null ? '—' : $today['coverage'].'%' }}</p>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">of today measured</p>
            </div>
            <svg viewBox="0 0 36 36" class="size-14 shrink-0 -rotate-90" aria-hidden="true">
                <circle cx="18" cy="18" r="15.9155" fill="none" stroke-width="3.5" class="stroke-slate-200 dark:stroke-white/10" />
                <circle cx="18" cy="18" r="15.9155" fill="none" stroke-width="3.5" stroke-linecap="round" class="stroke-teal-500" stroke-dasharray="{{ $today['coverage'] ?? 0 }} 100" />
            </svg>
        </div>
    </section>

    {{-- Chart --}}
    <section class="{{ $card }} p-5 sm:p-6" aria-labelledby="chart-heading">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 id="chart-heading" class="text-lg font-semibold">Sound level over time</h2>
                <p class="text-sm text-slate-500 dark:text-slate-400">LAeq with the loudest instant in each bucket dashed. Shaded bands are detected events.</p>
            </div>
            <div role="group" aria-label="Time range" class="inline-flex self-start rounded-full bg-slate-900/5 p-1 dark:bg-white/10">
                @foreach ($ranges as $key => $option)
                    <button type="button" wire:click="setRange('{{ $key }}')" aria-pressed="{{ $dashboard['range'] === $key ? 'true' : 'false' }}"
                        class="rounded-full px-3.5 py-1.5 text-sm font-medium transition {{ $dashboard['range'] === $key ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-800 dark:text-white' : 'text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white' }}">
                        {{ $key }}
                    </button>
                @endforeach
            </div>
        </div>

        <div class="relative mt-5">
            <div wire:loading.flex wire:target="setRange" class="absolute inset-0 z-10 items-center justify-center rounded-2xl bg-white/60 backdrop-blur-sm dark:bg-slate-950/60">
                <span class="size-6 animate-spin rounded-full border-2 border-teal-500 border-t-transparent"></span>
            </div>
            @if (collect($dashboard['chart']['series'])->isEmpty())
                <div class="flex h-80 items-center justify-center rounded-2xl border border-dashed border-slate-300 text-sm text-slate-500 dark:border-white/10">
                    No measurements in the last {{ $ranges[$dashboard['range']]['label'] }}.
                </div>
            @else
                <div wire:key="public-chart-{{ $chartKey }}" wire:ignore x-data="noiseChart('time', { ...@js($dashboard['chart']), theme: window.nmPublicChartTheme() })" class="relative h-80 sm:h-96">
                    <canvas x-ref="canvas" role="img" aria-label="LAeq over the last {{ $ranges[$dashboard['range']]['label'] }}"></canvas>
                </div>
            @endif
        </div>

        <div class="mt-4 flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-slate-500 dark:text-slate-400">
            <span class="inline-flex items-center gap-1.5"><span class="h-3 w-4 rounded-sm bg-rose-500/25"></span> Confirmed disturbance</span>
            <span class="inline-flex items-center gap-1.5"><span class="h-3 w-4 rounded-sm bg-amber-500/25"></span> Other event</span>
            <span>Resolution: {{ $dashboard['chart']['resolution']['label'] }}</span>
            <span>Gaps are missing data, not silence.</span>
        </div>
    </section>

    {{-- Events --}}
    <section class="{{ $card }} p-5 sm:p-6" aria-labelledby="events-heading">
        <div class="flex items-baseline justify-between gap-4">
            <h2 id="events-heading" class="text-lg font-semibold">Recent events</h2>
            <span class="text-xs text-slate-500 dark:text-slate-400">Latest {{ count($dashboard['events']) }}</span>
        </div>

        @forelse (collect($dashboard['events'])->groupBy('day') as $day => $events)
            <h3 class="mt-6 text-xs font-semibold tracking-wide text-slate-500 uppercase dark:text-slate-400">{{ $day }}</h3>
            <ol class="mt-3 flex flex-col divide-y divide-slate-200/80 dark:divide-white/5">
                @foreach ($events as $event)
                    <li class="grid grid-cols-[5.5rem_1fr] items-center gap-x-4 gap-y-2 py-3 sm:grid-cols-[7rem_1fr_auto]">
                        <div>
                            <p class="text-sm font-medium tabular-nums">{{ $event['time'] }}</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">{{ $event['duration'] }}</p>
                        </div>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-baseline gap-x-2">
                                <span class="text-sm font-semibold whitespace-nowrap tabular-nums">{{ $event['peak'] === null ? '—' : number_format($event['peak'], 1).' dBA' }}</span>
                                <span class="text-xs text-slate-500 dark:text-slate-400">peak{{ $event['above_baseline'] === null ? '' : ' · +'.number_format($event['above_baseline'], 1).' dB over background' }}</span>
                            </div>
                            <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-200/70 dark:bg-white/10">
                                <div class="h-full rounded-full {{ $event['status'] === 'confirmed_disturbance' ? 'bg-rose-500' : 'bg-gradient-to-r from-teal-400 to-sky-500' }}" style="width: {{ $event['peak'] === null ? 0 : max(4, round(100 * $event['peak'] / $maxEventPeak)) }}%"></div>
                            </div>
                        </div>
                        <span @class([
                            'col-start-2 justify-self-start rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset sm:col-start-auto sm:justify-self-end',
                            'bg-rose-500/10 text-rose-700 ring-rose-500/20 dark:text-rose-300' => $event['status'] === 'confirmed_disturbance',
                            'bg-amber-500/10 text-amber-700 ring-amber-500/20 dark:text-amber-300' => $event['status'] === 'uncertain',
                            'bg-slate-500/10 text-slate-600 ring-slate-500/20 dark:text-slate-300' => ! in_array($event['status'], ['confirmed_disturbance', 'uncertain'], true),
                        ])>{{ $event['status_label'] }}</span>
                    </li>
                @endforeach
            </ol>
        @empty
            <p class="mt-6 rounded-2xl border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500 dark:border-white/10">No events detected yet.</p>
        @endforelse
    </section>

    {{-- Methodology --}}
    <footer class="flex flex-col gap-3 px-1 pb-4 text-xs leading-relaxed text-slate-500 dark:text-slate-400">
        <p>
            <span class="font-medium text-slate-700 dark:text-slate-300">About these measurements.</span>
            This is a DIY monitoring system. Its readings do not come from a certified or regulatory sound level meter and do not by themselves establish a violation. Events are detected automatically; a "Confirmed disturbance" means a reviewer confirmed that a disturbance happened, nothing more.
            @if ($calibration !== null)
                Calibration: <span class="font-medium text-slate-700 dark:text-slate-300">{{ $calibration['label'] }}</span>. {{ $calibration['description'] }}
            @endif
        </p>
        <p>Updated {{ $dashboard['generated_at'] }} · Quality policy {{ $dashboard['quality_policy'] }} · Powered by Noise Monitor</p>
    </footer>
</div>
