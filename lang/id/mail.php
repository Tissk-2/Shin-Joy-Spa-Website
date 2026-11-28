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
        'received' => [
            'subject' => 'Permintaan reservasi Shin Joy Spa Anda telah diterima',
            'intro' => 'Kami telah menerima permintaan reservasi :reference untuk :service pada :date pukul :time.',
            'next' => 'Reservasi ini belum terkonfirmasi. Kami akan mengirim email lagi setelah staf menyetujui atau menolaknya.',
        ],
        'approved' => [
            'subject' => 'Reservasi Shin Joy Spa Anda telah dikonfirmasi',
            'intro' => 'Reservasi :reference untuk :service pada :date pukul :time telah dikonfirmasi.',
        ],
        'cancelled' => [
            'subject' => 'Reservasi Shin Joy Spa Anda dibatalkan',
            'intro' => 'Reservasi :reference untuk :service pada :date pukul :time telah dibatalkan.',
            'again' => 'Jika Anda ingin memilih waktu lain, silakan buat reservasi baru.',
            'reason' => 'Alasan dari spa: :reason',
        ],
        'expired' => [
            'subject' => 'Permintaan reservasi Shin Joy Spa Anda telah kedaluwarsa',
            'intro' => 'Permintaan reservasi :reference untuk :service pada :date pukul :time tidak sempat kami konfirmasi, sehingga permintaan tersebut kedaluwarsa. Mohon maaf atas ketidaknyamanan ini.',
        ],
        'slot_full' => [
            'subject' => 'Waktu reservasi Shin Joy Spa Anda sudah terisi',
            'intro' => 'Mohon maaf, seluruh ruangan untuk permintaan reservasi :reference (:service pada :date pukul :time) sudah terisi sebelum sempat dikonfirmasi, sehingga permintaan ini dibatalkan.',
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
