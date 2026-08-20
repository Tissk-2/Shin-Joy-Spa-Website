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
];
