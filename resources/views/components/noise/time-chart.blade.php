@props(['chart', 'chartKey', 'height' => '20rem', 'showTable' => false, 'id' => 'chart'])
@php
    $points = collect($chart['series'])->flatMap(fn ($s) => collect($s['points'])->reject(fn ($p) => $p['gap'] ?? false)->map(fn ($p) => [...$p, 'series' => $s['label']]));
@endphp
<div {{ $attributes }}>
    <div class="mb-2 flex flex-wrap items-center gap-2 text-xs text-gray-600 dark:text-gray-300">
        <x-filament::badge size="sm" color="gray">Resolution: {{ $chart['resolution']['label'] }}{{ $chart['resolution']['source'] === 'raw' ? ' (raw readings)' : ' ('.$chart['resolution']['source'].' rollups)' }}</x-filament::badge>
        @if ($chart['resolution']['coarsened'] ?? false)
            <x-filament::badge size="sm" color="warning">Coarsened to stay within 2,000 points</x-filament::badge>
        @endif
        @foreach ($chart['series'] as $series)
            <x-filament::badge size="sm" :color="match ($series['calibration_state']) { 'calibrated' => 'success', 'estimated' => 'warning', default => 'gray' }">
                {{ $series['calibration_label'] }}
            </x-filament::badge>
        @endforeach
        <span>Gaps are missing data (unknown), not silence. Shaded bands are events.</span>
    </div>

    @if (collect($chart['series'])->isEmpty())
        <div class="flex items-center justify-center rounded-lg border border-dashed border-gray-300 p-8 text-sm text-gray-500 dark:border-white/10" style="height: {{ $height }}">
            No measurements in this range.
        </div>
    @else
        <div wire:key="{{ $id }}-{{ $chartKey }}" wire:ignore x-data="noiseChart('time', @js($chart))" x-on:noise-cursor.window="setCursor($event.detail)" style="height: {{ $height }}" class="relative">
            <canvas x-ref="canvas" role="img" aria-label="{{ $chart['metric']['axis'] }} over time; use the data table for exact values"></canvas>
        </div>
    @endif

    @if ($showTable && $points->isNotEmpty())
        <div class="mt-4 max-h-96 overflow-auto rounded-lg border border-gray-200 dark:border-white/10">
            <table class="w-full text-left text-xs nm-tabular">
                <caption class="sr-only">Chart data table</caption>
                <thead class="sticky top-0 bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <th scope="col" class="px-2 py-1">Bucket start ({{ $chart['timezone'] }})</th>
                        <th scope="col" class="px-2 py-1">Series</th>
                        <th scope="col" class="px-2 py-1">{{ $chart['metric']['label'] }}{{ $chart['metric']['aggregation'] === 'energy' && $chart['resolution']['seconds'] > 1 ? ' (energy avg)' : '' }} ({{ $chart['metric']['unit'] }})</th>
                        @if ($chart['metric']['aggregation'] === 'energy')
                            <th scope="col" class="px-2 py-1">Max LAFmax (dBA)</th>
                        @endif
                        <th scope="col" class="px-2 py-1">Valid / expected (s)</th>
                        <th scope="col" class="px-2 py-1">Excluded (s)</th>
                        <th scope="col" class="px-2 py-1">Flags</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($points->take(2000) as $point)
                        <tr class="border-t border-gray-100 dark:border-white/5">
                            <td class="px-2 py-1">{{ \App\Support\LocalTime::display(\Carbon\CarbonImmutable::createFromTimestampMs($point['t']), $chart['timezone']) }}</td>
                            <td class="px-2 py-1">{{ $point['series'] }}</td>
                            <td class="px-2 py-1">{{ $point['v'] === null ? '—' : number_format($point['v'], 1) }}</td>
                            @if ($chart['metric']['aggregation'] === 'energy')
                                <td class="px-2 py-1">{{ ($point['m'] ?? null) === null ? '—' : number_format($point['m'], 1) }}</td>
                            @endif
                            <td class="px-2 py-1">{{ intdiv($point['valid_ms'] ?? 0, 1000) }} / {{ intdiv($point['expected_ms'] ?? 0, 1000) }}</td>
                            <td class="px-2 py-1">{{ intdiv($point['excluded_ms'] ?? 0, 1000) }}</td>
                            <td class="px-2 py-1">{{ implode(', ', $point['flags'] ?? []) ?: '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
