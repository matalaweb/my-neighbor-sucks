@props(['metrics' => [\App\Enums\Metric::LAeq, \App\Enums\Metric::LAFmax, \App\Enums\Metric::LCeq, \App\Enums\Metric::LowFrequencyLeq]])
{{-- Plain-language explainer for owners and visitors who don't work in acoustics. --}}
<details {{ $attributes->class('group text-sm') }}>
    <summary class="cursor-pointer font-medium select-none">What do these numbers mean?</summary>
    <div class="mt-3 grid gap-6 md:grid-cols-2">
        <div>
            <h3 class="font-semibold">The readings</h3>
            <dl class="mt-2 space-y-3">
                @foreach ($metrics as $metric)
                    <div>
                        <dt class="font-medium">{{ $metric->plainName() }} <span class="font-normal text-gray-500 dark:text-gray-400">· {{ $metric->getLabel() }}, {{ $metric->unit() }}</span></dt>
                        <dd class="text-gray-600 dark:text-gray-300">{{ $metric->description() }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
        <div>
            <h3 class="font-semibold">How loud is that?</h3>
            <p class="mt-2 text-gray-600 dark:text-gray-300">Typical levels in dBA (decibels, weighted like human hearing):</p>
            <table class="mt-2 w-full text-left">
                <tbody class="text-gray-600 dark:text-gray-300">
                    <tr><td class="w-14 pe-3 font-medium tabular-nums">30</td><td>Quiet bedroom at night, rustling leaves</td></tr>
                    <tr><td class="pe-3 font-medium tabular-nums">40</td><td>Quiet suburban street, library</td></tr>
                    <tr><td class="pe-3 font-medium tabular-nums">50</td><td>Light traffic in the distance, moderate rain</td></tr>
                    <tr><td class="pe-3 font-medium tabular-nums">60</td><td>Normal conversation a few feet away</td></tr>
                    <tr><td class="pe-3 font-medium tabular-nums">70</td><td>Vacuum cleaner, busy road nearby</td></tr>
                    <tr><td class="pe-3 font-medium tabular-nums">80</td><td>Barking dog close by, loud music in the next room</td></tr>
                    <tr><td class="pe-3 font-medium tabular-nums">90+</td><td>Lawn mower, leaf blower, motorcycle up close</td></tr>
                </tbody>
            </table>
            <h3 class="mt-4 font-semibold">Rules of thumb</h3>
            <ul class="mt-2 list-disc space-y-1 ps-5 text-gray-600 dark:text-gray-300">
                <li>Decibels aren't linear: <strong>+10 dB sounds about twice as loud</strong>.</li>
                <li>A change of 3 dB is just noticeable; 5 dB is clearly noticeable.</li>
                <li>The loudest moment is always higher than the average. Short spikes are normal; a raised average means sustained noise.</li>
                <li>"Over background" compares a sound with the usual quiet level at this spot, so it means the same thing day or night.</li>
            </ul>
        </div>
    </div>
</details>
