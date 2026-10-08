<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\Device;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Writes structured audit entries. Never pass secrets, signed URLs, audio
 * content, or raw payloads as metadata.
 */
class AuditLogger
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $action,
        ?Model $entity = null,
        array $metadata = [],
        ?int $accountId = null,
        ?User $user = null,
        ?Device $device = null,
    ): AuditLog {
        $user ??= Auth::guard('web')->user();
        $request = app()->runningInConsole() ? null : request();

        return AuditLog::query()->create([
            'account_id' => $accountId ?? $entity?->getAttribute('account_id') ?? $device?->account_id,
            'user_id' => $user?->getKey(),
            'device_id' => $device?->getKey(),
            'action' => $action,
            'entity_type' => $entity ? class_basename($entity) : null,
            'entity_id' => $entity?->getKey(),
            'entity_uuid' => $entity?->getAttribute('uuid'),
            'metadata' => $metadata === [] ? null : $metadata,
            'request_id' => $request?->attributes->get('request_id'),
            'ip_address' => $request?->ip(),
            'created_at' => CarbonImmutable::now(),
        ]);
    }
}
