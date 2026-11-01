<?php

namespace App\Services\Pricing;

use App\Enums\RateRuleType;
use App\Models\RatePlan;
use App\Models\RateRule;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use LogicException;

/**
 * Keeps the priorities of a rate plan's rules inside their type's band (RateRuleType).
 *
 * Within a type, a higher priority wins; the numbers have no meaning beyond that order.
 * Callers lock the rate plan row first, so two admins cannot hand out the same priority.
 */
class PriceRuleOrder
{
    /**
     * The priority that puts a new rule above every rule of its type.
     *
     * When the top of the band is taken, the band is renumbered from its lowest priority first.
     *
     * @throws LogicException when the band is full
     */
    public function top(RatePlan $ratePlan, RateRuleType $type): int
    {
        if ($type === RateRuleType::Base) {
            return RateRuleType::Base->lowestPriority();
        }

        $rules = $this->rulesOf($ratePlan, $type);

        if ($rules->isEmpty()) {
            return $type->lowestPriority();
        }

        if ($rules->count() >= RateRuleType::BAND_SIZE) {
            throw new LogicException("Rate plan {$ratePlan->id} cannot hold more {$type->value} prices.");
        }

        if ($rules->first()->priority < $type->highestPriority()) {
            return $rules->first()->priority + 1;
        }

        $this->apply($ratePlan, $type, $rules->modelKeys());

        return $type->lowestPriority() + $rules->count();
    }

    /**
     * Sets the order of all rules of one type.
     *
     * @param  list<string>  $ruleIds  every rule of the type, the one that wins first
     *
     * @throws InvalidArgumentException when the ids are not exactly the rules of the type
     */
    public function apply(RatePlan $ratePlan, RateRuleType $type, array $ruleIds): void
    {
        $current = $this->rulesOf($ratePlan, $type)->keyBy(fn (RateRule $rule): string => $rule->getKey());

        $listed = array_unique($ruleIds);

        if (count($listed) !== count($ruleIds) || count($listed) !== $current->count() || array_diff($listed, $current->keys()->all()) !== []) {
            throw new InvalidArgumentException("The order must list every {$type->value} price of rate plan {$ratePlan->id} once.");
        }

        if ($type === RateRuleType::Base) {
            return;
        }

        foreach ($ruleIds as $position => $id) {
            $rule = $current[$id];
            $rule->priority = $type->lowestPriority() + count($ruleIds) - 1 - $position;
            $rule->save();
        }
    }

    /**
     * @return Collection<int, RateRule> highest priority first
     */
    private function rulesOf(RatePlan $ratePlan, RateRuleType $type): Collection
    {
        return $ratePlan->rateRules()
            ->where('type', $type)
            ->orderByDesc('priority')
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get();
    }
}
