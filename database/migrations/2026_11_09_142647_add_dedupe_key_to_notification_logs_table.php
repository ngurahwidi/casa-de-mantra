<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Identifies the occurrence a notification belongs to, so the same one cannot be sent twice.
     *
     * The existing (reservation_id, template, channel) index cannot be unique: a reservation that
     * is reactivated legitimately receives reservation_awaiting_payment again.
     */
    public function up(): void
    {
        Schema::table('notification_logs', function (Blueprint $table) {
            $table->string('dedupe_key', 191)->nullable()->after('template')->unique();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notification_logs', function (Blueprint $table) {
            $table->dropUnique(['dedupe_key']);
            $table->dropColumn('dedupe_key');
        });
    }
};
