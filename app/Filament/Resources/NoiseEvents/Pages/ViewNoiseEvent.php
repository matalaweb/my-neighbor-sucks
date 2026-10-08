<?php

namespace App\Filament\Resources\NoiseEvents\Pages;

use App\Enums\DetectionState;
use App\Enums\ExportKind;
use App\Enums\Metric;
use App\Enums\ReviewConfidence;
use App\Enums\ReviewStatus;
use App\Enums\SourceCertainty;
use App\Enums\SourceLabel;
use App\Filament\Resources\EvidenceExports\EvidenceExportResource;
use App\Filament\Resources\NoiseEvents\NoiseEventResource;
use App\Models\EventGroup;
use App\Models\EventRecording;
use App\Models\NoiseEvent;
use App\Services\Events\AnnotateEvent;
use App\Services\Events\SnapshotEventMeasurements;
use App\Services\Exports\RequestEvidenceExport;
use App\Services\Measurements\MeasurementSeries;
use App\Services\Recordings\RecordingPlayback;
use App\Services\Retention\DeleteEvent;
use App\Services\Retention\KeepEvent;
use App\Support\Decibels;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Throwable;

/**
 * Event detail: synchronized chart and audio, timeline, agent vs server
 * summaries, provenance, and append-only annotations. Works without a
 * recording and with partial measurements.
 */
class ViewNoiseEvent extends ViewRecord
{
    protected static string $resource = NoiseEventResource::class;

    protected string $view = 'filament.resources.noise-events.view';

    #[Url]
    public string $metric = 'laeq_db';

    public bool $showTable = false;

    /** @var array<string, string> recording uuid => short-lived URL */
    public array $playbackUrls = [];

    public function getTitle(): string|Htmlable
    {
        return 'Event '.substr($this->record->uuid, 0, 8);
    }

    public function loadPlayback(string $recordingUuid): void
    {
        $recording = $this->recording($recordingUuid);

        try {
            $this->playbackUrls[$recording->uuid] = app(RecordingPlayback::class)->playbackUrl(auth()->user(), $recording);
        } catch (Throwable $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();
        }
    }

    public function downloadOriginal(string $recordingUuid): void
    {
        $recording = $this->recording($recordingUuid);

        try {
            $this->redirect(app(RecordingPlayback::class)->downloadUrl(auth()->user(), $recording));
        } catch (Throwable $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();
        }
    }

    private function recording(string $uuid): EventRecording
    {
        /** @var NoiseEvent $event */
        $event = $this->record;

        // Nested lookup is always constrained to this (already authorized) event.
        return $event->recordings()->where('uuid', $uuid)->firstOrFail();
    }

