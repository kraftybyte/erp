# Bot Telegram: Input Expense & Advance

Karyawan bisa mengajukan expense dan advance lewat chat Telegram:

```
User: [foto struk]
Bot : 📎 Struk diterima. Sekarang tulis keterangannya…
User: expense 150rb grab ke kantor klien nestory
Bot : 📝 Draft Expense
      Tanggal: 25 Okt 2026 · Project: PT Nestory Indonesia | Video Profile
      Kategori: Transport · Nominal: Rp 150.000 · Lampiran: 📎 foto struk
      [✅ Kirim] [✏️ Nominal] [✏️ Keterangan] [📁 Project] [🏷 Kategori] ...
```

Belum ada yang tersimpan sampai user menekan **Kirim**. Setelah itu record dibuat
dengan status `Pending`, persis seperti dari form, sehingga notifikasi ke Finance
dan Director tetap jalan. Saat statusnya berubah (disetujui, ditolak, dibayarkan,
selesai), pengaju juga mendapat kabar di Telegram.

### Struk / invoice

- Boleh dikirim lebih dulu (lalu keterangan), bersama caption, atau menyusul
  setelah draft jadi. Foto, JPG, PNG, WebP, atau PDF, maksimal 5 MB. Dari album,
  hanya foto pertama yang dipakai.
- Struk baru diunduh ke ERP saat **Kirim**, ke `storage/app/public/expense/invoice/…`
  atau `advance/attachment/…`. Jenis file diperiksa dari isinya, bukan dari label
  Telegram, karena file ini bisa dibuka lewat link publik.
- Expense dengan struk otomatis mendapat 1 item pembelian (keterangan + nominal),
  karena tombol "Upload Invoice Pembelian" di ERP — yang biasanya mengisi item —
  hilang begitu lampiran ada. Setelah disetujui, Finance bisa langsung membayar.
- Bila Gemini aktif, isi struk ikut dibaca: total (hanya bila pesan tidak
  menyebut nominal), tanggal, dan nama toko.

### Realisasi advance

```
User: [foto struk]  realisasi 350rb sewa lighting nestory
Bot : 🧾 Draft Realisasi
      Advance: Advance Video Profile — Rp 2.000.000
      Project: PT Nestory Indonesia | Video Profile · PIC Budi Santoso
      Item: Sewa lighting · Rp 350.000 · Lampiran: 📎 foto struk
      Sisa advance: Rp 2.000.000 → Rp 1.650.000
```

- Kata `realisasi`/`lpj`/`pertanggungjawaban`, atau perintah `/realisasi`.
- Hanya advance **milik pengirim** berstatus Dibayarkan / Sedang di-Realisasi User
  yang ditawarkan. Dicocokkan dari project di pesan; bila beberapa cocok, bot
  menampilkan daftar bernomor lengkap dengan PIC dan sisa dananya.
- Satu struk = satu batch (satu item) lewat `RealisasiService::createBatch`, jadi
  aturan ERP tetap berlaku: batch pertama mengubah status advance dan memberi
  tahu Finance; overrun yang belum disetujui menahan realisasi berikutnya. File
  disimpan di `realisasi/{advance_id}/` seperti unggahan dari halaman realisasi.
- **Cegah klaim ganda:** pesan bernominal tanpa kata expense untuk project yang
  masih punya advance terbuka ditanyakan dulu ("dibayar dari advance itu?").
  Expense yang ditulis jelas tetap diberi peringatan dan tombol "Jadikan realisasi".
- `/status` menampilkan progres setiap advance terbuka.

### Banyak struk sekaligus

Kirim beberapa foto sebagai **album** dengan satu caption, atau kirim foto
susulan ke draft yang sedang disusun.

```
User: [album 3 foto] realisasi nestory
Bot : (membalas foto 1) Struk 1 ☝️ — berapa nominalnya?
User: 120rb sewa lighting
Bot : (membalas foto 2) Struk 2 ☝️ — berapa nominalnya?
…
Bot : 🧾 Draft Realisasi — 3 struk
      1. 📎 Sewa lighting — Rp 120.000
      2. 📎 Konsumsi kru — Rp 85.000
      3. 📎 Parkir — Rp 15.000
      Total: Rp 220.000 · Sisa advance: Rp 2.000.000 → Rp 1.780.000
      [✅ Kirim 3 struk] [✏️ 1][✏️ 2][✏️ 3] [🗑 1][🗑 2][🗑 3]
```

