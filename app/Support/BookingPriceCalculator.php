<?php

namespace App\Support;

use App\Enums\PricingBasis;
use InvalidArgumentException;

final class BookingPriceCalculator
{
    public function total(
        PricingBasis $basis,
        int $baseIdr,
        int $extensionIdr,
        int $guests,
        int $minGuests,
        int $maxGuests,
    ): int {
        if ($baseIdr < 0 || $extensionIdr < 0) {
            throw new InvalidArgumentException('negative_amount');
        }

        if ($minGuests < 1 || $maxGuests > 4 || $minGuests > $maxGuests) {
            throw new InvalidArgumentException('invalid_coverage');
        }

        if ($guests < 1 || $guests > 4 || $guests < $minGuests || $guests > $maxGuests) {
            throw new InvalidArgumentException('guest_coverage');
        }

        if ($extensionIdr > PHP_INT_MAX - $baseIdr) {
            throw new InvalidArgumentException('amount_overflow');
        }
        $unitIdr = $baseIdr + $extensionIdr;

        if ($basis === PricingBasis::PerBooking) {
            return $unitIdr;
        }

        if ($unitIdr > intdiv(PHP_INT_MAX, $guests)) {
            throw new InvalidArgumentException('amount_overflow');
        }

        return $unitIdr * $guests;
    }
}
