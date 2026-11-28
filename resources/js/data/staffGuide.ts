import type { Locale } from "@/types";

export interface GuideLink {
  href: string;
  label: string;
  requiresCreate?: boolean;
}

export interface GuideSection {
  id: string;
  title: string;
  summary: string;
  steps: readonly string[];
  note?: string;
  links?: readonly GuideLink[];
  ownerOnly?: boolean;
}

export interface GuideCopy {
  jumpLabel: string;
  statusHeading: string;
  statusOn: string;
  statusOff: string;
  statusScheduling: string;
  statusEmails: string;
  statusTelegram: string;
  sections: readonly GuideSection[];
}

export const staffGuide: Record<Locale, GuideCopy> = {
  id: {
    jumpLabel: "Bagian panduan",
    statusHeading: "Pengaturan aktif saat ini",
    statusOn: "Aktif",
    statusOff: "Nonaktif",
    statusScheduling: "Penjadwalan ruangan",
    statusEmails: "Email status pelanggan",
    statusTelegram: "Aksi cepat Telegram",
    sections: [
      {
        id: "requests",
        title: "Menanggapi permintaan masuk",
        summary:
          "Permintaan dari situs web belum memesan ruangan sampai staf menyetujuinya.",
        steps: [
          "Pastikan terapis tersedia untuk perawatan yang dipilih. Sistem mengatur ruangan, bukan jadwal terapis.",
          "Buka Hari ini, daftar Reservasi (tab Menunggu), atau pesan di grup Telegram staf untuk menyetujui atau menolak.",
          "Jika menyetujui saat penjadwalan ruangan aktif, centang konfirmasi ketersediaan terapis. Ruangan yang sesuai akan dipesan otomatis.",
          "Jika ruangan terakhir untuk waktu tersebut terisi, permintaan lain yang masih menunggu dan tidak lagi mendapat ruangan akan dibatalkan otomatis oleh sistem dengan email pemberitahuan ke tamu.",
          "Jika menolak dari dasbor, alasan yang Anda ketik akan ditampilkan ke pelanggan di halaman reservasi dan email pembatalan. Tulis dengan bahasa yang sopan dan jelas.",
          "Putuskan sebelum jam janji dimulai. Permintaan yang dibiarkan tanpa keputusan akan kedaluwarsa otomatis saat jam janji tiba.",
        ],
        links: [
          { href: "/admin", label: "Buka Hari ini" },
          {
            href: "/admin/bookings?scope=pending",
            label: "Lihat antrean menunggu",
          },
        ],
      },
      {
        id: "changes",
        title: "Tamu minta batal atau pindah jam",
        summary:
          "Tamu tidak bisa membatalkan atau memindahkan jadwal sendiri di situs web; mereka akan menghubungi spa lewat telepon atau WhatsApp.",
        steps: [
          "Untuk pembatalan: buka detail reservasi, klik Batalkan, lalu catat alasan singkat (misalnya: Sesuai permintaan tamu lewat WhatsApp).",
          "Untuk pindah jam: reservasi tidak bisa digeser langsung. Buat reservasi baru pada jam pengganti, lalu batalkan reservasi lama.",
          "Jika jam baru beririsan dengan reservasi lama saat penjadwalan ruangan aktif, batalkan reservasi lama terlebih dahulu agar ruangannya kembali kosong.",
        ],
        links: [{ href: "/admin/bookings", label: "Cari reservasi" }],
      },
      {
        id: "manual",
        title: "Mencatat tamu walk-in, telepon, dan WhatsApp",
        summary:
          "Masukkan semua reservasi di luar situs web agar jadwal ruangan tetap akurat dan tidak bentrok.",
        steps: [
          "Buka Reservasi baru, pilih sumber (Datang langsung, Telepon, atau WhatsApp), lalu isi nama tamu serta nomor WhatsApp atau email.",
          "Pilih paket, jenis perawatan, durasi, tanggal, dan jam. Daftar ruangan di bawah kalender menampilkan ruangan mana saja yang masih kosong.",
          "Centang langsung setujui bila terapis dan ruangan sudah siap, lalu simpan.",
          "Jika email dan nomor WhatsApp cocok dengan akun tamu yang sudah terverifikasi, reservasi otomatis muncul di akun tamu tersebut.",
          "Jangan pernah memasukkan email atau akun pribadi staf untuk tamu. Bila tamu tidak punya email, beri tahu konfirmasinya lewat telepon atau WhatsApp.",
        ],
        links: [
          {
            href: "/admin/bookings/create",
            label: "Buka Reservasi baru",
            requiresCreate: true,
          },
        ],
      },
      {
        id: "visit",
        title: "Saat tamu datang dan pembayaran",
        summary:
          "Semua pembayaran dilakukan langsung di spa setelah perawatan (tunai, kartu, atau QRIS). Situs web tidak mencatat pembayaran.",
        steps: [
          "Tombol Selesai baru bisa diklik mulai jam janji temu dimulai. Sebelum jamnya tiba, tombol memang terkunci dengan keterangan waktu tunggu.",
          "Tombol Tidak hadir baru bisa diklik setelah durasi perawatan dijadwalkan selesai.",
          "Menandai Tidak hadir akan mengirim email pemberitahuan ke pelanggan bila email status sedang aktif.",
        ],
        links: [{ href: "/admin/schedule", label: "Lihat jadwal hari ini" }],
      },
      {
        id: "issues",
        title: "Kendala yang sering terjadi",
        summary:
          "Langkah cepat jika ada pemberitahuan tertunda atau tombol terasa lambat.",
        steps: [
          "Permintaan terlanjur kedaluwarsa: status kedaluwarsa tidak bisa dibuka lagi. Minta tamu memesan ulang, atau catat lewat Reservasi baru lalu langsung setujui.",
          "Tombol Telegram terasa tidak merespons: balasan Telegram biasanya butuh 5–10 detik. Periksa status terbaru di pesan Telegram atau di daftar Reservasi sebelum menekan ulang.",
          "Pemberitahuan email masih Menunggu dikirim lebih dari 1 menit atau Gagal: kabari pemilik/pengelola server agar memeriksa antrean pengiriman, dan hubungi tamu lewat WhatsApp bila mendesak.",
          "Tamu tidak menerima email: cek bagian Pemberitahuan di detail reservasi. Jika tertulis tamu tidak punya email, hubungi lewat telepon atau WhatsApp.",
        ],
      },
      {
        id: "owner",
        title: "Khusus pemilik",
        summary:
          "Pengaturan akses staf, pemeliharaan ruangan, dan verifikasi harga hanya bisa dilakukan oleh akun pemilik.",
        ownerOnly: true,
        steps: [
          "Izin staf & Telegram: buka halaman Staf untuk memberikan atau mencabut izin pemesanan serta menautkan atau menghapus tautan Telegram. Jika staf berhenti bekerja, cabut izin pemesanan, hapus tautan Telegram, dan keluarkan dari grup Telegram.",
          "Pemeliharaan ruangan: jika ada perbaikan AC atau penutupan sementara, catat di Periode pemeliharaan agar sistem tidak menawarkan ruangan tersebut ke tamu.",
          "Verifikasi harga paket: setiap kali harga atau dasar harga paket diubah di Katalog, status verifikasinya otomatis hilang. Buka paket tersebut lalu klik Verifikasi harga setelah memastikan nominalnya benar.",
        ],
        note: "Setiap reservasi di situs saat ini berlaku untuk 1 orang (1 ruangan). Tamu yang datang bersama teman atau pasangan dapat membuat reservasi terpisah per orang.",
        links: [
          { href: "/admin/staff", label: "Kelola staf" },
          { href: "/admin/rooms", label: "Kelola ruangan" },
          { href: "/admin/catalog", label: "Buka katalog" },
        ],
      },
    ],
  },
  en: {
    jumpLabel: "Guide sections",
    statusHeading: "Active site settings",
    statusOn: "On",
    statusOff: "Off",
    statusScheduling: "Room scheduling",
    statusEmails: "Customer status emails",
    statusTelegram: "Telegram quick actions",
    sections: [
      {
        id: "requests",
        title: "Handling incoming requests",
        summary:
          "Website requests do not reserve a room until staff approve them.",
        steps: [
          "Check therapist availability first. The site schedules rooms, not therapists.",
          "Use Today, the Bookings list (Pending tab), or the Telegram staff group to approve or decline.",
          "When approving with room scheduling on, tick the therapist confirmation. A suitable room is reserved automatically.",
          "If the last suitable room for that time is taken, other pending requests that can no longer fit are automatically cancelled and the guests receive a full-slot notice.",
          "When declining from the dashboard, any reason you write is shown to the guest on their booking page and cancellation email. Keep it polite and clear.",
          "Decide before the start time. Unanswered requests expire automatically when the appointment time arrives.",
        ],
        links: [
          { href: "/admin", label: "Open Today" },
          {
            href: "/admin/bookings?scope=pending",
            label: "View pending queue",
          },
        ],
      },
      {
        id: "changes",
        title: "Cancellations and time changes",
        summary:
          "Guests cannot cancel or move a booking online; they contact the spa by phone or WhatsApp.",
        steps: [
          "To cancel: open the booking detail, choose Cancel, and add a short reason (for example: Guest asked by WhatsApp).",
          "To move a time: bookings cannot be moved in place. Create a new booking for the new time, then cancel the old one.",
          "If the new time overlaps the old booking while room scheduling is on, cancel the old booking first so its room is freed.",
        ],
        links: [{ href: "/admin/bookings", label: "Find a booking" }],
      },
      {
        id: "manual",
        title: "Walk-in, phone, and WhatsApp bookings",
        summary:
          "Record every outside booking in the panel so room availability stays accurate and avoids double bookings.",
        steps: [
          "Open New booking, pick the source (Walk-in, Phone, or WhatsApp), and enter the guest's name plus a phone number or email.",
          "Choose the package, treatment type, duration, date, and time. The room list below the calendar shows which rooms are open.",
          "Tick Approve now if the therapist and room are ready, then save.",
          "If both the email and WhatsApp number match a verified customer account, the booking joins that guest's account automatically.",
          "Never enter a staff member's own email for a guest. If the guest has no email, confirm with them by phone or WhatsApp.",
        ],
        links: [
          {
            href: "/admin/bookings/create",
            label: "Open New booking",
            requiresCreate: true,
          },
        ],
      },
      {
        id: "visit",
        title: "Arrival, completion, and payment",
        summary:
          "Every booking is paid at the spa after treatment (cash, card, or QRIS). The website does not record payments.",
        steps: [
          "Mark completed unlocks once the appointment start time arrives. Until then the button stays disabled with a wait note.",
          "Mark no-show unlocks after the scheduled end of the treatment.",
          "Marking No-show sends a notice email to the guest when customer status emails are on.",
        ],
        links: [{ href: "/admin/schedule", label: "Open today's schedule" }],
      },
      {
        id: "issues",
        title: "Common issues",
        summary: "What to do when a notice is delayed or a button seems slow.",
        steps: [
          "A request expired before anyone answered: expired requests cannot be reopened. Ask the guest to request again, or enter it via New booking and approve immediately.",
          "A Telegram button felt unresponsive: Telegram answers typically take 5–10 seconds. Check the updated Telegram message or the Bookings list before tapping again.",
          "An email stays Waiting to send for more than a minute or shows Failed: let the owner/server maintainer know to check the queue, and message the guest on WhatsApp if urgent.",
          "A guest did not receive an email: check the Notifications section on the booking detail page. If no email was provided, reach out by phone or WhatsApp.",
        ],
      },
      {
        id: "owner",
        title: "Owner tasks",
        summary:
          "Staff permissions, room maintenance periods, and package price verification are owner-only.",
        ownerOnly: true,
        steps: [
          "Staff & Telegram access: use the Staff page to grant or revoke booking management and link or remove Telegram accounts. When a staff member leaves, revoke booking management, remove their Telegram link, and remove them from the Telegram group.",
          "Room maintenance: record temporary closures or repairs under Maintenance periods so guests are not offered unavailable rooms.",
          "Package price verification: editing prices or pricing basis in the Catalog clears verification automatically. Reopen the package and press Verify pricing once you confirm the numbers.",
        ],
        note: "Each booking is currently for 1 guest (1 room). Guests coming with a friend or partner make separate bookings per person.",
        links: [
          { href: "/admin/staff", label: "Manage staff" },
          { href: "/admin/rooms", label: "Manage rooms" },
          { href: "/admin/catalog", label: "Open catalog" },
        ],
      },
    ],
  },
};
