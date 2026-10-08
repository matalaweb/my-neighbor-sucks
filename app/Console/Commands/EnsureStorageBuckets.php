<?php

namespace App\Console\Commands;

use Aws\S3\S3Client;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Local development helper: creates the private S3-compatible buckets used
 * by the compose stack. Laravel Cloud provisions buckets itself; this command
 * refuses to run in production.
 */
#[Signature('noise:storage:ensure-buckets {--bucket=* : Bucket names (defaults to AWS_BUCKET and the test bucket)}')]
#[Description('Create the local private S3-compatible buckets (development only)')]
class EnsureStorageBuckets extends Command
{
    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('Refusing to manage buckets in production; provision them in Laravel Cloud.');

            return self::FAILURE;
        }

        $config = config('filesystems.disks.s3');
        $client = new S3Client([
            'version' => 'latest',
            'region' => $config['region'] ?: 'us-east-1',
            'endpoint' => $config['endpoint'],
            'use_path_style_endpoint' => (bool) $config['use_path_style_endpoint'],
            'credentials' => ['key' => $config['key'], 'secret' => $config['secret']],
        ]);

        $buckets = $this->option('bucket') ?: array_unique(array_filter([$config['bucket'], 'noise-monitor-test']));

        foreach ($buckets as $bucket) {
            try {
                if (! $client->doesBucketExistV2($bucket)) {
                    $client->createBucket(['Bucket' => $bucket]);
                    $this->info("Created private bucket {$bucket}.");
                } else {
                    $this->line("Bucket {$bucket} already exists.");
                }
            } catch (Throwable $exception) {
                $this->error("Could not ensure bucket {$bucket}: ".$exception->getMessage());

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
