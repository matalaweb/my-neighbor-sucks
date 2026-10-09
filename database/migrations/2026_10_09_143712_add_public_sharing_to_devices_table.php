<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            // Public dashboard link: the hash is the lookup key; the encrypted
            // token lets owners copy the link again without storing plaintext.
            $table->char('share_token_hash', 64)->nullable()->unique()->after('heartbeat_interval_seconds');
            $table->text('share_token')->nullable()->after('share_token_hash');
            $table->dateTime('shared_at', 6)->nullable()->after('share_token');
            $table->string('public_title', 120)->nullable()->after('shared_at');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropUnique(['share_token_hash']);
            $table->dropColumn(['share_token_hash', 'share_token', 'shared_at', 'public_title']);
        });
    }
};