- **Realisasi**: 1 batch, satu item per struk, dan setiap item menyimpan
  strukny sendiri (bukan semua file di item pertama).
- **Expense**: satu pengajuan per struk, karena kolom lampiran expense hanya
  muat satu file.
- **Advance**: satu pengajuan dengan semua lampirannya (penawaran, referensi,
  foto barang). Lampiran hanya pendukung, jadi nominal tidak ditanyakan per
  gambar; cukup nominal advance. Disimpan di kolom `advances.attachments`
  (kolom `attachment` tetap berisi lampiran pertama) dan tampil sebagai
  tombol Lampiran 1…n di halaman detail advance.

Pengaman supaya tidak salah masuk:

- Nominal ditanyakan per struk sambil **membalas foto yang dimaksud**. Bila
  Gemini aktif, nominal tiap struk dibaca otomatis dan tetap tampil untuk diperiksa.
- Nominal di caption album dianggap **total**; bila berbeda dengan jumlah per
  struk, ringkasan memberi peringatan. Pada foto susulan, nominal yang sudah
  ditulis tetap milik struk pertama.
- Struk yang **persis sama** (dikirim ulang/di-forward) ditolak, di draft yang
  sama maupun bila pernah dikirim sebelumnya. Catatannya di tabel
  `telegram_receipts` (bukan cache, supaya tidak hilang saat deploy), dicatat
  dalam transaksi yang sama dengan pengajuannya.
- Bila satu struk gagal diunduh saat Kirim, struk lain yang sudah terunggah
  dihapus lagi dan tidak ada yang tersimpan.

### PIC

Project satu klien sering hanya beda di PIC-nya. Bila beberapa project cocok,
bot menampilkan daftar bernomor dengan PIC (dari project, atau dari Deal bila
kosong). Nama PIC di pesan ("nestory pak budi") ikut memilih project, tapi hanya
di antara project yang nama kliennya sudah cocok.

Perintah bot: `/expense`, `/advance`, `/status` (5 pengajuan terakhir), `/batal`, `/bantuan`.

---

## Setup di server (sekali)

### 1. Buat bot

1. Di Telegram, buka **@BotFather** (yang bercentang biru), kirim `/newbot`.
2. Isi nama tampilan (mis. `ERP Krafty`) dan username yang diakhiri `bot`.
3. Simpan token yang diberikan. Token ini memberi kendali penuh atas bot.
4. Kirim `/setjoingroups` → pilih bot → **Disable**, supaya bot tidak bisa
   dimasukkan ke grup.

Menu perintah tidak perlu diatur di BotFather; ERP mendaftarkannya otomatis.

### 2. Deploy

```bash
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force      # kolom telegram_chat_id di users + settings Integrasi
php artisan config:cache
php artisan route:cache
```

Pastikan `APP_URL` di `.env` adalah alamat `https://` yang publik; webhook
didaftarkan ke `<APP_URL>/api/telegram/webhook`.

### 3. Isi Settings → Integrasi

Masuk sebagai super_admin, buka **Settings → Integrasi**:

1. **Gemini AI**: tempel API key dari aistudio.google.com, biarkan model
   `gemini-3.5-flash-lite`, lalu tekan **Tes koneksi**.
2. **Bot Telegram**: tempel token dari BotFather, lalu **Simpan**. Webhook dan
   secret-nya langsung didaftarkan; kolom **Status** harus menunjukkan
   *Webhook: ✅ Aktif*.

Semua kunci disimpan terenkripsi (dengan `APP_KEY`) dan tidak pernah dikirim
balik ke browser: kolomnya tampil kosong dengan keterangan "Tersimpan (…xxxx)".
Membiarkan kolom kosong saat menyimpan berarti kuncinya tidak diubah.

Halaman ini hanya terbuka untuk super_admin. Role lain bisa diberi akses lewat
permission `page_ManageIntegrations` di Shield.

