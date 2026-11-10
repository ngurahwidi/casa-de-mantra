<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Allow the skipped status: a queued reminder that no longer applies when its turn comes, such
     * as a payment reminder for a reservation paid meanwhile, is not sent.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE notification_logs DROP CHECK notification_logs_status_check');
        DB::statement("ALTER TABLE notification_logs ADD CONSTRAINT notification_logs_status_check CHECK (status IN ('queued', 'sent', 'failed', 'skipped'))");
    }

    /**
     * Reverse the migrations; skipped notifications become failed ones and keep their reason.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE notification_logs DROP CHECK notification_logs_status_check');
        DB::table('notification_logs')->where('status', 'skipped')->update(['status' => 'failed']);
        DB::statement("ALTER TABLE notification_logs ADD CONSTRAINT notification_logs_status_check CHECK (status IN ('queued', 'sent', 'failed'))");
    }
};
