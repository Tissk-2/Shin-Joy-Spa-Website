<?php

use Laravel\Fortify\Features;

return [
    'guard' => 'web',
    'passwords' => 'users',
    'username' => 'email',
    'email' => 'email',
    'lowercase_usernames' => true,
    'home' => '/dashboard',
    'prefix' => '',
    'domain' => null,
    'middleware' => ['web'],
    'auth_middleware' => 'auth',
    'limiters' => [
        'login' => 'login',
        'verification' => '6,1',
    ],
    'views' => true,
    'paths' => [
        'login' => '/login',
        'logout' => '/logout',
        'password' => [
            'request' => '/forgot-password',
            'reset' => '/reset-password/{token}',
            'email' => '/forgot-password',
            'update' => '/reset-password',
            'confirm' => '/confirm-password',
            'confirmation' => '/confirmed-password-status',
        ],
        'register' => '/register',
        'verification' => [
            'notice' => '/verify-email',
            'verify' => '/verify-email/{id}/{hash}',
            'send' => '/email/verification-notification',
        ],
    ],
    'redirects' => [
        'login' => '/dashboard',
        'logout' => '/',
        'password-confirmation' => '/dashboard',
        'register' => '/verify-email',
        'email-verification' => '/dashboard',
        'password-reset' => '/login',
    ],
    'features' => [
        Features::registration(),
        Features::resetPasswords(),
        Features::emailVerification(),
    ],
];
