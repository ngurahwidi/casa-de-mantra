<?php

namespace App\Actions\Pricing;

use App\Data\Pricing\PriceRuleData;
use App\Enums\RateRuleType;
use App\Models\PropertyProfile;
use App\Models\RatePlan;
use App\Models\RateRule;
use App\Models\RoomType;
use App\Services\Pricing\PriceRuleOrder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Adds or changes a weekend, seasonal or special-dates price of a room type.
 *
 * The rule's type fixes its priority band, so on a night covered by several prices special
 * dates win over a season, a season over the weekend price, and all of them over the base
 * price. A new rule goes to the top of its type and so wins over older rules of the same
 * type; ReorderPriceRules changes that order. Editing keeps the rule's type and position.
 *
 * A weekend price applies from the day it is added, with no end date. The room type needs a
 * base price first (SetBasePrice), which creates the rate plan the rule belongs to.
 */
class SavePriceRule
{
    public function __construct(private readonly PriceRuleOrder $order) {}

    /**
     * @param  RateRule|null  $rule  the rule to change; null adds a new one
     *
     * @throws LogicException when the room type has no base price yet
     * @throws InvalidArgumentException when the data is incomplete or does not fit the rule
     */
    public function handle(RoomType $roomType, PriceRuleData $data, ?RateRule $rule = null): RateRule
    {
        $ratePlan = $roomType->nonRefundableRatePlan()->first()
            ?? throw new LogicException("Room type {$roomType->id} has no base price yet.");

        if ($rule !== null && ($rule->rate_plan_id !== $ratePlan->id || $rule->type !== $data->type)) {
            throw new InvalidArgumentException("Rate rule {$rule->id} is not a {$data->type->value} price of room type {$roomType->id}.");
        }

        $this->validate($data);

        return DB::transaction(function () use ($ratePlan, $data, $rule): RateRule {
            // Serialises priority assignment with other saves and reorders of this rate plan.
            RatePlan::query()->whereKey($ratePlan->getKey())->lockForUpdate()->firstOrFail();

            $rule ??= $ratePlan->rateRules()->make([
                'type' => $data->type,
                'priority' => $this->order->top($ratePlan, $data->type),
            ]);

            $rule->fill([
                'name' => trim($data->name),
                'start_date' => $data->type === RateRuleType::Weekend
                    ? ($rule->exists ? $rule->start_date->toDateString() : now(PropertyProfile::current()->timezone)->toDateString())
                    : $data->firstNight?->toDateString(),
                'end_date' => $data->lastNight?->toDateString(),
                'price_per_night' => $data->pricePerNight,
                'days_of_week_mask' => $data->daysOfWeekMask,
                'min_stay_override' => $data->minStay,
            ]);

            $rule->save();

            return $rule;
        });
    }

    private function validate(PriceRuleData $data): void
    {
        if ($data->type === RateRuleType::Base) {
            throw new InvalidArgumentException('Set the base price with SetBasePrice.');
        }

        if (trim($data->name) === '') {
            throw new InvalidArgumentException('A price needs a name.');
        }

        if (! is_numeric($data->pricePerNight) || $data->pricePerNight < 0) {
            throw new InvalidArgumentException("Price {$data->pricePerNight} must be a non-negative number.");
        }

        if ($data->daysOfWeekMask < 1 || $data->daysOfWeekMask > RateRule::ALL_DAYS) {
            throw new InvalidArgumentException('A price needs at least one day of the week.');
        }

        if ($data->minStay !== null && $data->minStay < 1) {
            throw new InvalidArgumentException('The minimum stay is at least one night.');
        }

        if ($data->type !== RateRuleType::Weekend) {
            if ($data->firstNight === null || $data->lastNight === null) {
                throw new InvalidArgumentException('A seasonal or special-dates price needs its first and last night.');
            }

            if ($data->lastNight->toDateString() < $data->firstNight->toDateString()) {
                throw new InvalidArgumentException('The last night cannot be before the first night.');
            }
        }
    }
}
