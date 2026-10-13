<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LZpeak (unweighted instantaneous peak), reported by collectors from
 * 0.3.0 next to LCpeak. Nullable: older agents don't send it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('measurements', function (Blueprint $table) {
            $table->double('lzpeak_db')->nullable()->after('lcpeak_db');
        });

        Schema::table('measurement_rollups', function (Blueprint $table) {
            $table->double('lzpeak_max')->nullable()->after('lcpeak_excluded_ms');
            $table->unsignedBigInteger('lzpeak_valid_ms')->default(0)->after('lzpeak_max');
            $table->unsignedBigInteger('lzpeak_excluded_ms')->default(0)->after('lzpeak_valid_ms');
        });
    }

    public function down(): void
    {
        Schema::table('measurement_rollups', function (Blueprint $table) {
            $table->dropColumn(['lzpeak_max', 'lzpeak_valid_ms', 'lzpeak_excluded_ms']);
        });

        Schema::table('measurements', function (Blueprint $table) {
            $table->dropColumn('lzpeak_db');
        });
    }
};
