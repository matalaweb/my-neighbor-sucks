<?php

namespace App\Services\Devices;

use App\Enums\DeviceStatus;
use App\Models\Device;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Issues, regenerates, and revokes the secret link to a device's public,
 * read-only dashboard. Only a SHA-256 digest is used for lookup; the token is
 * kept encrypted so owners can copy the link again. Every lookup re-reads the
 * device, so revoking or regenerating takes effect immediately.
 */
class DeviceSharingService
{
    public const TOKEN_PREFIX = 'nms_';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Share the device publicly, or regenerate its link if it is already shared.
     */
    public function share(Device $device, User $user): string
    {
        if ($device->isArchived()) {
            throw new RuntimeException('Archived devices cannot be shared.');
        }

        $regenerating = $device->share_token_hash !== null;
        $token = self::TOKEN_PREFIX.Str::random(48);

        $device->forceFill([
            'share_token_hash' => self::hashToken($token),
            'share_token' => $token,
            'shared_at' => CarbonImmutable::now(),
        ])->save();

        $this->audit->record($regenerating ? 'device.sharing.regenerated' : 'device.sharing.enabled', $device, user: $user);

        return $token;
    }

    public function stop(Device $device, User $user): void
    {
        if ($device->share_token_hash === null) {
            return;
        }

        $device->forceFill(['share_token_hash' => null, 'share_token' => null, 'shared_at' => null])->save();

        $this->audit->record('device.sharing.disabled', $device, user: $user);
    }

    public function resolve(string $token): ?Device
    {
        if (! str_starts_with($token, self::TOKEN_PREFIX) || strlen($token) > 128) {
            return null;
        }

        return Device::query()
            ->with('property')
            ->where('share_token_hash', self::hashToken($token))
            ->where('status', DeviceStatus::Active)
            ->first();
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
