<?php

namespace Tests\Unit;

use App\Enums\PricingBasis;
use App\Support\BookingPriceCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BookingPriceCalculatorTest extends TestCase
{
    // Production break caught: per-person packages must multiply the whole base+extension unit by guests.
    public function test_per_person_multiplies_base_and_extension_by_guests(): void
    {
        $prices = new BookingPriceCalculator;

        $this->assertSame(680000, $prices->total(PricingBasis::PerPerson, 265000, 75000, 2, 1, 4));
        $this->assertSame(340000, $prices->total(PricingBasis::PerPerson, 265000, 75000, 1, 1, 4));
        $this->assertSame(1020000, $prices->total(PricingBasis::PerPerson, 265000, 75000, 3, 1, 4));
    }

    // Production break caught: per-booking packages must never multiply the quoted amount by guests.
    public function test_per_booking_keeps_one_total_for_the_whole_guest_coverage(): void
    {
        $prices = new BookingPriceCalculator;

        $this->assertSame(340000, $prices->total(PricingBasis::PerBooking, 265000, 75000, 2, 1, 4));
        $this->assertSame(340000, $prices->total(PricingBasis::PerBooking, 265000, 75000, 4, 1, 4));
        $this->assertSame(340000, $prices->total(PricingBasis::PerBooking, 265000, 75000, 3, 2, 4));
    }

    // Production break caught: an extension rides the package basis rather than a separate pricing rule.
    public function test_extension_amount_inherits_the_package_basis(): void
    {
        $prices = new BookingPriceCalculator;

        $this->assertSame(420000, $prices->total(PricingBasis::PerPerson, 150000, 60000, 2, 1, 4));
        $this->assertSame(210000, $prices->total(PricingBasis::PerBooking, 150000, 60000, 2, 1, 4));
        $this->assertSame(300000, $prices->total(PricingBasis::PerPerson, 150000, 0, 2, 1, 4));
    }

    // Production break caught: explicit guest coverage must reject guests outside the attested range.
    public function test_guest_coverage_outside_the_declared_range_is_rejected(): void
    {
        $prices = new BookingPriceCalculator;

        foreach ([
            [1, 2, 4],
            [5, 1, 4],
            [3, 3, 1],
        ] as [$guests, $min, $max]) {
            try {
                $prices->total(PricingBasis::PerBooking, 100000, 0, $guests, $min, $max);
                $this->fail("Expected guests={$guests} min={$min} max={$max} to be rejected.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // Production break caught: coverage itself must stay within the supported one-to-four guests boundary.
    #[DataProvider('invalidCoverage')]
    public function test_invalid_coverage_boundaries_are_rejected(int $min, int $max): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new BookingPriceCalculator)->total(PricingBasis::PerPerson, 100000, 0, 1, $min, $max);
    }

    /** @return array<string, array{int, int}> */
    public static function invalidCoverage(): array
    {
        return [
            'zero minimum' => [0, 4],
            'five maximum' => [1, 5],
            'minimum above maximum' => [3, 2],
            'negative minimum' => [-1, 4],
        ];
    }

    // Production break caught: negative IDR amounts must never become a quote or a credit.
    public function test_negative_amounts_are_rejected(): void
    {
        $prices = new BookingPriceCalculator;

        foreach ([
            [-1, 0],
            [0, -1],
            [100000, -1],
        ] as [$base, $extension]) {
            try {
                $prices->total(PricingBasis::PerBooking, $base, $extension, 1, 1, 4);
                $this->fail('Expected a negative amount to be rejected.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, $prices->total(PricingBasis::PerBooking, 0, 0, 1, 1, 4));
    }

    // Production break caught: checked IDR arithmetic must not silently overflow into a smaller total.
    public function test_addition_and_person_multiplication_overflow_is_rejected(): void
    {
        $prices = new BookingPriceCalculator;

        try {
            $prices->total(PricingBasis::PerBooking, PHP_INT_MAX, 1, 1, 1, 4);
            $this->fail('Expected addition overflow.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        try {
            $prices->total(PricingBasis::PerPerson, PHP_INT_MAX, 0, 2, 1, 4);
            $this->fail('Expected multiplication overflow.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(PHP_INT_MAX, $prices->total(PricingBasis::PerPerson, PHP_INT_MAX, 0, 1, 1, 4));
    }
}
