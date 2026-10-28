<?php

use App\Enums\NotificationChannel;
use App\Enums\NotificationLogStatus;
use App\Enums\OutboxEventStatus;
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
        Schema::create('pages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug', 191)->unique();
            $table->string('title');
            $table->longText('body')->nullable();
            $table->boolean('is_published')->default(false);
            $table->timestamps();
        });

        Schema::create('faqs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('question');
            $table->text('answer');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('sort_order');
        });

        Schema::create('reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('guest_name');
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            // Where the review was originally left (e.g. Google, Booking.com); free text.
            $table->string('source', 50)->nullable();
            $table->boolean('is_published')->default(false);
            $table->timestamps();

            $table->index('is_published');
        });

        Schema::create('notification_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 100);
            $table->string('channel', 20);
            // Not every channel has a subject (e.g. WhatsApp later).
            $table->string('subject')->nullable();
            $table->text('body');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Also serves as the index for lookups by code.
            $table->unique(['code', 'channel']);
        });

        Schema::create('notification_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('reservation_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 20);
            // Code of the template that was used (notification_templates.code).
            $table->string('template', 100);
            $table->string('status', 20)->default(NotificationLogStatus::Queued->value);
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            // Also serves as the index for the reservation_id foreign key.
            $table->index(['reservation_id', 'template', 'channel']);
            $table->index('status');
        });

        Schema::create('integration_mappings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Not a foreign key: it can point at any entity (reservation, room type, unit, ...).
            $table->string('entity_type', 50);
            $table->uuid('internal_id');
            $table->string('provider', 50);
            $table->string('external_id', 191);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            // One external counterpart per entity and provider.
            $table->unique(['entity_type', 'internal_id', 'provider']);
            // Reverse lookup when a provider sends us its own id.
            $table->index(['provider', 'entity_type', 'external_id']);
        });

        Schema::create('outbox_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type', 100);
            $table->json('payload');
            $table->string('status', 20)->default(OutboxEventStatus::Pending->value);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            // Used by the outbox processor to pick up pending events in order.
            $table->index(['status', 'created_at']);
            $table->index('type');
        });

        $channels = $this->quoted(NotificationChannel::cases());
        $logStatuses = $this->quoted(NotificationLogStatus::cases());
        $outboxStatuses = $this->quoted(OutboxEventStatus::cases());

        DB::statement('ALTER TABLE reviews ADD CONSTRAINT reviews_rating_check CHECK (rating BETWEEN 1 AND 5)');

        DB::statement("ALTER TABLE notification_templates ADD CONSTRAINT notification_templates_channel_check CHECK (channel IN ({$channels}))");

        DB::statement("ALTER TABLE notification_logs ADD CONSTRAINT notification_logs_channel_check CHECK (channel IN ({$channels}))");
        DB::statement("ALTER TABLE notification_logs ADD CONSTRAINT notification_logs_status_check CHECK (status IN ({$logStatuses}))");

        DB::statement("ALTER TABLE outbox_events ADD CONSTRAINT outbox_events_status_check CHECK (status IN ({$outboxStatuses}))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
        Schema::dropIfExists('integration_mappings');
        Schema::dropIfExists('notification_logs');
        Schema::dropIfExists('notification_templates');
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('faqs');
        Schema::dropIfExists('pages');
    }

    /**
     * @param  array<int, BackedEnum>  $cases
     */
    private function quoted(array $cases): string
    {
        return collect($cases)->map(fn (BackedEnum $case) => "'{$case->value}'")->implode(', ');
    }
};
