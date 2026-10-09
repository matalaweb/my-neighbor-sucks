<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\IsImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Private supporting file with recorded checksum.
 */
class Attachment extends Model
{
    use BelongsToAccount, HasPublicUuid, IsImmutable;

    public const UPDATED_AT = null;

    /** The UUID is chosen before storage so the object key can include it (see AttachmentStore). */
    protected $fillable = [
        'uuid', 'account_id', 'attachable_type', 'attachable_id', 'purpose', 'disk', 'object_key',
        'original_filename', 'mime_type', 'byte_size', 'sha256', 'uploaded_by',
    ];

    /** @return MorphTo<Model, $this> */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }
}
