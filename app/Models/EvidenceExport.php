<?php

namespace App\Models;

use App\Enums\ExportKind;
use App\Enums\ExportStatus;
use App\Models\Concerns\BelongsToAccount;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvidenceExport extends Model
{
    use BelongsToAccount, HasPublicUuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'kind' => ExportKind::class,
            'status' => ExportStatus::class,
            'scope' => 'array',
            'options' => 'array',
            'selection' => 'array',
            'manifest' => 'array',
            'limits_overridden' => 'boolean',
            'requested_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'object_deleted_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isDownloadable(): bool
    {
        return $this->status === ExportStatus::Ready
            && $this->object_key !== null
            && $this->object_deleted_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
