<?php

namespace App\Console\Commands;

use App\Enums\MembershipRole;
use App\Models\Account;
use App\Models\Property;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use DateTimeZone;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Laravel\Prompts\password;

/**
 * Production-safe initial setup: creates the first account, its owner, and
 * optionally a property. Seeds no measurements or demo data.
 */
#[Signature('noise:create-owner {email} {--name= : Owner display name} {--account= : Household/account name} {--property= : Optional first property name} {--timezone= : Property timezone (default America/Chicago)} {--generate-password : Generate and print a random password instead of prompting} {--if-none : Do nothing (successfully) when any user already exists; used by first-boot init}')]
#[Description('Create the initial account owner (public registration is disabled)')]
class CreateOwner extends Command
{
    public function handle(AuditLogger $audit): int
    {
        $email = Str::lower($this->argument('email'));

        if ($this->option('if-none') && User::query()->exists()) {
            $this->line('Users already exist; skipping initial owner creation.');

            return self::SUCCESS;
        }

        if (User::query()->where('email', $email)->exists()) {
            $this->error('A user with that email already exists.');

            return self::FAILURE;
        }

        $timezone = $this->option('timezone') ?: config('noise.default_timezone');

        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            $this->error('Unknown timezone '.$timezone);

            return self::FAILURE;
        }

        $fromEnvironment = (string) env('NOISE_INITIAL_OWNER_PASSWORD', '');

        if ($fromEnvironment !== '' && strlen($fromEnvironment) < 12) {
            $this->error('NOISE_INITIAL_OWNER_PASSWORD must be at least 12 characters.');

            return self::FAILURE;
        }

        $generated = $fromEnvironment === '' && ($this->option('generate-password') || ! $this->input->isInteractive());
        $plain = $fromEnvironment !== '' ? $fromEnvironment : ($generated ? Str::password(20) : password('Password (12+ characters)', required: true, validate: fn (string $value) => strlen($value) < 12 ? 'Use at least 12 characters.' : null));

        [$user, $account] = DB::transaction(function () use ($email, $plain, $timezone): array {
            $account = Account::query()->create(['name' => $this->option('account') ?: 'Household']);
            $user = new User([
                'name' => $this->option('name') ?: Str::before($email, '@'),
                'email' => $email,
                'password' => $plain,
                'current_account_id' => $account->id,
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();
            $account->users()->attach($user, ['role' => MembershipRole::Owner->value]);

            if ($this->option('property')) {
                Property::query()->create(['account_id' => $account->id, 'name' => $this->option('property'), 'timezone' => $timezone]);
            }

            return [$user, $account];
        });

        $audit->record('membership.initial_owner_created', $user, [], accountId: $account->id, user: $user);

        $this->info("Owner {$email} created for account \"{$account->name}\".");

        if ($generated) {
            $this->warn('Generated password (shown once): '.$plain);
        }

        $this->line('Sign in at '.url('/app'));

        return self::SUCCESS;
    }
}
