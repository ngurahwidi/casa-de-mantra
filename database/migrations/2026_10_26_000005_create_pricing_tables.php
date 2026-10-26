<?php

use App\Enums\MealPlan;
use App\Enums\TaxFeeAppliesTo;
use App\Enums\TaxFeeType;
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
        Schema::create('cancellation_policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 191)->unique();
            $table->json('rules');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('rate_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('room_type_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('cancellation_policy_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('meal_plan', 20)->default(MealPlan::RoomOnly->value);
            $table->unsignedSmallInteger('min_stay')->default(1);
            $table->unsignedSmallInteger('max_stay')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('room_type_id');
            $table->index('cancellation_policy_id');
        });

        Schema::create('rate_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('rate_plan_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            // Monday = 1 ... Sunday = 64, every day = 127.
            $table->unsignedTinyInteger('days_of_week_mask')->default(127);
            $table->decimal('price_per_night', 14, 2);
            $table->unsignedSmallInteger('min_stay_override')->nullable();
            $table->unsignedInteger('priority')->default(0);
            $table->timestamps();

            // Also serves as the index for the rate_plan_id foreign key.
            $table->index(['rate_plan_id', 'start_date', 'end_date']);
        });

        Schema::create('tax_fees', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('type', 20);
            $table->decimal('value', 14, 2);
            $table->boolean('is_included_in_price')->default(false);
            $table->string('applies_to', 20)->default(TaxFeeAppliesTo::PerNight->value);
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        $mealPlans = $this->quoted(MealPlan::cases());
        $types = $this->quoted(TaxFeeType::cases());
        $appliesTo = $this->quoted(TaxFeeAppliesTo::cases());

        DB::statement("ALTER TABLE rate_plans ADD CONSTRAINT rate_plans_meal_plan_check CHECK (meal_plan IN ({$mealPlans}))");
        DB::statement('ALTER TABLE rate_plans ADD CONSTRAINT rate_plans_min_stay_check CHECK (min_stay >= 1)');
        DB::statement('ALTER TABLE rate_plans ADD CONSTRAINT rate_plans_max_stay_check CHECK (max_stay IS NULL OR max_stay >= min_stay)');

        DB::statement('ALTER TABLE rate_rules ADD CONSTRAINT rate_rules_date_range_check CHECK (end_date IS NULL OR end_date >= start_date)');
        DB::statement('ALTER TABLE rate_rules ADD CONSTRAINT rate_rules_days_of_week_mask_check CHECK (days_of_week_mask BETWEEN 1 AND 127)');
        DB::statement('ALTER TABLE rate_rules ADD CONSTRAINT rate_rules_price_check CHECK (price_per_night >= 0)');
        DB::statement('ALTER TABLE rate_rules ADD CONSTRAINT rate_rules_min_stay_override_check CHECK (min_stay_override IS NULL OR min_stay_override >= 1)');

        DB::statement("ALTER TABLE tax_fees ADD CONSTRAINT tax_fees_type_check CHECK (type IN ({$types}))");
        DB::statement("ALTER TABLE tax_fees ADD CONSTRAINT tax_fees_applies_to_check CHECK (applies_to IN ({$appliesTo}))");
        DB::statement("ALTER TABLE tax_fees ADD CONSTRAINT tax_fees_value_check CHECK (value >= 0 AND (type <> 'percent' OR value <= 100))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tax_fees');
        Schema::dropIfExists('rate_rules');
        Schema::dropIfExists('rate_plans');
        Schema::dropIfExists('cancellation_policies');
    }

    /**
     * @param  array<int, BackedEnum>  $cases
     */
    private function quoted(array $cases): string
    {
        return collect($cases)->map(fn (BackedEnum $case) => "'{$case->value}'")->implode(', ');
    }
};
