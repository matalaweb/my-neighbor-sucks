@props(['event', 'timezone'])
@php
    $duration = $event->durationMs();
    $recording = $event->recording_state;
    $recordingColor = match ($recording?->value) {
        'verified' => 'success',
        'failed', 'missing' => 'danger',
        'purged' => 'gray',
        null => 'gray',
        default => 'warning',
    };
    $reviewColor = match ($event->review_status->value) {
        'confirmed_disturbance' => 'danger',
        'household_noise' => 'info',
        'dismissed' => 'gray',
        'uncertain' => 'warning',
        default => 'gray',
    };
@endphp
<tr class="border-t border-gray-100 dark:border-white/5">
    <td class="py-2 pe-3 align-top">
        <a class="text-primary-600 hover:underline dark:text-primary-400" href="{{ \App\Filament\Resources\NoiseEvents\NoiseEventResource::getUrl('view', ['record' => $event]) }}">
            {{ \App\Support\LocalTime::displayShort($event->started_at, $timezone) }}
        </a>
        <div class="text-xs text-gray-500">{{ $event->device?->name }}</div>
    </td>
    <td class="py-2 pe-3 align-top nm-tabular">
        @if ($event->isOpen())
            <x-filament::badge color="warning" size="sm">Open</x-filament::badge>
        @else
            {{ number_format($duration / 1000, 1) }} s
        @endif
    </td>
    <td class="py-2 pe-3 align-top">
        <x-filament::badge :color="$reviewColor" size="sm">{{ $event->review_status->getLabel() }}</x-filament::badge>
        @if ($event->source_label)
            <div class="mt-1 text-xs text-gray-500">{{ $event->source_certainty?->getLabel() }} {{ $event->source_label->getLabel() }}</div>
        @endif
    </td>
    <td class="py-2 pe-3 align-top">
        <x-filament::badge :color="$recordingColor" size="sm">{{ $recording?->getLabel() ?? 'No recording expected' }}</x-filament::badge>
    </td>
    <td class="py-2 align-top text-xs">
        @forelse ($event->qualityFlagList() as $flag)
            <x-filament::badge color="warning" size="sm">{{ $flag->getLabel() }}</x-filament::badge>
        @empty
            <span class="text-gray-400">—</span>
        @endforelse
    </td>
</tr>
