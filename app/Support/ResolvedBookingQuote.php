<?php

namespace App\Support;

final readonly class ResolvedBookingQuote
{
    /**
     * @param  array<string, mixed>  $bookingAttributes
     */
    public function __construct(private array $bookingAttributes) {}

    /**
     * @return array<string, mixed>
     */
    public function bookingAttributes(): array
    {
        return $this->bookingAttributes;
    }
}
