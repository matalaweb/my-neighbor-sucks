<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('noise_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('device_id')->constrained()->restrictOnDelete();
            $table->string('channel', 32);
            $table->foreignId('stream_id')->constrained('measurement_streams')->restrictOnDelete();
            $table->unsignedInteger('configuration_revision');
            $table->unsignedInteger('current_revision');

            // Projection of the latest agent revision (source record).
            $table->string('detection_state', 16);
            $table->dateTime('started_at', 6);
            $table->dateTime('ended_at', 6)->nullable();
            $table->dateTime('recording_started_at', 6)->nullable();
            $table->dateTime('recording_ended_at', 6)->nullable();
            $table->string('detection_rule_version', 64);
            $table->string('trigger_metric', 32);
            $table->string('trigger_kind', 32);
            $table->double('trigger_threshold_db')->nullable();
            $table->double('trigger_value_db')->nullable();
            $table->double('baseline_db')->nullable();
            $table->string('baseline_method')->nullable();
            $table->json('agent_summary')->nullable();
            $table->unsignedSmallInteger('quality_flags')->default(0);
            $table->boolean('recording_expected')->default(false);
            $table->unsignedSmallInteger('expected_segment_count')->nullable();

            // Server-maintained states (spec §9).
            $table->string('completeness_state', 16)->default('pending');
            $table->string('recording_state', 16)->nullable();
            $table->json('server_summary')->nullable();
            $table->dateTime('server_summary_at', 6)->nullable();

            // Review projection from append-only annotations.
            $table->string('review_status', 32)->default('unreviewed');
            $table->string('source_label', 32)->nullable();
            $table->string('source_certainty', 16)->nullable();
            $table->string('review_confidence', 16)->nullable();
            $table->unsignedBigInteger('latest_review_annotation_id')->nullable();
            $table->dateTime('marked_incomplete_at', 6)->nullable();

            $table->boolean('keep')->default(false);
            $table->dateTime('keep_changed_at', 6)->nullable();
            $table->foreignId('keep_changed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->dateTime('first_received_at', 6);
            $table->dateTime('last_revision_received_at', 6);
            $table->timestamps(6);

            $table->unique(['device_id', 'uuid']);
            $table->index(['account_id', 'property_id', 'started_at'], 'events_property_time_index');
            $table->index(['account_id', 'review_status', 'started_at'], 'events_review_index');
            $table->index(['device_id', 'channel', 'started_at']);
        });

        Schema::create('noise_event_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('noise_event_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->json('payload');
            $table->char('payload_hash', 64);
            $table->boolean('applied_to_projection');
            $table->dateTime('received_at', 6);
            $table->string('request_id', 64)->nullable();
            $table->unique(['noise_event_id', 'revision']);
        });

        Schema::create('event_measurement_snapshots', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('noise_event_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 16); // collecting | frozen
            $table->string('completeness', 16); // pending | complete | partial | unavailable
            $table->dateTime('window_start', 6);
            $table->dateTime('window_end', 6)->nullable();
            $table->dateTime('detection_start', 6);
            $table->dateTime('detection_end', 6)->nullable();
            $table->unsignedInteger('expected_intervals')->default(0);
            $table->unsignedInteger('captured_intervals')->default(0);
            $table->json('missing_ranges')->nullable();
            $table->longText('rows')->nullable();
            $table->char('content_hash', 64)->nullable();
            $table->string('policy_version', 32);
            $table->text('limitations')->nullable();
            $table->dateTime('frozen_at', 6)->nullable();
            $table->timestamps(6);
            $table->unique(['noise_event_id', 'version']);
        });

        Schema::create('event_recordings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('device_id')->constrained()->restrictOnDelete();
            $table->foreignId('noise_event_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('segment_number');
            $table->dateTime('capture_started_at', 6);
            $table->unsignedInteger('duration_ms');
            $table->string('mime_type', 64);
            $table->string('codec', 32);
            $table->unsignedInteger('sample_rate_hz');
            $table->unsignedTinyInteger('channel_count');
            $table->unsignedTinyInteger('bit_depth')->nullable();
            $table->unsignedBigInteger('byte_size');
            $table->char('reported_sha256', 64);
            $table->char('declaration_hash', 64);
            $table->string('status', 16);
            $table->string('failure_reason')->nullable();

            // Verification result of the preserved original.
            $table->char('verified_sha256', 64)->nullable();
            $table->unsignedBigInteger('verified_byte_size')->nullable();
            $table->unsignedInteger('verified_duration_ms')->nullable();
            $table->json('media_info')->nullable();
            $table->string('final_disk', 32)->nullable();
            $table->string('final_key')->nullable();
            $table->string('final_version_id')->nullable();
            $table->dateTime('verified_at', 6)->nullable();

            // Optional clearly-labelled playback derivative (never replaces the original).
            $table->string('derivative_key')->nullable();
            $table->string('derivative_mime_type', 64)->nullable();
            $table->char('derivative_sha256', 64)->nullable();

            // Purge tombstone: identity and hash survive; playback access does not.
            $table->dateTime('purge_started_at', 6)->nullable();
            $table->dateTime('purged_at', 6)->nullable();
            $table->string('purge_reason')->nullable();

            $table->dateTime('declared_at', 6);
            $table->timestamps(6);

            $table->unique(['device_id', 'uuid']);
            $table->unique(['noise_event_id', 'segment_number']);
            $table->index(['account_id', 'status']);
        });

        Schema::create('recording_upload_attempts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('event_recording_id')->constrained()->restrictOnDelete();
            $table->string('staging_key');
            $table->dateTime('expires_at', 6);
            $table->string('state', 16);
            $table->char('computed_sha256', 64)->nullable();
            $table->unsignedBigInteger('computed_byte_size')->nullable();
            $table->string('failure_reason')->nullable();
            $table->dateTime('completed_at', 6)->nullable();
            $table->dateTime('verification_started_at', 6)->nullable();
            $table->dateTime('finished_at', 6)->nullable();
            $table->dateTime('staging_deleted_at', 6)->nullable();
            $table->timestamps(6);
            $table->index(['state', 'expires_at']);
        });

        Schema::create('event_annotations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('noise_event_id')->constrained()->restrictOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 32); // review | note | incomplete_marker | keep_change
            $table->string('review_status', 32)->nullable();
            $table->string('source_label', 32)->nullable();
            $table->string('source_certainty', 16)->nullable();
            $table->string('confidence', 16)->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('supersedes_id')->nullable()->constrained('event_annotations')->restrictOnDelete();
            $table->dateTime('created_at', 6);
            $table->index(['noise_event_id', 'id']);
        });

        Schema::create('event_groups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('event_group_noise_event', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('noise_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->unique(['event_group_id', 'noise_event_id']);
        });

        Schema::create('evidence_exports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 32);
            $table->string('status', 16);
            $table->string('title')->nullable();
            $table->json('scope');
            $table->json('options')->nullable();
            // Frozen selection captured at request time (spec §15).
            $table->json('selection');
            $table->json('manifest')->nullable();
            $table->unsignedBigInteger('estimated_bytes')->nullable();
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->char('export_sha256', 64)->nullable();
            $table->string('disk', 32)->nullable();
            $table->string('object_key')->nullable();
            $table->string('file_name')->nullable();
            $table->boolean('limits_overridden')->default(false);
            $table->unsignedInteger('attempts')->default(0);
            $table->text('failure_reason')->nullable();
            $table->dateTime('requested_at', 6);
            $table->dateTime('started_at', 6)->nullable();
            $table->dateTime('finished_at', 6)->nullable();
            $table->dateTime('expires_at', 6)->nullable();
            $table->dateTime('object_deleted_at', 6)->nullable();
            $table->timestamps(6);
            $table->index(['account_id', 'status']);
        });

        Schema::table('noise_events', function (Blueprint $table) {
            $table->foreign('latest_review_annotation_id')->references('id')->on('event_annotations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('noise_events', fn (Blueprint $table) => $table->dropForeign(['latest_review_annotation_id']));
        Schema::dropIfExists('evidence_exports');
        Schema::dropIfExists('event_group_noise_event');
        Schema::dropIfExists('event_groups');
        Schema::dropIfExists('event_annotations');
        Schema::dropIfExists('recording_upload_attempts');
        Schema::dropIfExists('event_recordings');
        Schema::dropIfExists('event_measurement_snapshots');
        Schema::dropIfExists('noise_event_revisions');
        Schema::dropIfExists('noise_events');
    }
};
