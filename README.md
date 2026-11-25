# Undangan Pernikahan Digital

Undangan web (HTML + JS) dengan backend PHP + MySQL untuk **Konfirmasi Kehadiran (RSVP)** dan **Doa & Harapan**, serta halaman admin dengan export Excel.

> Wajib dijalankan lewat server PHP (Laragon/XAMPP atau `php -S`). Live Server VS Code **tidak** menjalankan PHP, sehingga form tidak akan tersimpan.

## Struktur folder

```
├── index.html          Halaman undangan
├── css/  js/           Tampilan & logika front-end (js/script.js memanggil api/)
├── assets/             Gambar, video, musik
├── api/                Endpoint JSON untuk halaman undangan
│   ├── submit-wish.php   POST  simpan ucapan
│   ├── get-wishes.php    GET   ambil ucapan untuk ditampilkan
│   └── submit-rsvp.php   POST  simpan konfirmasi kehadiran
├── admin/              Halaman admin (butuh login)
│   ├── login.php  logout.php  dashboard.php  auth.php
│   ├── export-rsvp.php   Unduh RSVP (.xlsx)
│   ├── export-wishes.php Unduh ucapan (.xlsx)
│   ├── xlsx.php          Pembuat file Excel
│   └── create-admin.php  Buat akun admin (SEKALI, lalu hapus file ini)
├── config/database.php Koneksi database (diblokir dari akses web via .htaccess)
└── database/undangan.sql Skema database (diblokir dari akses web via .htaccess)
```

## Cara menjalankan (lokal)

1. Nyalakan **MySQL** (Laragon: Start All).
2. Import database sekali: `mysql -u root < database/undangan.sql` (atau lewat phpMyAdmin/HeidiSQL).
3. Sesuaikan user/password di `config/database.php` bila perlu.
4. Jalankan server dari folder proyek:
   - `php -S localhost:8080`, lalu buka http://localhost:8080
   - atau taruh folder ini di `C:\laragon\www\` dan buka lewat `http://nama-folder.test`.
5. Buka `/admin/create-admin.php`, buat akun admin, lalu **hapus file tersebut**.
6. Masuk di `/admin/login.php`. Dashboard menampilkan rekap RSVP dan ucapan, masing-masing dengan tombol **Unduh Excel (.xlsx)**.

## Saat online (hosting)

- Import `database/undangan.sql` lewat phpMyAdmin hosting, lalu ubah kredensial di `config/database.php`.
- Pastikan server memakai Apache (agar `.htaccess` memblokir `config/` dan `database/`); untuk Nginx, tambahkan aturan `deny all` untuk folder tersebut.
- Gunakan HTTPS dan hapus `admin/create-admin.php` setelah dipakai.

## Mengubah tampilan (Tailwind CSS lokal)

Halaman undangan memakai file CSS lokal `css/tailwind.css` (bukan CDN), jadi tetap tampil benar
walau internet tamu lambat. File ini **dibangun** dari class Tailwind yang dipakai di `index.php`
dan `js/script.js`.

Jika Anda menambah/mengubah class Tailwind di kedua berkas itu, bangun ulang:

```
npm install        # sekali saja (butuh Node.js)
npm run build:css  # menghasilkan css/tailwind.css
```

`package.json`, `tailwind.config.js`, dan `css/tailwind-input.css` hanya untuk membangun CSS;
tidak perlu diunggah ke hosting.
