<?php

namespace Database\Seeders;

use App\Models\CancellationPolicy;
use Illuminate\Database\Seeder;

class CancellationPolicySeeder extends Seeder
{
    /**
     * Create the Non-Refundable policy template.
     *
     * An existing policy is left untouched, so wording edited by the admin is kept.
     */
    public function run(): void
    {
        CancellationPolicy::firstOrCreate(
            ['name' => CancellationPolicy::NON_REFUNDABLE],
            [
                'rules' => [['refund_percent' => 0]],
                'description' => implode("\n\n", [
                    'This booking is non-refundable.',
                    'Once your reservation is confirmed, the full amount paid will not be refunded if you cancel, change your dates, or do not arrive (no-show). Leaving earlier than your check-out date does not qualify for a partial refund.',
                    'Your request is not a confirmed booking until we have approved it and received your payment. You will not be charged for a request that we decline or that expires before payment.',
                    'If you are unsure about your travel plans, please contact us before paying. We recommend arranging travel insurance that covers cancellation.',
                ]),
            ],
        );
    }
}
