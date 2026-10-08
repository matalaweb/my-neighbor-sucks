<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Services\Retention\ApplyRetention;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('noise:retention {--account= : Account UUID (defaults to all accounts)} {--dry-run : Count what would be removed without changing anything}')]
#[Description('Apply the configured retention policy with staged, retryable deletion')]
class ApplyRetentionCommand extends Command
{
    public function handle(ApplyRetention $retention): int
    {
        $account = null;

        if ($this->option('account')) {
            $account = Account::query()->where('uuid', $this->option('account'))->first();

            if ($account === null) {
                $this->error('Unknown account.');

                return self::FAILURE;
            }
        }

        $report = $retention->run($account, (bool) $this->option('dry-run'));

        foreach ($report['accounts'] as $uuid => $result) {
            $this->info(($report['dry_run'] ? '[dry run] ' : '')."Account {$uuid}");
            $this->table(['Category', 'Rows/objects'], collect($result)->except(['blocked', 'failures'])->map(fn ($count, $key) => [$key, $count])->values()->all());

            foreach ($result['blocked'] as $line) {
                $this->warn('Blocked: '.$line);
            }

            foreach ($result['failures'] as $line) {
                $this->error('Failure: '.$line);
            }
        }

        $this->line('Retried object deletions: '.$report['retried_object_deletions']);

        return collect($report['accounts'])->contains(fn ($result) => $result['failures'] !== []) ? self::FAILURE : self::SUCCESS;
    }
}
