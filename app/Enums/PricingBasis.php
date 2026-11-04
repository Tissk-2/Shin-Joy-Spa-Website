<?php

namespace App\Enums;

enum PricingBasis: string
{
    case PerPerson = 'per_person';
    case PerBooking = 'per_booking';
}