Variabel `.env` (`GEMINI_API_KEY`, `TELEGRAM_BOT_TOKEN`, dst.) masih dibaca
sebagai cadangan bila isian di Settings kosong, dan disalin ke Settings saat
migration pertama kali dijalankan.

Lewat SSH, padanan tombol di halaman ini adalah:

```bash
php artisan telegram:webhook          # daftarkan ulang
php artisan telegram:webhook info     # status
php artisan telegram:webhook delete   # matikan bot
```

### 4. Hubungkan akun

Setiap karyawan: **ERP → Profil → Telegram → Hubungkan Telegram → Buka Telegram → Start**.
Link berlaku 15 menit dan hanya bisa dipakai sekali. Hanya akun yang punya izin
`create_expense` / `create_advance` yang bisa mengajukan jenis tersebut.

---

## AI (Gemini)

Pesan dibaca oleh dua pembaca sekaligus:

- **Pembaca aturan** (selalu jalan, tanpa biaya): nominal (`150rb`, `1,5jt`,
  `Rp 150.000`), jenis (kata advance/kasbon/uang muka; selain itu pesan
  bernominal dianggap expense), kategori dari kata kunci
  (`App\Services\Telegram\CategoryGuesser`: grab/bensin/tol → Transport,
  kuota → Komunikasi, canva → Software, …), dan project dari nama klien
  (`App\Services\Telegram\ProjectMatcher`: tidak peka huruf besar-kecil,
  toleran salah ketik satu huruf, mencari di semua project). Bila beberapa
  project sama cocoknya, bot hanya menawarkan kandidat itu.
- **Gemini** (bila aktif): hasilnya diutamakan, dan isian yang tidak diisi AI
  diisi pembaca aturan.

Jadi `expense 150rb grab ke kantor klien nestory` langsung menjadi ringkasan
lengkap walau Gemini mati atau saldonya habis. Untuk menambah kata kunci
kategori, ubah daftar di `CategoryGuesser`.

Cek cara bot membaca sebuah pesan tanpa membuka Telegram:

```bash
php artisan telegram:parse "advance 2jt beli properti shooting kemarin"
```

Cara tercepat memeriksa Gemini adalah tombol **Tes koneksi** di Settings →
Integrasi; pesannya menjelaskan penyebab gagal (saldo habis, key salah, model
tidak ada). Saldo prabayar diisi ulang di AI Studio → Billing.

Hasil AI tidak pernah dipakai mentah: project harus ada di daftar, kategori harus
salah satu dari `App\Enums\FinanceCategory`, nominal harus positif, dan tanggal
tidak boleh di masa depan. User tetap memeriksa draft sebelum menekan Kirim.

---

## Troubleshooting

| Gejala | Penyebab & solusi |
|---|---|
| Bot tidak membalas sama sekali | Settings → Integrasi → **Cek status**, lihat *Error terakhir*. |
| Webhook ⚠️ belum terdaftar ke server ini | `APP_URL` berubah atau webhook dipakai server lain. Tekan **Daftarkan ulang webhook**. |
| Error terakhir `403 Forbidden` | Secret tidak cocok, mis. setelah database dipulihkan dari backup. Tekan **Daftarkan ulang webhook**. |
| "Akun Telegram ini belum terhubung" | User belum menekan link dari halaman Profil, atau akunnya `is_active = 0`. |
| "Bot Telegram belum siap" di Profil | Token bot kosong atau salah di Settings → Integrasi. |
| Bot membalas tapi tidak memahami kalimat | Gemini tidak aktif; tekan **Tes koneksi** di Settings → Integrasi. |
| Kunci di Settings tiba-tiba tidak terbaca | `APP_KEY` berganti, sehingga kunci terenkripsi tidak bisa dibuka. Isi ulang kuncinya. |
| Draft hilang | Draft disimpan di cache selama 2 jam; `cache:clear` juga menghapusnya. |

Menghentikan bot sementara: Settings → Integrasi → **Matikan bot**.
Bila token bocor: BotFather → `/revoke`, tempel token baru di Settings → Integrasi, Simpan.

---

## Bot laporan keuangan (terpisah)

