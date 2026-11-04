<?php

return [
    'availability' => [
        'unavailable' => 'Ketersediaan ruangan sementara tidak dapat diperiksa.',
        'invalid' => 'Pilihan untuk pemeriksaan ketersediaan tidak valid.',
        'unsupported' => 'Kolom pemeriksaan ketersediaan ini tidak didukung.',
        'horizon' => 'Pilih tanggal dalam periode pemesanan.',
        'integer' => 'Masukkan bilangan bulat dalam rentang yang diizinkan.',
        'date' => 'Masukkan tanggal valid dalam format YYYY-MM-DD.',
        'choice' => 'Pilihan perawatan tidak tersedia.',
    ],
    'accepted' => ':attribute harus diterima.',
    'after_or_equal' => ':attribute harus :date atau setelahnya.',
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
    'booking' => [
        'availability_unavailable' => 'Ketersediaan ruangan belum dapat diperiksa. Silakan coba lagi.',
        'slot_unavailable' => 'Waktu yang dipilih tidak lagi tersedia. Pilih waktu lain.',
        'closing_time' => 'Pilih waktu agar perawatan selesai paling lambat pukul 21.00.',
        'future_time' => 'Pilih waktu yang belum lewat untuk reservasi hari ini.',
        'price_changed' => 'Harga perawatan berubah. Muat ulang halaman reservasi dan tinjau total baru sebelum mengonfirmasi.',
        'pricing_unverified' => 'Harga paket ini belum diverifikasi. Pilih paket lain atau hubungi kami.',
        'pricing_unavailable' => 'Harga paket belum dapat diverifikasi saat ini. Silakan coba lagi.',
        'guest_coverage' => 'Jumlah tamu di luar rentang yang diizinkan untuk paket ini.',
        'today' => 'hari ini',
    ],
    'custom' => [
        'phone' => [
            'regex' => 'Nomor telepon harus berisi antara 8 dan 15 digit.',
        ],
        'password' => [
            'regex' => 'Kata sandi harus berisi setidaknya satu huruf dan satu angka.',
        ],
    ],
    'attributes' => [
        'currentStatus' => 'Status saat ini',
        'email' => 'Email',
        'locale' => 'Bahasa',
        'name' => 'Nama',
        'nextStatus' => 'Status berikutnya',
        'password' => 'Kata sandi',
        'password_confirmation' => 'Konfirmasi kata sandi',
        'phone' => 'Nomor telepon',
        'reason' => 'Alasan',
    ],
];
