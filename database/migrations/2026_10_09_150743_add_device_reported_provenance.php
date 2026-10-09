<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Device-reported measurement chain (ADR 0001, 2026-10-09): devices register
 * their own profiles and calibrations, readings may predate any placement
 * (resolved by the server from the capture time) and any published
 * configuration (configuration_revision null = agent ran on local defaults).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['measurement_profiles', 'device_calibrations'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('source', 16)->default('owner')->after('content_hash');
            });
        }

        Schema::table('measurement_streams', function (Blueprint $table) {
            $table->unsignedBigInteger('device_deployment_id')->nullable()->change();
        });

        Schema::table('measurements', function (Blueprint $table) {
            $table->unsignedInteger('configuration_revision')->nullable()->change();
        });

        Schema::table('noise_events', function (Blueprint $table) {
            $table->unsignedInteger('configuration_revision')->nullable()->change();
        });
    }

    /**
     * Reverting fails while rows without a placement or configuration revision exist.
     */
    public function down(): void
    {
        Schema::table('noise_events', function (Blueprint $table) {
            $table->unsignedInteger('configuration_revision')->nullable(false)->change();
        });

        Schema::table('measurements', function (Blueprint $table) {
            $table->unsignedInteger('configuration_revision')->nullable(false)->change();
        });

        Schema::table('measurement_streams', function (Blueprint $table) {
            $table->unsignedBigInteger('device_deployment_id')->nullable(false)->change();
        });

        foreach (['measurement_profiles', 'device_calibrations'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('source');
            });
        }
    }
};
