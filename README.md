# ShizuFi

Web keuangan pribadi dengan frontend HTML, CSS, JavaScript dan backend PHP + MySQL untuk shared hosting. Tidak memerlukan Node.js / proses build. Struktur: workspace → akun → transaksi + ledger, dan akun investasi → instrumen → transaksi investasi → posisi. Cakupan aktif: fiat dan emas.

## Jalankan

Untuk preview lokal tanpa database: `python -m http.server 4173`, lalu buka http://localhost:4173. Untuk hosting dengan database, ikuti [HOSTINGER.md](HOSTINGER.md). Mode server memerlukan login dan tidak beralih otomatis ke localStorage ketika API gagal.

## Cara menggunakan

1. **+ Akun / emas → Akun / wallet**: buat bank, cash, e-wallet, investment account, credit card, loan atau other. Pilih mata uang dan saldo awal. Utang memakai saldo negatif.
2. **+ Akun / emas → Kepemilikan awal emas**: pilih akun investasi, nama instrumen, berat, kemurnian, dan modal. Saldo awal emas tidak mengurangi kas. Berat 0 mendaftarkan instrumen baru tanpa posisi / modal.
3. **+ Transaksi**: catat pemasukan, pengeluaran, transfer, beli/jual emas, atau koreksi saldo. Buat kategori pemasukan / pengeluaran melalui kolom kategori baru.
4. Transfer satu mata uang mencatat keluar dan masuk dalam satu transaksi. Untuk mata uang berbeda, isi jumlah sumber dan jumlah yang benar-benar diterima; kurs pasar tidak mengubah nominal transaksi historis.
5. Pembelian emas mengurangi kas IDR sebesar nilai bruto + biaya + pajak. Penjualan menambah kas sebesar nilai bruto − biaya − pajak. Instrumen harus terdaftar terlebih dahulu.
6. Edit akun dapat mengubah nama, catatan dan saldo; perubahan saldo membuat entri koreksi. Jenis akun, mata uang dan institusi tidak berubah melalui edit. Arsip tersedia hanya setelah saldo dan seluruh posisi kosong. Riwayat tidak dihapus.
7. Klik riwayat transaksi untuk melihat entri ledger. Ringkasan menampilkan 50 transaksi terbaru; backup berisi seluruh transaksi.

Biaya pembelian termasuk dalam modal. Modal menggunakan rata-rata tertimbang; penjualan sebagian mengeluarkan modal secara proporsional. Penjualan melebihi stok pada tanggal transaksi ditolak. Modal yang tidak diketahui tetap ditandai tidak diketahui dan tidak menghasilkan estimasi laba yang menyesatkan. Catatan diproses sesuai tanggal transaksi, kemudian urutan pencatatan untuk timestamp yang sama. Transaksi tanggal mendatang juga merupakan catatan aktif; tidak ada fitur penjadwalan.

## Penyimpanan

Satu pemilik dan satu workspace personal, base currency IDR, timezone Asia/Jakarta. Di hosting, login email/password memakai session PHP; data disimpan dalam MySQL dan dapat diakses pada perangkat lain setelah login. Tidak ada koneksi bank. Preview localhost tetap lokal tanpa login. Skema dan pemetaan ada di [SCHEMA.md](SCHEMA.md).

MySQL menyimpan tabel relasional dan snapshot JSON backup v2 secara atomik. Nominal transaksi berupa integer minor unit (IDR / JPY tanpa desimal, mata uang lain dua desimal). Berat berupa integer microgram (1 gram = 1.000.000 microgram). Nominal dibulatkan ke minor unit dan berat ke microgram saat input. Harga pasar/valuasi menggunakan angka pecahan JavaScript; bukan mesin settlement NUMERIC(28,12). Preview lokal menggunakan localStorage `shizufi.v2`.

