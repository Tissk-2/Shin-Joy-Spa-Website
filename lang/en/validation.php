<?php

return [
    'accepted' => 'The :attribute field must be accepted.',
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
