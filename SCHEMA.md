# Struktur ShizuFi v2

Skema referensi diterapkan sebagai tabel relasional MySQL melalui PHP, dengan snapshot JSON untuk kompatibilitas backup. SQL tersedia di [database/schema.sql](database/schema.sql), aktivasi di [HOSTINGER.md](HOSTINGER.md). Preview lokal masih menggunakan tabel logis JSON browser.

| Bagian | Implementasi saat ini |
|---|---|
| `users`, `workspaces`, `workspace_members` | Satu pemilik login email/password hash, workspace personal IDR, role owner. Identitas impor selalu dipetakan ke pemilik yang login. |
| `accounts` | UUID, workspace, nama, tipe, currency, institution_id, status, note, timestamp. Parent belum digunakan. |
| `institutions` | Entitas terpisah, dibuat dari nama institusi pada form akun dan digunakan kembali bila sama. |
| `transactions` | UUID, workspace, type, timestamp, description, category, source manual, created_by, created_at. |
| `transaction_entries` | UUID, transaction_id, account_id nullable, currency, amount_minor bertanda, offset_type. |
| `categories` | UUID, workspace, nama, income/expense. Pilihan bawaan dan kategori buatan pengguna. Hierarki belum diaktifkan. |
| `instruments` | UUID, gold, XAU / provider_symbol, nama, currency IDR, purity %, note. |
| `investment_transactions` | UUID, FK transaksi utama, workspace, akun investasi, instrumen, akun kas, opening/buy/sell, quantity_micrograms, total_minor, fee_minor, tax_minor, currency, date. |
| `positions` | Cache per pasangan account + instrument: quantity_micrograms, cost_basis_minor, average_cost, realized_pnl_minor, updated_at. ID berupa key gabungan; bukan entitas yang diedit langsung. |
| `market_prices` | instrument_id, harga IDR per gram berat aktual sesuai kemurnian, timestamp, sumber spot/manual. Maksimal 5.000 catatan cache terbaru. |
| `exchange_rates` | Snapshot terakhir kurs USD → tiap mata uang, timestamp, sumber. |
| `currencies` | IDR, USD, EUR, SGD, JPY, MYR, AUD, GBP beserta decimal_places. |
| `market` | Cache kutipan emas murni + kurs untuk valuasi dashboard; terpisah dari ledger. |

## Ledger dan saldo

`amount_minor` positif menambah saldo akun, negatif menguranginya. Setiap transaksi harus berjumlah nol **per mata uang**. Entri dengan account_id null merupakan penyeimbang bernama `equity`, `income`, `expense`, `fx`, atau `investment`; bukan wallet pengguna. Ini merupakan ledger wallet berimbang, belum chart of accounts lengkap untuk laporan akuntansi bisnis.

Contoh transfer 100.000 IDR dari bank ke dompet:

| transaction_id | account_id | currency | amount_minor |
|---|---|---|---:|
| transfer-1 | bank | IDR | -100000 |
| transfer-1 | cash | IDR | 100000 |

Pemasukan mempunyai entri akun positif dan penyeimbang income negatif; pengeluaran sebaliknya. Saldo awal / koreksi memakai penyeimbang equity. Transfer lintas mata uang menambahkan penyeimbang FX pada kedua mata uang agar masing-masing berjumlah nol. Historical amount tidak dihitung ulang mengikuti kurs terkini.

Kepemilikan awal emas tidak mendebit fiat. Transaksi utama mempunyai dua entri penyeimbang nol dan detail investasi berisi gram / modal. Beli/jual mempunyai satu entri kas dan penyeimbang investasi, dengan FK ke detail investasi. Modal rata-rata berasal dari nilai bruto + fee + tax pembelian. Modal tidak diketahui disimpan null; angka nol berarti modal diketahui nol.

`positions` dibaca untuk valuasi; perubahan transaksi membangun ulang posisi secara kronologis. Backend membangun ulang saat setiap save, termasuk harga; posisi dari browser tidak dipercaya. Gram murni untuk valuasi = quantity_micrograms / 1e6 × purity / 100.

MySQL memakai FK gabungan workspace_id + entity_id untuk akun, kategori, instrumen, transaksi dan posisi. workspaces.state_json adalah snapshot untuk membaca aplikasi/backup; tabel relasional merupakan proyeksi yang ditulis dalam transaksi SQL yang sama. users.password_hash terpisah dan tidak pernah masuk snapshot/backup. workspaces.revision mencegah lost update. app_settings mengunci pembuatan satu owner; login_attempts membatasi percobaan autentikasi. Snapshot save menyinkronkan ulang proyeksi workspace, sehingga desain ini belum cocok untuk volume transaksi besar.

## Yang disiapkan sebagai tahap berikutnya

Instrument stock, crypto, deposit, bond, dan lainnya; deposit_details; multiple workspace / anggota; category parent; accounts parent; connections, external_accounts, sync_jobs; external IDs dan kredensial belum diaktifkan. Tidak ada integrasi bank, broker atau exchange yang terhubung. Market-data provider emas dan kurs merupakan satu-satunya integrasi aktif.

Login dan penyimpanan lintas perangkat tersedia setelah konfigurasi PHP/MySQL Hostinger selesai. Pembaruan perangkat lain terlihat setelah reload, bukan realtime push. Jangan menyimpan password_hash atau credentials_ref berisi rahasia di HTML / repository publik.
