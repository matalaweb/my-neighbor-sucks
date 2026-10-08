<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('status', 16)->default('active');
            $table->json('capabilities')->nullable();
            $table->string('software_version', 64)->nullable();
            $table->uuid('current_boot_id')->nullable();
            $table->dateTime('last_contact_at', 6)->nullable();
            $table->dateTime('latest_capture_at', 6)->nullable();
            $table->dateTime('latest_measurement_received_at', 6)->nullable();
            $table->unsignedBigInteger('latest_heartbeat_id')->nullable();
            $table->unsignedInteger('desired_config_revision')->nullable();
            $table->unsignedInteger('applied_config_revision')->nullable();
            $table->unsignedInteger('reporting_interval_seconds')->default(30);
            $table->unsignedInteger('heartbeat_interval_seconds')->default(60);
            // Owner-enabled import window allowing backfill older than the default 30 days.
            $table->dateTime('import_window_starts_at')->nullable();
            $table->dateTime('import_window_expires_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['account_id', 'property_id', 'status']);
        });

        Schema::create('device_credentials', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('device_id')->constrained()->restrictOnDelete();
            $table->string('name')->nullable();
            $table->string('token_prefix', 16);
            // SHA-256 digest of the full secret; the plaintext is shown once.
            $table->char('token_hash', 64)->unique();
            $table->json('abilities');
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->timestamps();
            $table->index(['device_id', 'revoked_at']);
        });

        // Immutable placement revisions (spec §5).
        Schema::create('device_deployments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('device_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->string('room')->nullable();
            $table->string('location_type', 16);
            $table->text('placement_description')->nullable();
            $table->text('mounting_notes')->nullable();
            $table->decimal('height_m', 5, 2)->nullable();
            $table->string('orientation')->nullable();
            $table->dateTime('effective_at', 6);
            $table->char('content_hash', 64);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('created_at', 6);
            $table->unique(['device_id', 'revision']);
        });

        // Immutable device/channel processing settings (spec §4).
        Schema::create('measurement_profiles', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('device_id')->constrained()->restrictOnDelete();
            $table->string('channel', 32);
            $table->unsignedInteger('revision');
            $table->string('name')->nullable();
            $table->string('microphone_model');
            $table->string('microphone_serial')->nullable();
            $table->string('audio_interface')->nullable();
            $table->unsignedInteger('sample_rate_hz');
            $table->decimal('gain_db', 6, 2)->nullable();
            $table->string('gain_description')->nullable();
            $table->string('weighting_implementation_version', 64);
            $table->string('filter_implementation_version', 64);
            $table->string('calibration_state', 16);
            $table->string('calibration_application_method')->nullable();
            $table->json('supported_metrics');
            $table->decimal('low_frequency_lower_hz', 8, 2)->nullable();
            $table->decimal('low_frequency_upper_hz', 8, 2)->nullable();
            $table->json('band_definitions')->nullable();
            $table->string('agent_processing_version', 64);
            $table->char('content_hash', 64);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('created_at', 6);
            $table->unique(['device_id', 'channel', 'revision']);
        });

        Schema::create('device_calibrations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('device_id')->constrained()->restrictOnDelete();
            $table->string('channel', 32);
            $table->unsignedInteger('revision');
            $table->string('calibration_state', 16);
            $table->string('reference_method');
            $table->string('reference_device')->nullable();
            $table->decimal('reference_level_db', 6, 2)->nullable();
            $table->decimal('reference_frequency_hz', 8, 2)->nullable();
            $table->decimal('sensitivity_mv_per_pa', 10, 4)->nullable();
            $table->decimal('sensitivity_dbfs_at_94db', 8, 3)->nullable();
            $table->string('gain_configuration')->nullable();
            $table->string('application_method')->nullable();
            $table->json('correction_metadata')->nullable();
            $table->dateTime('performed_at', 6)->nullable();
            $table->string('performed_by')->nullable();
            $table->text('notes')->nullable();
            $table->char('content_hash', 64);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('created_at', 6);
            $table->unique(['device_id', 'channel', 'revision']);
        });

        Schema::create('calibration_field_checks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('device_calibration_id')->constrained()->restrictOnDelete();
            $table->dateTime('checked_at', 6);
            $table->string('reference_source');
            $table->decimal('expected_level_db', 6, 2)->nullable();
            $table->decimal('measured_level_db', 6, 2)->nullable();
            $table->boolean('passed')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('created_at', 6);
        });

        // Private supporting files (frequency response, certificates, placement photos).
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->morphs('attachable');
            $table->string('purpose', 48);
            $table->string('disk', 32);
            $table->string('object_key');
            $table->string('original_filename');
            $table->string('mime_type', 128)->nullable();
            $table->unsignedBigInteger('byte_size');
            $table->char('sha256', 64);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('created_at', 6);
        });

        Schema::create('device_configurations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('device_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->json('document');
            $table->char('content_hash', 64);
            $table->unsignedInteger('rollback_of_revision')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('issued_at', 6);
            $table->unique(['device_id', 'revision']);
        });

        Schema::create('device_config_acknowledgments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('device_id')->constrained()->restrictOnDelete();
            $table->foreignId('device_configuration_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->string('status', 16);
            $table->text('reason')->nullable();
            $table->dateTime('reported_applied_at', 6)->nullable();
            $table->dateTime('received_at', 6);
            $table->string('request_id', 64)->nullable();
            $table->index(['device_id', 'received_at']);
        });

        Schema::create('device_heartbeats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('device_id')->constrained()->restrictOnDelete();
            $table->string('agent_version', 64)->nullable();
            $table->uuid('boot_id')->nullable();
            $table->unsignedBigInteger('uptime_seconds')->nullable();
            $table->json('capabilities')->nullable();
            $table->string('microphone_state', 16)->nullable();
            $table->unsignedBigInteger('free_disk_bytes')->nullable();
            $table->unsignedBigInteger('total_disk_bytes')->nullable();
            $table->unsignedBigInteger('queued_measurement_count')->nullable();
            $table->unsignedBigInteger('pending_audio_bytes')->nullable();
            $table->unsignedInteger('pending_audio_count')->nullable();
            $table->dateTime('oldest_pending_capture_at', 6)->nullable();
            $table->unsignedInteger('desired_config_revision')->nullable();
            $table->unsignedInteger('applied_config_revision')->nullable();
            $table->string('clock_sync_state', 16)->nullable();
            $table->integer('clock_offset_ms')->nullable();
            $table->unsignedInteger('recent_dropped_intervals')->nullable();
            $table->text('last_capture_error')->nullable();
            $table->dateTime('sent_at', 6)->nullable();
            $table->dateTime('received_at', 6);
            $table->index(['device_id', 'received_at']);
        });

        Schema::create('device_health_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('device_id')->constrained()->restrictOnDelete();
            $table->date('day');
            $table->unsignedInteger('heartbeat_count');
            $table->unsignedBigInteger('min_free_disk_bytes')->nullable();
            $table->unsignedBigInteger('max_queued_measurement_count')->nullable();
            $table->unsignedBigInteger('max_pending_audio_bytes')->nullable();
            $table->unsignedInteger('unsynchronized_clock_count')->default(0);
            $table->unsignedInteger('microphone_fault_count')->default(0);
            $table->integer('max_abs_clock_offset_ms')->nullable();
            $table->unsignedInteger('dropped_intervals')->default(0);
            $table->json('agent_versions')->nullable();
            $table->timestamps();
            $table->unique(['device_id', 'day']);
        });

        Schema::table('devices', function (Blueprint $table) {
            $table->foreign('latest_heartbeat_id')->references('id')->on('device_heartbeats')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('devices', fn (Blueprint $table) => $table->dropForeign(['latest_heartbeat_id']));
        Schema::dropIfExists('device_health_summaries');
        Schema::dropIfExists('device_heartbeats');
        Schema::dropIfExists('device_config_acknowledgments');
        Schema::dropIfExists('device_configurations');
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('calibration_field_checks');
        Schema::dropIfExists('device_calibrations');
        Schema::dropIfExists('measurement_profiles');
        Schema::dropIfExists('device_deployments');
        Schema::dropIfExists('device_credentials');
        Schema::dropIfExists('devices');
    }
};
