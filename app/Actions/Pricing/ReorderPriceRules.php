<?php

namespace App\Actions\Pricing;

use App\Enums\RateRuleType;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Services\Pricing\PriceRuleOrder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Changes which price wins when prices of the same type cover the same night.
 *
 * The order lists every price of the room type, the one that wins first, e.g. as dragged on
 * the Pricing page. Only the order within each type is taken from it: prices never move to
 * another type's band, so special dates still beat seasons whatever the list says.
 */
class ReorderPriceRules
{
    public function __construct(private readonly PriceRuleOrder $order) {}

    /**
     * @param  list<string>  $ruleIds  every price of the room type, the one that wins first
     *
     * @throws InvalidArgumentException when the ids are not exactly the room type's prices
     */
    public function handle(RoomType $roomType, array $ruleIds): void
    {
        $ratePlan = $roomType->nonRefundableRatePlan()->first()
            ?? throw new InvalidArgumentException("Room type {$roomType->id} has no prices to order.");

        DB::transaction(function () use ($ratePlan, $ruleIds): void {
            RatePlan::query()->whereKey($ratePlan->getKey())->lockForUpdate()->firstOrFail();

            $types = $ratePlan->rateRules()->pluck('type', 'id');

            $unknown = array_diff($ruleIds, $types->keys()->all());

            if ($unknown !== []) {
                throw new InvalidArgumentException('Rate rules '.implode(', ', $unknown)." are not prices of rate plan {$ratePlan->id}.");
            }

            foreach (RateRuleType::cases() as $type) {
                $ofType = array_values(array_filter($ruleIds, fn (string $id): bool => $types[$id] === $type));

                $this->order->apply($ratePlan, $type, $ofType);
            }
        });
    }
}
