# ShizuFi

Web keuangan pribadi berbasis HTML, CSS, dan JavaScript, tanpa PHP / proses build. Versi ini mengikuti struktur workspace → akun → transaksi + ledger, dan akun investasi → instrumen → transaksi investasi → posisi. Cakupan aktif: fiat dan emas.

## Jalankan

`python -m http.server 4173`, lalu buka http://localhost:4173. Tetap gunakan alamat/origin yang sama karena penyimpanan terpisah per browser dan origin.

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

Satu pemilik lokal dan satu workspace personal, base currency IDR, timezone Asia/Jakarta. Tidak ada autentikasi / password, server database, sinkronisasi perangkat, atau koneksi bank. `users` lokal berfungsi sebagai identitas pencatat; bukan akun login. Skema relasi dan pemetaan ada di [SCHEMA.md](SCHEMA.md).

Data menggunakan localStorage `shizufi.v2` dalam bentuk tabel logis JSON. Nominal transaksi berupa integer minor unit (IDR / JPY tanpa desimal, mata uang lain dua desimal). Berat berupa integer microgram (1 gram = 1.000.000 microgram). Nominal dibulatkan ke minor unit dan berat ke microgram saat input. Harga pasar dan estimasi valuasi menggunakan angka pecahan JavaScript; bukan mesin settlement NUMERIC(28,12).

Posisi disimpan sebagai cache materialisasi, dihitung ulang saat perubahan transaksi dan impor / pemuatan untuk menjaga integritas. Pembaruan harga tidak menghitung ulang posisi. Saldo akun merupakan jumlah entri ledger. Penyimpanan lokal ini dibatasi untuk penggunaan pribadi, bukan jutaan transaksi.

Backup v1 otomatis dimigrasikan saat v2 belum tersedia. Fiat menjadi saldo awal akun; emas menjadi kepemilikan awal akun investasi. Kemurnian, catatan, modal dan cache pasar dipertahankan. Timestamp migrasi menjadi tanggal saldo awal karena v1 tidak menyimpan transaksi pembelian. Log aktivitas edit lama tetap berada dalam data v1 dan tidak diubah menjadi transaksi. Key v1 tidak dihapus.

Ekspor backup sebelum memindahkan alamat, membersihkan site data, atau mengganti perangkat. Impor mengganti seluruh workspace setelah konfirmasi. Backup yang ledger-nya tidak seimbang, referensinya rusak, atau penjualannya melebihi stok ditolak. Cache posisi backup dihitung ulang. Jika data lokal gagal dibaca, penyimpanan otomatis berhenti agar data tidak ditimpa.

## Harga emas dan kurs

Setiap dibuka / tombol Perbarui ditekan:

- Harga spot XAU USD / troy ounce: https://api.gold-api.com/price/XAU.
- Kurs harian USD: https://open.er-api.com/v6/latest/USD.
- Harga gram 24K = `USD/oz ÷ 31.1034768 × IDR/USD`.
- Nilai emas = `gram × kemurnian/100 × harga gram 24K`.
- Nilai fiat = `saldo ÷ kurs mata uang per USD × IDR/USD`.

Harga spot bukan retail / buyback Antam atau Pegadaian. Timestamp harga dan kurs ditampilkan. Jika API gagal, cache diberi label; aset tanpa harga ditandai belum dinilai dan total disebut sementara. Harga manual diganti spot saat pembaruan otomatis berhasil. Harga instrumen dan kurs tersimpan terpisah dari transaksi historis. Tidak ada API key.

## GitHub Pages

1. Buat repository **public**, misalnya ShizuFi.
2. Upload `index.html`, `style.css`, `model.js`, `app.js`, `favicon.svg`, `.nojekyll` ke root branch main. Jangan upload backup keuangan.
3. **Settings → Pages → Build and deployment → Deploy from a branch → main → /(root) → Save**.
4. Buka `https://USERNAME.github.io/ShizuFi/` setelah deployment selesai.

Repository tujuan: https://github.com/catfinder-id/ShizuFi. Setelah GitHub Pages aktif, alamat webnya https://catfinder-id.github.io/ShizuFi/. File web publik tidak menyertakan data keuangan lokal. Siapa pun yang memiliki akses ke profil browser dapat membaca data lokal. Data localhost tidak otomatis berpindah ke alamat GitHub Pages; gunakan ekspor / impor backup.

## Verifikasi

`node --check model.js`, `node --check app.js`, dan `node --test --test-isolation=none tests/app.test.cjs`.

Pengujian mencakup presisi minor unit, transfer satu / lintas mata uang, beli/jual emas dengan biaya dan pajak, modal rata-rata dan laba terealisasi, stok negatif / transaksi mundur, koreksi, arsip, integritas backup, migrasi v1, pembaruan pasar, dan valuasi kemurnian. Preview browser memverifikasi migrasi catatan yang ada dan pergantian form transaksi emas.

Dokumentasi sumber: [Gold API](https://gold-api.com/docs), [ExchangeRate-API](https://www.exchangerate-api.com/docs/free), [GitHub Pages](https://docs.github.com/en/pages/getting-started-with-github-pages/creating-a-github-pages-site).
