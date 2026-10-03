# Aktivasi PHP + MySQL di shared hosting

Frontend tetap HTML/CSS/JavaScript. Backend memerlukan PHP **8.1 atau lebih baru**, ekstensi **PDO MySQL**, MySQL/MariaDB dengan InnoDB, dan HTTPS. Gunakan versi PHP yang masih didukung di hPanel. Deploy Git harus menyalin file PHP ke hosting yang mengeksekusi PHP, bukan hanya menyajikan output build statis.

## 1. Ekspor data yang sudah ada

Sebelum berpindah ke mode server, buka ShizuFi lama pada alamat/browser yang berisi datamu dan klik **Backup**. Simpan JSON secara privat. Backup v1 dan v2 masih dapat diimpor. Deploy tidak memindahkan data browser ke database secara otomatis.

## 2. Buat database dan user MySQL

Di hPanel, buka **Databases → Management / MySQL Databases**, buat database dan pengguna. Catat **nama lengkap** termasuk prefix hPanel, user, host, dan password. Gunakan password database baru bila password lama pernah terlihat di screenshot. Password database berbeda dari password login ShizuFi.

Tidak perlu mengaktifkan remote MySQL publik: PHP mengakses database dari hosting. Host biasanya `localhost`; gunakan nilai yang ditampilkan Hostinger untuk database tersebut.

## 3. Impor skema

Buka **phpMyAdmin** untuk database yang baru dibuat → **Import** → pilih [database/schema.sql](database/schema.sql) dari proyek → jalankan. Gunakan database kosong khusus ShizuFi. File tidak melakukan DROP, tetapi bukan alat migrasi otomatis untuk tabel lama dengan nama/struktur berbeda.

Tabel: currencies, users, app_settings, workspaces, workspace_members, institutions, accounts, categories, instruments, transactions, transaction_entries, investment_transactions, positions, market_prices, exchange_rates, login_attempts.

## 4. Konfigurasi privat

Melalui **File Manager**, buat `shizufi-config.php` pada direktori **induk public_html**, bukan di dalam public_html. Salin format [config.example.php](config.example.php) dan isi langsung di server. Jangan mengunggah konfigurasi berisi password ke GitHub atau chat.

Contoh posisi untuk web pada root domain:

```text
direktori-domain/
├── shizufi-config.php      ← konfigurasi privat, isi sendiri
└── public_html/
    ├── index.html
    ├── cloud.js
    └── api/
        ├── index.php
        ├── domain.php
        └── storage.php
```

Isi konfigurasi:

- `db_host`: host dari Hostinger, biasanya localhost.
- `db_port`: biasanya 3306.
- `db_name` dan `db_user`: nama lengkap dengan prefix hPanel.
- `db_password`: password MySQL baru.
- `setup_token`: kunci instalasi acak minimal 32 karakter, berbeda dari password database/login.
- `secure_cookies`: **true** untuk hosting publik.

Generate kunci instalasi di PowerShell lokal, kemudian salin langsung ke konfigurasi privat (jangan kirim ke chat):

```powershell
$taskRandomBytes = New-Object byte[] 32
[System.Security.Cryptography.RandomNumberGenerator]::Fill($taskRandomBytes)
[Convert]::ToHexString($taskRandomBytes)
```

Perintah ini memerlukan PowerShell modern / .NET modern. Alternatif: gunakan generator password pada password manager untuk kunci acak panjang.

Lokasi default konfigurasi adalah `dirname(__DIR__, 2)/shizufi-config.php` dari `api/index.php`. Jika web ditempatkan pada subfolder public_html, lokasi default akan berbeda; atur environment variable server `SHIZUFI_CONFIG_PATH` ke path absolut file privat, atau sesuaikan default path dalam API ke lokasi di luar public_html. Jangan menaruh file rahasia di folder publik sebagai solusi.

## 5. Deploy dan buat akun pemilik

Pastikan file root, `cloud.js`, folder `api`, serta `.htaccess` ikut dideploy melalui Git. Kode tidak memerlukan Node.js / build. File `config.example.php` hanya contoh, tidak digunakan sebagai konfigurasi runtime.