Bot kedua untuk Director, Finance, dan super_admin: invoice masuk, expense, dan
advance — **seluruh waktu atau per periode** — plus **mencatat pembayaran invoice** (`/bayar`) —
satu-satunya hal yang mengubah data. Token, webhook
(`/api/telegram/finance-webhook`), dan secret-nya terpisah dari bot pengajuan.

Setup:

1. BotFather → `/newbot` (mis. `SigncareFinanceBot`) → `/setjoingroups` → **Disable**.
2. Settings → Integrasi → **Bot Telegram — laporan keuangan** → tempel token → **Simpan**.
   Lewat SSH: `php artisan telegram:webhook --finance` (juga `info`, `delete`).
3. Pengguna menghubungkan akun Telegram **sekali** lewat bot pengajuan (Profil →
   Hubungkan Telegram). ID chat pribadi sama di semua bot, jadi bot laporan
   langsung mengenalinya. Role diperiksa ulang di setiap pesan dan tombol.

Perintah: `/ringkasan`, `/invoice`, `/piutang`, `/advance`, `/expense`, `/pending`,
`/periode`, `/excel`, `/bayar`. Setelah perintah baru ditambahkan, daftarkan ulang webhook
(Settings → Integrasi → Simpan, atau `php artisan telegram:webhook --finance`)
supaya menu perintah di Telegram ikut diperbarui.

### Mencatat pembayaran invoice (`/bayar`)

`/bayar` (atau tombol **💳 Catat pembayaran invoice**) → pilih invoice → pilih
rekening penerima → **Lunas** atau ketik nominal bila dibayar sebagian
(`12,5jt`, `12.500.000`) → **✅ Simpan**.

- Yang ditawarkan sama dengan tombol Bayar di ERP: invoice penjualan (bukan
  proforma) yang belum lunas dan tidak dibatalkan, jatuh tempo terlama dulu.
  `/bayar organik` atau mengetik nama klien / nomor invoice / nominal mencari
  invoice-nya.
- Invoice DP ditagih sebesar DP-nya; nominal tidak boleh melebihi sisa tagihan.
- Pencatatannya lewat `App\Services\InvoicePayment`, jalur yang sama dengan
  tombol Bayar di ERP: status lunas/sebagian, transaksi uang masuk di rekening
  (dicatat atas nama pengguna Telegram-nya), saldo dihitung ulang, dan log
  invoice. Baris invoice dikunci selama pencatatan, sehingga pembayaran dari ERP
  dan Telegram yang bersamaan tidak bisa melebihi tagihan; ketukan ✅ ganda
  hanya tercatat sekali.
- Tanggal transaksi = saat dicatat, sama seperti di ERP.

- **Ringkasan**: invoice masuk — total invoice DP, total invoice full payment
  (dan pelunasan bila ada), total, belum dibayarkan; expense — total dan belum
  dibayarkan; advance — total, belum dibayarkan, belum dipertanggungjawabkan.
- **Invoice**: angka yang sama plus daftar invoice periode itu.
- **Advance**: per project — diajukan, dicairkan, direalisasi, sisa.
- **Expense**: per kategori dan per project.
- **Belum dibayar** (`/piutang`): per invoice yang belum lunas, dan sisa DP yang
  belum dibuatkan invoice pelunasan.
- **Pending**: antrean yang menunggu persetujuan/pembayaran — selalu seluruh waktu.
- **Excel** (tombol ⬇️ atau `/excel`): data periode itu dalam satu file — sheet
  Ringkasan, Invoice, Expense, Advance, Per Project — karena pesan Telegram
  maksimal 4.096 karakter. Angkanya dari perhitungan yang sama dengan bot.

**Periode.** Tombol **📅 Periode** (atau `/periode`) menawarkan Bulan ini, Bulan
lalu, Tahun ini, Tahun lalu, Semua waktu; selama pilihan itu tampil, rentang
juga bisa diketik: `1/10/2026 - 31/10/2026`, `1 okt - 15 okt`, `oktober 2026`,
`2026`, atau langsung `/periode 1/10/2026 - 31/10/2026`. Periode diingat per
pengguna (90 hari) dan berlaku untuk semua laporan dan Excel sampai diganti.
Invoice disaring menurut tanggal invoice, expense dan advance menurut tanggal
pengajuan. Pasangan DP ↔ pelunasan tetap dicari dari seluruh invoice, jadi
pelunasan bulan ini tetap terpasang ke DP-nya dari bulan sebelumnya
(`App\Services\ReportPeriod`, `FinanceOverview::forPeriod()`).

