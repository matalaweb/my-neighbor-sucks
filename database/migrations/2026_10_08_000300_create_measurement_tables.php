<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A stream is one device/channel under one deployment, profile, and
        // calibration identity. Acoustic series never mix streams (spec §5).
        Schema::create('measurement_streams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('device_id')->constrained()->restrictOnDelete();
            $table->string('channel', 32);
            $table->foreignId('device_deployment_id')->constrained()->restrictOnDelete();
            $table->foreignId('measurement_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('device_calibration_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('calibration_state', 16);
            $table->char('stream_key', 64)->unique();
            $table->dateTime('first_captured_at', 6)->nullable();
            $table->dateTime('last_captured_at', 6)->nullable();
            $table->timestamps();
            $table->index(['device_id', 'channel']);
        });

        Schema::create('measurement_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('device_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('schema_version');
            $table->char('payload_hash', 64);
            $table->dateTime('sent_at', 6)->nullable();
            $table->unsignedInteger('record_count');
            $table->unsignedInteger('inserted_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->dateTime('accepted_from', 6)->nullable();
            $table->dateTime('accepted_to', 6)->nullable();
            $table->json('result')->nullable();
            $table->dateTime('received_at', 6);
            $table->string('request_id', 64)->nullable();
            $table->unique(['device_id', 'uuid']);
            $table->index('received_at');
        });

        Schema::create('measurements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('device_id')->constrained()->restrictOnDelete();
            $table->string('channel', 32);
            $table->uuid('boot_id');
            $table->unsignedBigInteger('sequence');
            $table->foreignId('stream_id')->constrained('measurement_streams')->restrictOnDelete();
            $table->unsignedInteger('configuration_revision');
            $table->dateTime('captured_at', 6);
            $table->unsignedInteger('duration_ms');
            // DOUBLE preserves the reported JSON number exactly (>= 0.01 dB).
            $table->double('laeq_db')->nullable();
            $table->double('lafmax_db')->nullable();
            $table->double('lceq_db')->nullable();
            $table->double('lcpeak_db')->nullable();
            $table->double('low_frequency_leq_db')->nullable();
            $table->double('rms_dbfs')->nullable();
            $table->unsignedSmallInteger('quality_flags')->default(0);
            $table->json('null_reasons')->nullable();
            $table->json('bands')->nullable();
            $table->binary('row_hash', 32, fixed: true);
            $table->foreignId('batch_id')->constrained('measurement_batches')->restrictOnDelete();
            $table->dateTime('received_at', 6);

            $table->unique(['device_id', 'channel', 'boot_id', 'sequence'], 'measurements_identity_unique');
            $table->index(['account_id', 'device_id', 'channel', 'captured_at'], 'measurements_account_time_index');
            $table->index(['stream_id', 'captured_at']);
        });

        // Compact replay receipts retained after raw rows are deleted so old
        // retries cannot resurrect purged readings (spec §8).
        Schema::create('measurement_receipts', function (Blueprint $table) {
            $table->unsignedBigInteger('device_id');
            $table->string('channel', 32);
            $table->uuid('boot_id');
            $table->unsignedBigInteger('sequence');
            $table->binary('row_hash', 32, fixed: true);
            $table->dateTime('captured_at', 6);
            $table->dateTime('raw_deleted_at', 6);
            $table->primary(['device_id', 'channel', 'boot_id', 'sequence']);
            $table->index('captured_at');
            $table->foreign('device_id')->references('id')->on('devices')->restrictOnDelete();
        });

        Schema::create('measurement_rollups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('device_id')->constrained()->restrictOnDelete();
            $table->foreignId('stream_id')->constrained('measurement_streams')->restrictOnDelete();
            $table->unsignedInteger('resolution_seconds');
            $table->dateTime('bucket_start');
            $table->unsignedBigInteger('expected_ms');
            $table->unsignedBigInteger('covered_ms')->default(0);
            $table->unsignedBigInteger('excluded_ms')->default(0);
            $table->unsignedBigInteger('ambiguous_ms')->default(0);
            $table->unsignedInteger('row_count')->default(0);

            foreach (['laeq', 'lceq', 'lf', 'dbfs'] as $metric) {
                $table->double($metric.'_energy_sum')->nullable();
                $table->unsignedBigInteger($metric.'_valid_ms')->default(0);
                $table->unsignedBigInteger($metric.'_excluded_ms')->default(0);
            }

            foreach (['lafmax', 'lcpeak'] as $metric) {
                $table->double($metric.'_max')->nullable();
                $table->unsignedBigInteger($metric.'_valid_ms')->default(0);
                $table->unsignedBigInteger($metric.'_excluded_ms')->default(0);
            }

            $table->json('quality_counts')->nullable();
            $table->json('configuration_revisions')->nullable();
            $table->json('bands')->nullable();
            $table->string('policy_version', 32);
            $table->dateTime('rebuilt_at', 6);

            $table->unique(['stream_id', 'resolution_seconds', 'bucket_start'], 'rollups_stream_bucket_unique');
            $table->index(['account_id', 'device_id', 'resolution_seconds', 'bucket_start'], 'rollups_device_time_index');
        });

        // Durable dirty-bucket/outbox records for required asynchronous work.
        Schema::create('maintenance_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('kind', 32);
            $table->char('dedupe_key', 64)->unique();
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->dateTime('bucket_start')->nullable();
            $table->json('payload')->nullable();
            $table->unsignedInteger('generation')->default(1);
            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->dateTime('available_at', 6);
            $table->dateTime('locked_until', 6)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps(6);
            $table->index(['kind', 'status', 'available_at']);
        });

        Schema::create('api_request_metrics', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->nullable();
            $table->unsignedBigInteger('device_id')->nullable();
            $table->dateTime('hour');
            $table->string('endpoint', 64);
            $table->unsignedSmallInteger('status_code');
            $table->unsignedInteger('request_count')->default(0);
            $table->unsignedBigInteger('latency_ms_sum')->default(0);
            $table->unsignedInteger('latency_ms_max')->default(0);
            $table->unsignedInteger('rejected_rows')->default(0);
            $table->unique(['device_id', 'hour', 'endpoint', 'status_code'], 'api_metrics_unique');
            $table->index(['account_id', 'hour']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_request_metrics');
        Schema::dropIfExists('maintenance_jobs');
        Schema::dropIfExists('measurement_rollups');
        Schema::dropIfExists('measurement_receipts');
        Schema::dropIfExists('measurements');
        Schema::dropIfExists('measurement_batches');
        Schema::dropIfExists('measurement_streams');
    }
};
