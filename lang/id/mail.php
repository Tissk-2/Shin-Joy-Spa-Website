<?php

return [
    'greeting' => 'Halo, :name!',
    'ignore' => 'Jika Anda tidak meminta tindakan ini, abaikan email ini.',
    'verify' => [
        'subject' => 'Verifikasi email Shin Joy Spa Anda',
        'intro' => 'Verifikasikan alamat email Anda untuk mengakses akun.',
        'action' => 'Verifikasi email',
        'expiry' => 'Tautan verifikasi ini kedaluwarsa dalam :minutes menit.',
    ],
    'reset' => [
        'subject' => 'Atur ulang kata sandi Shin Joy Spa Anda',
        'intro' => 'Kami menerima permintaan untuk mengatur ulang kata sandi akun Anda.',
        'action' => 'Atur ulang kata sandi',
        'expiry' => 'Tautan pengaturan ulang ini kedaluwarsa dalam :minutes menit.',
    ],
    'booking' => [
        'approved' => [
            'subject' => 'Reservasi Shin Joy Spa Anda telah dikonfirmasi',
            'intro' => 'Reservasi :reference untuk :service pada :date pukul :time telah dikonfirmasi.',
        ],
        'cancelled' => [
            'subject' => 'Reservasi Shin Joy Spa Anda dibatalkan',
            'intro' => 'Reservasi :reference untuk :service pada :date pukul :time telah dibatalkan.',
            'again' => 'Jika Anda ingin memilih waktu lain, silakan buat reservasi baru.',
        ],
        'expired' => [
            'subject' => 'Permintaan reservasi Shin Joy Spa Anda telah kedaluwarsa',
            'intro' => 'Permintaan reservasi :reference untuk :service pada :date pukul :time tidak sempat kami konfirmasi, sehingga permintaan tersebut kedaluwarsa. Mohon maaf atas ketidaknyamanan ini.',
        ],
        'no_show' => [
            'subject' => 'Janji temu Shin Joy Spa Anda terlewat',
            'intro' => 'Janji temu :reference untuk :service pada :date pukul :time tercatat tidak dihadiri.',
            'contact' => 'Jika menurut Anda ini keliru, silakan hubungi spa.',
        ],
        'pay_at_spa' => 'Pembayaran dilakukan di spa pada hari janji temu Anda.',
        'action' => 'Lihat reservasi Anda',
    ],
];