**Pasangan DP ↔ pelunasan.** Nilai pekerjaan dihitung dari invoice DP (yang
menyimpan nilai penuh) dan invoice bayar penuh; invoice pelunasan yang sudah
dipasangkan ke DP-nya tidak ditambahkan lagi. Pasangannya lewat
`parent_invoice_id`; invoice lama (sebelum kolom itu ada) dipasangkan ke invoice
DP dengan deal atau klien yang sama — **hanya bila kandidatnya tepat satu**.
Yang ambigu tidak ditebak: bot menampilkan peringatan berisi jumlahnya, dan
pasangannya bisa dirapikan di ERP.

Definisi angka (`App\Services\FinanceOverview`):

| Angka | Definisi |
|---|---|
| Expense sudah dibayar | Status Dibayarkan/Selesai. Status yang dipakai, bukan kolom `paid` (pada data lama kolom itu bisa terisi walau baru disetujui). |
| Advance dicairkan | Status Dibayarkan, Sedang di-Realisasi (User), Siap Diselesaikan, Selesai |
| Belum dipertanggungjawabkan | Per advance yang belum selesai: dicairkan − direalisasi − sisa yang dikembalikan |
| Invoice DP / full payment / pelunasan | Invoice penjualan (bukan proforma) yang tidak draft/batal, sebesar `Invoice::amountDue()`: invoice DP hanya persentase DP-nya, sisanya lewat invoice pelunasan — `grand_total` mentah akan menghitung dobel |
| Invoice belum dibayarkan | Per invoice: ditagihkan − dibayar; lewat jatuh tempo bila `due_date` sudah lewat |
| Expense / advance belum dibayarkan | Status Pending + Disetujui (belum dibayar/dicairkan, tidak ditolak) |
| Ditolak | Ditampilkan terpisah, tidak masuk total |

Catatan: angka "pengeluaran" di Payment Dashboard ERP memakai definisi lain
(semua pengajuan termasuk yang ditolak/pending), jadi bisa berbeda dari bot.

---

## Peta kode

| Berkas | Isi |
|---|---|
| `app/Settings/IntegrationSettings.php` | Kunci Gemini & Telegram (cadangan ke `.env`) |
| `app/Filament/Pages/Setting/ManageIntegrations.php` | Halaman Settings → Integrasi |
| `app/Services/Telegram/TelegramWebhookManager.php` | Daftar, cek, cabut webhook |
| `app/Http/Controllers/TelegramWebhookController.php` | Cek secret, teruskan update ke bot |
| `app/Services/Telegram/TelegramBot.php` | Alur percakapan, tombol, pembuatan record |
| `app/Services/Telegram/FinanceBot.php` | Bot laporan keuangan |
| `app/Services/Telegram/InvoicePaymentFlow.php` | Alur `/bayar` di bot laporan keuangan |
| `app/Services/InvoicePayment.php` | Pencatatan pembayaran invoice (ERP & Telegram) |
| `app/Services/Telegram/SubmissionParser.php` | Pesan → isian (Gemini, atau aturan sederhana) |
| `app/Services/Telegram/AmountParser.php` | Membaca nominal: `150rb`, `1,5jt`, `Rp 150.000` |
| `app/Services/Telegram/Draft.php` | Draft di cache sebelum dikirim |
| `app/Services/Telegram/TelegramLinker.php` | Link sekali pakai untuk menghubungkan akun |
| `app/Services/Telegram/TelegramNotifier.php` | Notifikasi status ke Telegram (`NotifHelper::send(..., telegram: true)`) |
| `app/Livewire/TelegramConnection.php` | Bagian Telegram di halaman Profil |
| `tests/Feature/TelegramBotTest.php` | Tes alur end-to-end |

Satu pengajuan menyimpan satu lampiran, mengikuti kolom `attachment` di ERP.
