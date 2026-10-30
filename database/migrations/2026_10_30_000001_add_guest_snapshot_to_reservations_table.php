<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            // Guest contact details as entered with this reservation. The guests row is only
            // the identity shared across bookings and may hold newer or older details.
            $table->string('guest_full_name')->after('guest_id');
            $table->string('guest_email', 191)->after('guest_full_name');
            $table->string('guest_phone', 50)->after('guest_email');
            $table->string('guest_country', 100)->nullable()->after('guest_phone');
        });

        // Existing reservations take the details currently on file for their guest.
        DB::statement(<<<'SQL'
            UPDATE reservations
            INNER JOIN guests ON guests.id = reservations.guest_id
            SET reservations.guest_full_name = guests.full_name,
                reservations.guest_email = guests.email,
                reservations.guest_phone = guests.phone,
                reservations.guest_country = guests.country
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn(['guest_full_name', 'guest_email', 'guest_phone', 'guest_country']);
        });
    }
};