Posisi disimpan sebagai cache materialisasi. Backend memvalidasi seluruh ledger dan membangun ulang posisi setiap save, termasuk pembaruan harga. Saldo akun adalah jumlah entri ledger. Versi server ini melakukan sinkronisasi ulang tabel workspace dalam satu transaksi, dengan batas 10.000 record per tabel dan payload 8 MB; cocok untuk pribadi, bukan jutaan transaksi. Revision lock menolak perubahan dari perangkat yang memegang versi lama. Reload untuk mengambil data terbaru; belum ada realtime push.

Backup v1 dimigrasikan menjadi v2 saat impor. Pada preview lokal, migrasi otomatis dilakukan saat v2 belum tersedia. Pada mode server, impor backup atau gunakan tombol impor data browser setelah login. Fiat menjadi saldo awal akun; emas menjadi kepemilikan awal akun investasi. Kemurnian, catatan, modal dan cache pasar dipertahankan. Timestamp migrasi menjadi tanggal saldo awal karena v1 tidak menyimpan transaksi pembelian. Log aktivitas edit lama tetap berada dalam key v1 dan tidak diubah menjadi transaksi. Key lokal tidak dihapus.

Ekspor backup sebelum memindahkan alamat, membersihkan site data, atau mengganti perangkat. Impor mengganti seluruh workspace setelah konfirmasi. Backup yang ledger-nya tidak seimbang, referensinya rusak, atau penjualannya melebihi stok ditolak. Cache posisi backup dihitung ulang. Jika data lokal gagal dibaca, penyimpanan otomatis berhenti agar data tidak ditimpa.

## Harga emas dan kurs

Setiap dibuka / tombol Perbarui ditekan:

- Harga spot XAU USD / troy ounce: https://api.gold-api.com/price/XAU.
- Kurs harian USD: https://open.er-api.com/v6/latest/USD.
- Harga gram 24K = `USD/oz ÷ 31.1034768 × IDR/USD`.
- Nilai emas = `gram × kemurnian/100 × harga gram 24K`.
- Nilai fiat = `saldo ÷ kurs mata uang per USD × IDR/USD`.

Harga spot bukan retail / buyback Antam atau Pegadaian. Timestamp harga dan kurs ditampilkan. Jika API gagal, cache diberi label; aset tanpa harga ditandai belum dinilai dan total disebut sementara. Harga manual diganti spot saat pembaruan otomatis berhasil. Harga instrumen dan kurs tersimpan terpisah dari transaksi historis. Tidak ada API key.

## Deployment

Repository: https://github.com/catfinder-id/ShizuFi. Hostinger mengambil kode dari branch main melalui auto-deploy. Frontend, cloud.js dan folder api harus ikut deploy. Impor SQL dan buat konfigurasi privat sesuai [HOSTINGER.md](HOSTINGER.md); konfigurasi dan database tidak dikelola melalui Git. Data localhost dipindahkan melalui ekspor/impor backup.

GitHub Pages tidak mendukung PHP dan tidak bisa menjalankan mode database ini. Repository hanya berisi kode/contoh konfigurasi, tidak berisi backup, password MySQL atau session pengguna.

## Verifikasi

`node --check model.js`, `node --check app.js`, `node --check cloud.js`, dan `node --test --test-isolation=none tests/app.test.cjs tests/cloud.test.cjs`. Pemeriksaan PHP dan domain ada di HOSTINGER.md.

Pengujian mencakup presisi minor unit, transfer satu / lintas mata uang, beli/jual emas dengan biaya dan pajak, modal rata-rata dan laba terealisasi, stok negatif / transaksi mundur, koreksi, arsip, integritas backup, migrasi v1, pembaruan pasar, dan valuasi kemurnian. Preview browser memverifikasi migrasi catatan yang ada dan pergantian form transaksi emas.

Dokumentasi sumber: [Gold API](https://gold-api.com/docs), [ExchangeRate-API](https://www.exchangerate-api.com/docs/free), [GitHub Pages](https://docs.github.com/en/pages/getting-started-with-github-pages/creating-a-github-pages-site).
