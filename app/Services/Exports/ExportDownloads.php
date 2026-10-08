<?php

namespace App\Services\Exports;

use App\Models\EvidenceExport;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Storage\EvidenceStorage;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * Issues short-lived, authorized download URLs for finished exports. URLs
 * are never stored or embedded in reports.
 */
class ExportDownloads
{
    public function __construct(
        private readonly EvidenceStorage $storage,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws AuthorizationException
     */
    public function issueUrl(User $user, EvidenceExport $export): string
    {
        Gate::forUser($user)->authorize('download', $export);

        if (! $export->isDownloadable()) {
            throw new RuntimeException('This export is not available for download (status: '.$export->status->value.').');
        }

        $url = $this->storage->browserUrl(
            $export->object_key,
            CarbonImmutable::now()->addMinutes((int) config('noise.exports.download_url_ttl_minutes')),
            $export->file_name,
        );

        $this->audit->record('export.downloaded', $export, ['file_name' => $export->file_name, 'sha256' => $export->export_sha256], user: $user);

        return $url;
    }
}
