<?php

namespace Tests\Feature\Actions;

use App\Actions\Availability\DeleteManualBlock;
use App\Enums\AvailabilityBlockSource;
use App\Models\AvailabilityBlock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeleteManualBlockTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{AvailabilityBlockSource}>
     */
    public static function sourcesEnteredByHand(): array
    {
        return [
            'manual' => [AvailabilityBlockSource::Manual],
            'ota manual' => [AvailabilityBlockSource::OtaManual],
        ];
    }

    #[DataProvider('sourcesEnteredByHand')]
    public function test_removes_a_block_entered_by_hand(AvailabilityBlockSource $source): void
    {
        $block = AvailabilityBlock::factory()->create(['source' => $source]);

        $this->app->make(DeleteManualBlock::class)->handle($block);

        $this->assertModelMissing($block);
    }
}
