<?php

return [
    'availability' => [
        'unavailable' => 'Room availability is temporarily unavailable.',
        'invalid' => 'The selected availability details are invalid.',
        'unsupported' => 'This availability field is not supported.',
        'horizon' => 'Choose a date within the booking period.',
        'integer' => 'Enter a whole number within the allowed range.',
        'date' => 'Enter a valid date in YYYY-MM-DD format.',
        'choice' => 'The selected treatment choice is unavailable.',
    ],
    'accepted' => 'The :attribute field must be accepted.',
    'after_or_equal' => 'The :attribute field must be :date or later.',
    'boolean' => 'The :attribute field must be true or false.',
    'confirmed' => 'The :attribute field confirmation does not match.',
    'current_password' => 'The password is incorrect.',
    'email' => 'The :attribute field must be a valid email address.',
    'in' => 'The selected :attribute is invalid.',
    'lowercase' => 'The :attribute field must be lowercase.',
    'max' => [
        'string' => 'The :attribute field must not be greater than :max characters.',
    ],
    'min' => [
        'string' => 'The :attribute field must be at least :min characters.',
    ],
    'regex' => 'The :attribute field format is invalid.',
    'required' => 'The :attribute field is required.',
    'string' => 'The :attribute field must be a string.',
    'unique' => 'The :attribute has already been taken.',
    'booking' => [
        'availability_unavailable' => 'Room availability cannot be verified right now. Please try again.',
        'slot_unavailable' => 'The selected time is no longer available. Choose another time.',
        'closing_time' => 'Choose a time that lets the treatment finish by 9:00 PM.',
        'future_time' => 'Choose a future time for today’s booking.',
        'price_changed' => 'The treatment price has changed. Refresh the booking page and review the new total before confirming.',
        'pricing_unverified' => 'This package pricing has not been verified. Choose another package or contact us.',
        'pricing_unavailable' => 'Package pricing cannot be verified right now. Please try again.',
        'guest_coverage' => 'The number of guests is outside the range allowed for this package.',
        'today' => 'today',
    ],
    'custom' => [
        'phone' => [
            'regex' => 'The phone number must contain between 8 and 15 digits.',
        ],
        'password' => [
            'regex' => 'The password must contain at least one letter and one number.',
        ],
    ],
    'attributes' => [
        'currentStatus' => 'current status',
        'email' => 'email',
        'locale' => 'locale',
        'name' => 'name',
        'nextStatus' => 'next status',
        'password' => 'password',
        'password_confirmation' => 'password confirmation',
        'phone' => 'phone number',
        'reason' => 'reason',
    ],
];
