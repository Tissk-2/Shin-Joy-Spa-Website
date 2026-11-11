<?php

return [
    'greeting' => 'Hello, :name!',
    'ignore' => 'If you did not request this action, you can ignore this email.',
    'verify' => [
        'subject' => 'Verify your Shin Joy Spa email',
        'intro' => 'Please verify your email address to access your account.',
        'action' => 'Verify email',
        'expiry' => 'This verification link expires in :minutes minutes.',
    ],
    'reset' => [
        'subject' => 'Reset your Shin Joy Spa password',
        'intro' => 'We received a request to reset your account password.',
        'action' => 'Reset password',
        'expiry' => 'This reset link expires in :minutes minutes.',
    ],
    'booking' => [
        'approved' => [
            'subject' => 'Your Shin Joy Spa booking is confirmed',
            'intro' => 'Your booking :reference for :service on :date at :time is confirmed.',
        ],
        'cancelled' => [
            'subject' => 'Your Shin Joy Spa booking was cancelled',
            'intro' => 'Your booking :reference for :service on :date at :time has been cancelled.',
            'again' => 'If you would like another time, you are welcome to make a new booking.',
        ],
        'expired' => [
            'subject' => 'Your Shin Joy Spa booking request has expired',
            'intro' => 'Your booking request :reference for :service on :date at :time was not confirmed in time, so it has expired. We are sorry for the inconvenience.',
        ],
        'no_show' => [
            'subject' => 'Your Shin Joy Spa appointment was missed',
            'intro' => 'Your appointment :reference for :service on :date at :time was recorded as missed.',
            'contact' => 'If you think this is a mistake, please contact the spa.',
        ],
        'pay_at_spa' => 'Payment is made at the spa on the day of your appointment.',
        'action' => 'View your booking',
    ],
];
