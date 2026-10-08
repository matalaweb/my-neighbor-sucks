<x-filament-panels::page>
    <div class="grid gap-6 lg:grid-cols-2">
        <x-filament::section>
            <x-slot name="heading">Members</x-slot>
            <x-slot name="description">Owners manage users, devices, credentials, settings, retention, deletion, and exports. Reviewers annotate and export. Viewers read measurements and play recordings.</x-slot>
            <table class="w-full text-left text-sm">
                <thead class="text-xs text-gray-500"><tr><th>Name</th><th>Role</th><th></th></tr></thead>
                <tbody>
                    @foreach ($members as $member)
                        <tr class="border-t border-gray-100 dark:border-white/5">
                            <td class="py-2">{{ $member->name }}<div class="text-xs text-gray-500">{{ $member->email }}</div></td>
                            <td><x-filament::badge size="sm" color="gray">{{ \App\Enums\MembershipRole::from($member->pivot->role)->getLabel() }}</x-filament::badge></td>
                            <td class="text-end">
                                {{ ($this->changeRoleAction)(['user' => $member->id]) }}
                                @if ($member->id !== auth()->id())
                                    {{ ($this->removeMemberAction)(['user' => $member->id]) }}
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            @if ($invitations->isNotEmpty())
                <h3 class="mt-4 text-sm font-semibold">Pending invitations</h3>
                <ul class="text-sm">
                    @foreach ($invitations as $invitation)
                        <li class="flex items-center justify-between py-1">
                            <span>{{ $invitation->email }} · {{ $invitation->role->getLabel() }} · expires {{ $invitation->expires_at->diffForHumans() }}</span>
                            {{ ($this->revokeInvitationAction)(['invitation' => $invitation->id]) }}
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Storage</x-slot>
            <dl class="grid grid-cols-2 gap-y-1 text-sm nm-tabular">
                <dt class="text-gray-500">Verified recordings</dt><dd>{{ $storage['recordings_count'] }} · {{ \Illuminate\Support\Number::fileSize($storage['recordings_bytes']) }}</dd>
                <dt class="text-gray-500">Stored exports</dt><dd>{{ \Illuminate\Support\Number::fileSize($storage['exports_bytes']) }}</dd>
                <dt class="text-gray-500">Attachments</dt><dd>{{ \Illuminate\Support\Number::fileSize($storage['attachments_bytes']) }}</dd>
                <dt class="text-gray-500">One-second readings</dt><dd>{{ number_format($storage['measurement_rows']) }} rows</dd>
                <dt class="text-gray-500">Rollup buckets</dt><dd>{{ number_format($storage['rollup_rows']) }}</dd>
            </dl>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Retention</x-slot>
            <x-slot name="description">Applied daily. Events marked "keep" preserve their snapshots, recordings, and provenance until the flag is removed.</x-slot>
            <dl class="grid grid-cols-2 gap-y-1 text-sm">
                @foreach ($retention as $key => $value)
                    <dt class="text-gray-500">{{ str($key)->replace('_', ' ')->ucfirst() }}</dt>
                    <dd>{{ $value === null ? 'until owner deletion' : $value }}</dd>
                @endforeach
            </dl>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Recording policy</x-slot>
            <p class="text-sm">Event recordings are <strong>{{ $recordingEnabled ? 'accepted' : 'disabled' }}</strong>. Only event clips are stored — no continuous recording, speech transcription, automatic identity attribution, or speed estimation.</p>
            <p class="mt-2 text-sm text-gray-500">Property timezones are edited on each property.</p>
        </x-filament::section>
    </div>
</x-filament-panels::page>