    protected function getHeaderActions(): array
    {
        /** @var NoiseEvent $event */
        $event = $this->record;
        $user = auth()->user();

        return [
            Action::make('review')
                ->label('Review')
                ->icon('heroicon-o-clipboard-document-check')
                ->visible($user->canAnnotate($event->account_id))
                ->fillForm(fn (): array => [
                    'review_status' => $event->review_status->value,
                    'source_label' => $event->source_label?->value,
                    'source_certainty' => $event->source_certainty?->value,
                    'confidence' => $event->review_confidence?->value,
                ])
                ->modalDescription('Each review is stored as a new append-only annotation. The device payloads are never changed.')
                ->schema([
                    Radio::make('review_status')->options(ReviewStatus::class)->required()
                        ->descriptions([
                            ReviewStatus::ConfirmedDisturbance->value => 'You confirm a disturbance occurred — not who caused it or that a law was broken.',
                            ReviewStatus::HouseholdNoise->value => 'Your own garage door or household activity.',
                        ]),
                    Select::make('source_label')->label('Suspected / observed source')->options(SourceLabel::class),
                    Radio::make('source_certainty')->options(SourceCertainty::class)->inline()
                        ->helperText('Observed = you saw or heard the source directly. Suspected = inferred.')
                        ->requiredWith('source_label'),
                    Radio::make('confidence')->options(ReviewConfidence::class)->inline(),
                    Textarea::make('notes')->label('Observations')->rows(4)->maxLength(10000),
                ])
                ->action(function (array $data) use ($event, $user): void {
                    app(AnnotateEvent::class)->review($event, $user, $data);
                    $this->record->refresh();
                    Notification::make()->title('Review saved.')->success()->send();
                }),
            Action::make('note')
                ->label('Add note')
                ->icon('heroicon-o-chat-bubble-left-ellipsis')
                ->color('gray')
                ->visible($user->canAnnotate($event->account_id))
                ->schema([Textarea::make('notes')->required()->rows(4)->maxLength(10000)])
                ->action(fn (array $data) => app(AnnotateEvent::class)->note($event, $user, $data['notes'])),
            ActionGroup::make([
                Action::make('keep')
                    ->label(fn (): string => $event->keep ? 'Remove keep flag' : 'Keep (preserve evidence)')
                    ->icon('heroicon-o-lock-closed')
                    ->visible($user->canManage($event->account_id))
                    ->requiresConfirmation()
                    ->modalDescription(fn (): string => $event->keep
                        ? 'Ordinary retention will apply again to this event, its snapshots, and its recordings.'
                        : 'Keeping preserves snapshots, recordings, provenance, and integrity metadata until removed. It cannot recover audio that was already purged.')
                    ->schema([TextInput::make('reason')->maxLength(500)])
                    ->action(function (array $data) use ($event, $user): void {
                        $warnings = app(KeepEvent::class)->set($event, ! $event->keep, $user, $data['reason'] ?? null);
                        $this->record->refresh();
                        Notification::make()->title($this->record->keep ? 'Event kept.' : 'Keep flag removed.')->body(implode(' ', $warnings) ?: null)->{$warnings ? 'warning' : 'success'}()->send();
                    }),
                Action::make('incomplete')
                    ->label('Mark incomplete')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->visible($user->canAnnotate($event->account_id) && $event->detection_state === DetectionState::Open && $event->marked_incomplete_at === null)
                    ->requiresConfirmation()
                    ->modalDescription('Records that the device never finalized this event. No end time is invented and the source record is unchanged; a later final revision can still close it.')
                    ->schema([Textarea::make('notes')->rows(2)])
                    ->action(fn (array $data) => app(AnnotateEvent::class)->markIncomplete($event, $user, $data['notes'] ?? null)),
                Action::make('group')
                    ->label('Add to group')
                    ->icon('heroicon-o-rectangle-group')
                    ->visible($user->canAnnotate($event->account_id))
                    ->schema([TextInput::make('name')->label('Group name')->required()->datalist(fn () => EventGroup::query()->where('account_id', $event->account_id)->pluck('name')->all())])
                    ->action(fn (array $data) => app(AnnotateEvent::class)->group($event, $user, $data['name'])),
                Action::make('export')
                    ->label('Export this event')
                    ->icon('heroicon-o-document-arrow-down')
                    ->visible($user->canExport($event->account_id))
                    ->schema([Select::make('kind')->options(ExportKind::class)->default(ExportKind::EvidenceBundle->value)->required()])
                    ->action(function (array $data) use ($event, $user): void {
                        try {
                            app(RequestEvidenceExport::class)->handle($user, $event->property, ($data['kind'] instanceof ExportKind ? $data['kind'] : ExportKind::from($data['kind'])), ['type' => 'events', 'event_uuids' => [$event->uuid]]);
                        } catch (ValidationException $exception) {
                            Notification::make()->title(collect($exception->errors())->flatten()->first())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Export queued.')->success()->actions([Action::make('open')->label('Open reports')->url(EvidenceExportResource::getUrl())])->send();
                    }),
                Action::make('refreshSnapshot')
                    ->label('Refresh measurement snapshot')
                    ->icon('heroicon-o-arrow-path')
                    ->visible($user->canAnnotate($event->account_id))
                    ->action(fn () => app(SnapshotEventMeasurements::class)->snapshot($event)),
                Action::make('delete')
                    ->label('Delete permanently')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible($user->canManage($event->account_id))
                    ->modalDescription('Permanently deletes this event, its revisions, snapshots, annotations, and recordings. Audit tombstones keep identities and hashes. This cannot be undone; kept events must be un-kept first.')
                    ->schema([TextInput::make('confirmation')->label('Type the first 8 characters of the event ID ('.substr($event->uuid, 0, 8).')')->required()])
                    ->action(function (array $data) use ($event, $user): void {
                        try {
                            app(DeleteEvent::class)->handle($event, $user, $data['confirmation']);
                        } catch (ValidationException $exception) {
                            Notification::make()->title(collect($exception->errors())->flatten()->first())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Event deleted.')->success()->send();
                        $this->redirect(NoiseEventResource::getUrl());
                    }),
            ])->label('More')->button()->color('gray'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        /** @var NoiseEvent $event */
        $event = $this->record->loadMissing(['device.property', 'stream.deployment', 'stream.profile', 'stream.calibration', 'revisions', 'recordings.uploadAttempts', 'annotations.author', 'snapshots', 'groups']);
        $metric = Metric::tryFrom($this->metric) ?? Metric::LAeq;
        $timezone = $event->property->timezone;
        $windowStart = $event->started_at->subSeconds((int) config('noise.events.snapshot_pre_seconds'));
        $windowEnd = ($event->ended_at ?? $event->device->latest_capture_at ?? $event->started_at->addMinute())->addSeconds((int) config('noise.events.snapshot_post_seconds'));

        if (! $windowEnd->greaterThan($windowStart)) {
            $windowEnd = $windowStart->addMinute();
        }

        $series = app(MeasurementSeries::class)->forDevice($event->device, $event->channel, $windowStart, $windowEnd, $metric);

        $chart = [
            'timezone' => $timezone,
            'metric' => $series['metric'],
            'resolution' => $series['resolution'],
            'series' => $series['series'],
            'showMaxima' => $metric->isEnergy(),
            'range' => ['from' => $windowStart->getTimestampMs(), 'to' => $windowEnd->getTimestampMs()],
            'events' => [['start' => $event->started_at->getTimestampMs(), 'end' => $event->ended_at?->getTimestampMs() ?? $windowEnd->getTimestampMs(), 'highlight' => true]],
        ];

        $snapshot = $event->snapshots->sortByDesc('version')->first();
        $bands = $this->eventAverageBands($snapshot?->decodedRows() ?? [], $event);

        return [
            'event' => $event,
            'timezone' => $timezone,
            'chart' => $chart,
            'chartKey' => md5(json_encode($chart)),
            'metrics' => Metric::cases(),
            'snapshot' => $snapshot,
            'bands' => $bands,
            'timeline' => $this->timeline($event),
        ];
    }

    /**
     * Energy-averaged band levels across the detection window (event average,
     * labelled as such). Only compatible bands (same centre and weighting) combine.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{center_hz: float, weighting: string, level_db: float|null}>
     */
    private function eventAverageBands(array $rows, NoiseEvent $event): array
    {
        $sums = [];

        foreach ($rows as $row) {
            $t = CarbonImmutable::parse($row['captured_at']);

            if ($t->lessThan($event->started_at) || ($event->ended_at !== null && $t->greaterThanOrEqualTo($event->ended_at))) {
                continue;
            }

            foreach ($row['bands'] ?? [] as $band) {
                if ($band['level_db'] === null) {
                    continue;
                }

                $key = $band['center_hz'].'|'.$band['weighting'];
                $sums[$key]['center_hz'] = $band['center_hz'];
                $sums[$key]['weighting'] = $band['weighting'];
                $sums[$key]['energy'] = ($sums[$key]['energy'] ?? 0) + Decibels::energy($band['level_db'], $row['duration_ms']);
                $sums[$key]['ms'] = ($sums[$key]['ms'] ?? 0) + $row['duration_ms'];
            }
        }

        $bands = array_map(fn (array $sum): array => [
            'center_hz' => $sum['center_hz'],
            'weighting' => $sum['weighting'],
            'level_db' => Decibels::round(Decibels::leq($sum['energy'], $sum['ms'])),
        ], array_values($sums));

        usort($bands, fn ($a, $b) => $a['center_hz'] <=> $b['center_hz']);

        return $bands;
    }

    /**
     * @return list<array{at: CarbonImmutable, label: string, detail: string|null}>
     */
    private function timeline(NoiseEvent $event): array
    {
        $items = [];

        foreach ($event->revisions as $revision) {
            $items[] = ['at' => $revision->received_at, 'label' => 'Device revision '.$revision->revision.' received ('.$revision->payload['detection_state'].')', 'detail' => $revision->applied_to_projection ? null : 'Older revision stored for history; did not change the current state.'];
        }

        foreach ($event->recordings as $recording) {
            $items[] = ['at' => $recording->declared_at, 'label' => 'Recording segment '.$recording->segment_number.' declared', 'detail' => null];

            if ($recording->verified_at) {
                $items[] = ['at' => $recording->verified_at, 'label' => 'Recording segment '.$recording->segment_number.' verified', 'detail' => 'SHA-256 '.$recording->verified_sha256];
            }

            if ($recording->purged_at) {
                $items[] = ['at' => $recording->purged_at, 'label' => 'Recording segment '.$recording->segment_number.' purged', 'detail' => $recording->purge_reason];
            }
        }

        foreach ($event->annotations as $annotation) {
            $items[] = ['at' => $annotation->created_at, 'label' => ucfirst(str_replace('_', ' ', $annotation->kind)).' by '.($annotation->author?->name ?? 'unknown'), 'detail' => null];
        }

        foreach ($event->snapshots as $snapshot) {
            if ($snapshot->frozen_at) {
                $items[] = ['at' => $snapshot->frozen_at, 'label' => 'Measurement snapshot v'.$snapshot->version.' frozen ('.$snapshot->completeness->getLabel().')', 'detail' => $snapshot->limitations];
            }
        }

        usort($items, fn ($a, $b) => $a['at'] <=> $b['at']);

        return $items;
    }
}
