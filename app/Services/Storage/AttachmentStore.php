<?php

namespace App\Services\Storage;

use App\Models\Attachment;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Stores private supporting files (frequency-response files, certificates,
 * placement photos) with a SHA-256 of the exact stored bytes. Uploading a
 * frequency-response file never changes a calibration state by itself.
 */
class AttachmentStore
{
    public function __construct(
        private readonly EvidenceStorage $storage,
        private readonly AuditLogger $audit,
    ) {}

    public function store(Model $attachable, string $localPath, string $originalName, ?string $mimeType, string $purpose, ?User $user): Attachment
    {
        $uuid = (string) Str::uuid7();
        $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $originalName) ?: 'file';
        $key = sprintf('attachments/%s/%s/%s-%s', $attachable->account->uuid, Str::kebab(class_basename($attachable)), $uuid, $safeName);
        $sha256 = hash_file('sha256', $localPath);
        $bytes = filesize($localPath);

        $stream = fopen($localPath, 'rb');

        try {
            $this->storage->disk()->writeStream($key, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $attachment = Attachment::query()->create([
            'uuid' => $uuid,
            'account_id' => $attachable->getAttribute('account_id'),
            'attachable_type' => $attachable->getMorphClass(),
            'attachable_id' => $attachable->getKey(),
            'purpose' => $purpose,
            'disk' => $this->storage->diskName(),
            'object_key' => $key,
            'original_filename' => $originalName,
            'mime_type' => $mimeType,
            'byte_size' => $bytes,
            'sha256' => $sha256,
            'uploaded_by' => $user?->id,
        ]);

        $this->audit->record('attachment.stored', $attachment, ['purpose' => $purpose, 'sha256' => $sha256], user: $user);

        return $attachment;
    }
}
