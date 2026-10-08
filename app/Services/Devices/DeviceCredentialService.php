<?php

namespace App\Services\Devices;

use App\Http\DeviceApi\DeviceTokenResolver;
use App\Models\Device;
use App\Models\DeviceCredential;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Issues, rotates, and revokes device credentials. The plaintext secret is
 * returned once and never stored. During a rotation window the old and new
 * credentials both work; historical device identity never changes.
 */
class DeviceCredentialService
{
    public const MAX_ACTIVE = 2;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return array{credential: DeviceCredential, token: string}
     */
    public function issue(Device $device, ?User $user, ?string $name = null): array
    {
        if ($device->isArchived()) {
            throw new RuntimeException('Archived devices cannot receive credentials.');
        }

        return DB::transaction(function () use ($device, $user, $name): array {
            Device::query()->whereKey($device->id)->lockForUpdate()->first();

            $active = $device->credentials()->whereNull('revoked_at')
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', CarbonImmutable::now()))
                ->count();

            if ($active >= self::MAX_ACTIVE) {
                throw new RuntimeException('A device may have at most '.self::MAX_ACTIVE.' active credentials; revoke one first.');
            }

            $token = DeviceTokenResolver::TOKEN_PREFIX.Str::random(48);

            $credential = $device->credentials()->create([
                'account_id' => $device->account_id,
                'name' => $name ?? 'Credential '.CarbonImmutable::now()->format('Y-m-d H:i'),
                'token_prefix' => substr($token, 0, 12),
                'token_hash' => DeviceCredential::hashSecret($token),
                'abilities' => DeviceCredential::ALL_ABILITIES,
                'issued_by' => $user?->id,
            ]);

            $this->audit->record('device.credential.issued', $credential, ['device_uuid' => $device->uuid, 'token_prefix' => $credential->token_prefix], user: $user);

            return ['credential' => $credential, 'token' => $token];
        });
    }

    /**
     * Issue a replacement and let the existing credentials expire after the
     * rotation window (0 hours = revoke immediately).
     *
     * @return array{credential: DeviceCredential, token: string}
     */
    public function rotate(Device $device, ?User $user, ?int $windowHours = null): array
    {
        $windowHours ??= (int) config('noise.device_api.credential_rotation_hours');

        return DB::transaction(function () use ($device, $user, $windowHours): array {
            $old = $device->credentials()->whereNull('revoked_at')->get();

            foreach ($old as $credential) {
                if ($windowHours === 0) {
                    $this->revoke($credential, $user, 'rotation');
                } elseif ($credential->expires_at === null || $credential->expires_at->greaterThan(CarbonImmutable::now()->addHours($windowHours))) {
                    $credential->forceFill(['expires_at' => CarbonImmutable::now()->addHours($windowHours)])->save();
                }
            }

            // Only one older credential may remain inside the rotation window.
            $keep = $device->credentials()->whereNull('revoked_at')->latest('id')->first();

            foreach ($device->credentials()->whereNull('revoked_at')->where('id', '!=', $keep?->id ?? 0)->get() as $extra) {
                $this->revoke($extra, $user, 'rotation');
            }

            $issued = $this->issue($device, $user, 'Rotated '.CarbonImmutable::now()->format('Y-m-d H:i'));
            $this->audit->record('device.credential.rotated', $issued['credential'], ['window_hours' => $windowHours], user: $user);

            return $issued;
        });
    }

    public function revoke(DeviceCredential $credential, ?User $user, string $reason = 'manual'): void
    {
        if ($credential->revoked_at !== null) {
            return;
        }

        $credential->forceFill(['revoked_at' => CarbonImmutable::now(), 'revoked_by' => $user?->id])->save();
        $this->audit->record('device.credential.revoked', $credential, ['reason' => $reason, 'token_prefix' => $credential->token_prefix], user: $user);
    }
}