Buka web menggunakan HTTPS. Ketika database kosong siap, halaman menampilkan **Buat akun pemilik**. Masukkan nama, email, password login (minimal 12 karakter; maksimal 72 byte), dan kunci instalasi dari konfigurasi privat. Pendaftaran publik ditutup setelah satu pemilik dibuat. Kunci instalasi tidak menggantikan password database.

Setelah pemilik berhasil dibuat, kosongkan nilai `setup_token` pada konfigurasi privat (`'setup_token' => ''`). Login berikutnya hanya menggunakan email/password. Jangan menghapus key konfigurasinya. Saat ini belum tersedia reset password melalui email; simpan password login di password manager.

## 6. Migrasi dan uji

- Login → **Impor backup** → pilih backup lama → konfirmasi penggantian workspace server.
- Jika data lama berada pada browser dan domain yang sama, tombol **Impor data dari browser ini** juga tersedia. Salinan lokal tetap disimpan.
- Muat ulang halaman, pastikan akun, saldo dan posisi tetap ada.
- Login dari perangkat lain dan periksa data yang sama.
- Ekspor backup berkala. Auto-deploy kode tidak menghapus database/config privat.

Setiap penyimpanan baru akan terlihat pada perangkat lain setelah halaman perangkat tersebut dimuat ulang. Belum ada pembaruan realtime/push antar perangkat. Jika data berubah dari perangkat lain, penyimpanan ditolak dengan konflik revisi; reload untuk mengambil versi terbaru. Input form dipertahankan saat penyimpanan gagal. Jangan mengimpor backup lama tanpa sengaja karena impor mengganti workspace.

## Penyimpanan dan keamanan

- Session cookie HttpOnly, SameSite Strict, Secure di hosting; timeout tidak aktif satu jam.
- Password di-hash; prepared SQL; CSRF untuk login/setup/perubahan; pembatasan percobaan login.
- Pemilik diperiksa server; backup tidak dapat mengganti identitas akun login.
- Server memvalidasi ledger/FK dan membangun ulang posisi, tidak percaya cache dari browser.
- Penyimpanan workspace menggunakan transaksi database dan revision lock untuk mencegah overwrite lintas perangkat.
- Snapshot JSON tersimpan bersama tabel relasional untuk kompatibilitas backup v2. Versi pribadi ini melakukan sinkronisasi ulang tabel workspace dalam satu transaksi setiap save, cocok untuk data kecil; bukan engine jutaan transaksi.
- Dashboard tidak menyalin data server ke localStorage. Data hanya tersimpan di memori halaman dan file backup yang kamu ekspor. Koneksi gagal tidak beralih diam-diam ke mode lokal.
- Market price tetap diperbarui di browser saat login / membuka halaman. Auto-deploy memperbarui kode, tidak menyimpan transaksi pribadi ke GitHub.

## Troubleshooting

**Backend belum dikonfigurasi:** periksa lokasi file privat. **Database gagal diakses:** periksa nama/user/password/host, PDO MySQL dan impor SQL. **API PHP belum tersedia:** pastikan hosting mengeksekusi PHP dan folder api ikut deploy. **Login berulang / CSRF:** gunakan HTTPS dan izinkan cookie first-party. Detail SQL/kredensial tidak dikirimkan ke browser.

GitHub Pages tidak menjalankan PHP. Versi hosted database harus dijalankan pada Hostinger atau server PHP lain. Preview localhost tetap memakai mode lokal; tambahkan `?backend=php` untuk menguji API PHP secara lokal.

## Verifikasi pengembangan

```text
node --test --test-isolation=none tests/app.test.cjs tests/cloud.test.cjs
php -l api/index.php
php -l api/domain.php
php -l api/storage.php
node tests/make-php-fixture.cjs PATH_TEMP_FIXTURE.json
php tests/domain.test.php PATH_TEMP_FIXTURE.json
```

Jangan commit fixture / backup yang memuat data keuangan. Pengujian domain PHP tidak menggantikan uji koneksi MySQL dan alur login di Hostinger setelah konfigurasi dibuat.
