<?php

use App\Enums\RateRuleType;
use App\Models\RateRule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Store the price type in its own column and move priorities into the type's band.
     *
     * Until now the type was encoded in the priority (0 base, 10 weekend, 20 seasonal, 30 special
     * dates). A rule with any other priority was not made on the Pricing page; it becomes a weekend
     * price when it covers some days of the week only, otherwise a seasonal price.
     *
     * Within each rate plan and type the rules keep the order in which they won (priority, then
     * latest start, then newest), now as distinct priorities in the band.
     */
    public function up(): void
    {
        Schema::table('rate_rules', function (Blueprint $table) {
            $table->string('type', 20)->nullable()->after('rate_plan_id');
        });

        DB::table('rate_rules')->update(['type' => DB::raw(sprintf(
            "CASE priority WHEN 0 THEN '%s' WHEN 10 THEN '%s' WHEN 20 THEN '%s' WHEN 30 THEN '%s' ELSE IF(days_of_week_mask = %d, '%s', '%s') END",
            RateRuleType::Base->value,
            RateRuleType::Weekend->value,
            RateRuleType::Seasonal->value,
            RateRuleType::SpecialDates->value,
            RateRule::ALL_DAYS,
            RateRuleType::Seasonal->value,
            RateRuleType::Weekend->value,
        ))]);

        DB::table('rate_rules')
            ->orderByDesc('priority')
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get(['id', 'rate_plan_id', 'type'])
            ->groupBy(fn (object $rule): string => $rule->rate_plan_id.'|'.$rule->type)
            ->each(function ($rules): void {
                $type = RateRuleType::from($rules->first()->type);

                foreach ($rules->values() as $position => $rule) {
                    DB::table('rate_rules')->where('id', $rule->id)->update([
                        'priority' => $type === RateRuleType::Base ? 0 : $type->lowestPriority() + $rules->count() - 1 - $position,
                    ]);
                }
            });

        Schema::table('rate_rules', function (Blueprint $table) {
            $table->string('type', 20)->nullable(false)->change();
        });

        $types = collect(RateRuleType::cases())->map(fn (RateRuleType $type) => "'{$type->value}'")->implode(', ');

        DB::statement("ALTER TABLE rate_rules ADD CONSTRAINT rate_rules_type_check CHECK (type IN ({$types}))");
    }

    /**
     * Reverse the migrations.
     *
     * The type goes back into the priority; the order within a type is lost.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE rate_rules DROP CHECK rate_rules_type_check');

        DB::table('rate_rules')->update(['priority' => DB::raw(sprintf(
            "CASE type WHEN '%s' THEN 10 WHEN '%s' THEN 20 WHEN '%s' THEN 30 ELSE 0 END",
            RateRuleType::Weekend->value,
            RateRuleType::Seasonal->value,
            RateRuleType::SpecialDates->value,
        ))]);

        Schema::table('rate_rules', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
