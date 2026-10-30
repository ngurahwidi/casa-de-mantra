<?php

namespace Tests\Feature\Services;

use App\Services\Reservation\ReservationCodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationCodeGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private const FORMAT = '/^RSV-[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{8}$/';

    public function test_codes_are_the_prefix_and_eight_unambiguous_upper_case_characters(): void
    {
        $generator = $this->app->make(ReservationCodeGenerator::class);

        for ($i = 0; $i < 200; $i++) {
            $this->assertMatchesRegularExpression(self::FORMAT, $generator->generate());
        }
    }

    public function test_alphabet_has_no_look_alike_characters(): void
    {
        foreach (['0', 'O', '1', 'I', 'L'] as $character) {
            $this->assertStringNotContainsString($character, ReservationCodeGenerator::ALPHABET);
        }
    }

    public function test_codes_are_not_repeated(): void
    {
        $codes = array_map(fn () => ReservationCodeGenerator::random(), range(1, 1000));

        $this->assertCount(1000, array_unique($codes));
    }
}
