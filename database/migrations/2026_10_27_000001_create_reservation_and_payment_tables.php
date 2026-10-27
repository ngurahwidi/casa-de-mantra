<?php

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ReservationPaymentStatus;
use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
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
        Schema::create('guests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('full_name');
            // Guests are matched by email when a request comes in (T4.4).
            $table->string('email', 191)->unique();
            $table->string('phone', 50);
            $table->string('country', 100)->nullable();
            $table->timestamps();
        });

        Schema::create('reservations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Public, hard-to-guess reference. Compared case-sensitively.
            $table->string('code', 16)->unique()->collation('utf8mb4_bin');
            $table->foreignUuid('guest_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default(ReservationStatus::Pending->value);
            $table->string('source', 20)->default(ReservationSource::DirectWeb->value);
            // Stay dates; check_out is exclusive.
            $table->date('check_in');
            $table->date('check_out');
            $table->unsignedSmallInteger('adults');
            $table->unsignedSmallInteger('children')->default(0);
            $table->text('special_requests')->nullable();
            $table->char('currency', 3);
            $table->decimal('subtotal', 14, 2);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('total', 14, 2);
            $table->string('payment_status', 20)->default(ReservationPaymentStatus::Unpaid->value);
            // Payment deadline while awaiting_payment.
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('ota_blocked_at')->nullable();
            $table->text('reject_reason')->nullable();
            // Link to the originating system when the booking comes from an OTA/PMS.
            $table->string('external_source', 50)->nullable();
            $table->string('external_id', 191)->nullable();
            // Cancellation policy as shown to the guest at booking time.
            $table->json('policy_snapshot')->nullable();
            $table->timestamps();

            $table->index('guest_id');
            $table->index(['status', 'check_in', 'check_out']);
            $table->index('expires_at');
            $table->index(['external_source', 'external_id']);
        });

        Schema::create('reservation_rooms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('room_type_id')->constrained()->restrictOnDelete();
            // Assigned on approval (T4.5); a unit with reservations cannot be deleted, only deactivated.
            $table->foreignUuid('unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('rate_plan_id')->constrained()->restrictOnDelete();
            $table->decimal('total_price', 14, 2);
            $table->timestamps();

            $table->index('reservation_id');
            $table->index('room_type_id');
            $table->index('unit_id');
            $table->index('rate_plan_id');
        });

        Schema::create('reservation_nights', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('reservation_room_id')->constrained()->cascadeOnDelete();
            // Snapshot of the price that applied to this night at booking time.
            $table->date('night_date');
            $table->decimal('price', 14, 2);
            $table->timestamps();

            // Also serves as the index for the reservation_room_id foreign key.
            $table->unique(['reservation_room_id', 'night_date']);
        });

        Schema::create('reservation_status_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('reservation_id')->constrained()->cascadeOnDelete();
            // Null for system actions (expiry, guest request).
            $table->foreignId('admin_user_id')->nullable()->constrained('users')->nullOnDelete();
            // Null for the entry that records the creation of the reservation.
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->text('note')->nullable();
            // Append-only: there is no updated_at.
            $table->timestamp('created_at')->useCurrent();

            // Also serves as the index for the reservation_id foreign key.
            $table->index(['reservation_id', 'created_at']);
            $table->index('admin_user_id');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Financial records are never removed together with a reservation.
            $table->foreignUuid('reservation_id')->constrained()->restrictOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('method', 20);
            $table->string('status', 20)->default(PaymentStatus::Pending->value);
            // Payment gateway name and its reference; both empty for manual transfers.
            $table->string('provider', 50)->nullable();
            $table->string('provider_ref', 191)->nullable();
            $table->timestamp('paid_at')->nullable();
            // Path of the transfer proof on the private disk.
            $table->text('proof_url')->nullable();
            $table->timestamps();

            $table->index('reservation_id');
            $table->index('verified_by');
            $table->index(['provider', 'provider_ref']);
        });

        $statuses = $this->quoted(ReservationStatus::cases());
        $sources = $this->quoted(ReservationSource::cases());
        $reservationPaymentStatuses = $this->quoted(ReservationPaymentStatus::cases());
        $paymentMethods = $this->quoted(PaymentMethod::cases());
        $paymentStatuses = $this->quoted(PaymentStatus::cases());

        DB::statement("ALTER TABLE reservations ADD CONSTRAINT reservations_status_check CHECK (status IN ({$statuses}))");
        DB::statement("ALTER TABLE reservations ADD CONSTRAINT reservations_source_check CHECK (source IN ({$sources}))");
        DB::statement("ALTER TABLE reservations ADD CONSTRAINT reservations_payment_status_check CHECK (payment_status IN ({$reservationPaymentStatuses}))");
        DB::statement('ALTER TABLE reservations ADD CONSTRAINT reservations_date_range_check CHECK (check_out > check_in)');
        DB::statement('ALTER TABLE reservations ADD CONSTRAINT reservations_guests_check CHECK (adults >= 1)');
        DB::statement('ALTER TABLE reservations ADD CONSTRAINT reservations_amounts_check CHECK (subtotal >= 0 AND tax_total >= 0 AND total >= 0)');

        DB::statement('ALTER TABLE reservation_rooms ADD CONSTRAINT reservation_rooms_total_price_check CHECK (total_price >= 0)');

        DB::statement('ALTER TABLE reservation_nights ADD CONSTRAINT reservation_nights_price_check CHECK (price >= 0)');

        DB::statement("ALTER TABLE reservation_status_logs ADD CONSTRAINT reservation_status_logs_from_status_check CHECK (from_status IS NULL OR from_status IN ({$statuses}))");
        DB::statement("ALTER TABLE reservation_status_logs ADD CONSTRAINT reservation_status_logs_to_status_check CHECK (to_status IN ({$statuses}))");

        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_method_check CHECK (method IN ({$paymentMethods}))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_status_check CHECK (status IN ({$paymentStatuses}))");
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_check CHECK (amount > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('reservation_status_logs');
        Schema::dropIfExists('reservation_nights');
        Schema::dropIfExists('reservation_rooms');
        Schema::dropIfExists('reservations');
        Schema::dropIfExists('guests');
    }

    /**
     * @param  array<int, BackedEnum>  $cases
     */
    private function quoted(array $cases): string
    {
        return collect($cases)->map(fn (BackedEnum $case) => "'{$case->value}'")->implode(', ');
    }
};
