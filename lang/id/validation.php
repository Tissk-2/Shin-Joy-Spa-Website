<?php

return [
    'accepted' => ':attribute harus diterima.',
    'boolean' => ':attribute harus bernilai benar atau salah.',
    'confirmed' => 'Konfirmasi :attribute tidak cocok.',
    'current_password' => 'Kata sandi salah.',
    'email' => ':attribute harus berupa alamat email yang valid.',
    'in' => ':attribute yang dipilih tidak valid.',
    'lowercase' => ':attribute harus menggunakan huruf kecil.',
    'max' => [
        'string' => ':attribute tidak boleh lebih dari :max karakter.',
    ],
    'min' => [
        'string' => ':attribute minimal harus berisi :min karakter.',
    ],
    'regex' => 'Format :attribute tidak valid.',
    'required' => ':attribute wajib diisi.',
    'string' => ':attribute harus berupa teks.',
    'unique' => ':attribute sudah digunakan.',
    'custom' => [
        'phone' => [
            'regex' => 'Nomor telepon harus berisi antara 8 dan 15 digit.',
        ],
        'password' => [
            'regex' => 'Kata sandi harus berisi setidaknya satu huruf dan satu angka.',
        ],
    ],
    'attributes' => [
        'email' => 'Email',
        'locale' => 'Bahasa',
        'name' => 'Nama',
        'password' => 'Kata sandi',
        'password_confirmation' => 'Konfirmasi kata sandi',
        'phone' => 'Nomor telepon',
    ],
];
