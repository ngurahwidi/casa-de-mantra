# TASKS.md: Villa Direct Booking Website

Dokumen ini adalah daftar tugas berurutan dari setup sampai deployment. Kerjakan **satu task per sesi**, sesuai urutan. Setelah tiap task selesai, jalankan test terkait, lalu berikan ringkasan singkat perubahan (file yang dibuat/diubah dan hasil test).

**Aturan git (WAJIB):** developer melakukan `git add`, `git commit`, dan `git push` **sendiri**. Jangan menjalankan perintah git yang mengubah riwayat atau remote (`add`, `commit`, `push`, `merge`, `rebase`, `reset`, `checkout` ke branch lain, `stash`, dsb.), dan jangan membuat branch atau tag. Perintah baca saja seperti `git status` dan `git diff` boleh dipakai. Di akhir tiap task, sarankan pesan commit yang pantas dalam satu baris (mis. `feat: add availability provider`), tetapi jangan mengeksekusinya.

---

## 0. Konteks dan aturan (WAJIB DIBACA SEBELUM MULAI)

### Ringkasan produk

Website villa untuk klien hospitality. Tamu mengirim **permintaan reservasi (request-to-book)** tanpa pembayaran online. Admin memverifikasi ketersediaan (termasuk kalender OTA seperti Booking.com/Expedia secara manual), lalu menyetujui dan meminta pembayaran via transfer manual. Reservasi baru `confirmed` setelah pembayaran dicatat admin.

### Keputusan yang sudah final

- **Satu project = satu property.** Tidak ada multi-tenant, tidak ada `property_id`, `site_id`, atau domain resolver. Profil villa disimpan di tabel `property_profiles` (selalu 1 baris).
- **Hanya bahasa Inggris.** Tidak ada kolom/tabel locale, tidak ada prefix bahasa di URL. Teks UI publik ditulis langsung dalam bahasa Inggris.
- **Pajak tidak dipakai**, tetapi tabel `tax_fees` tetap ada dengan kolom `is_active` (default `false`). Service harga hanya membaca baris `is_active = true`. Tabel kosong berarti `tax_total = 0`. Resource Filament untuk `tax_fees` disembunyikan dari navigasi.
- **Hanya rate plan Non-Refundable** yang dijual. Rate plan tetap disimpan di database, tapi **UI admin menyembunyikan konsep rate plan**; sistem membuatnya otomatis.
- **Tanpa payment gateway** di fase 1. Pembayaran = transfer manual yang dicatat admin. Tabel `payments` siap untuk gateway nanti.
- **Arsitektur harus siap integrasi PMS/channel manager/booking engine** di masa depan (lihat bagian "Kesiapan integrasi").

### Stack

- Laravel (versi stabil terbaru), PHP sesuai requirement Laravel tersebut
- **MySQL 8.0.16+** (InnoDB, charset `utf8mb4`, collation `utf8mb4_0900_ai_ci` atau `utf8mb4_unicode_ci`). Versi minimum 8.0.16 wajib agar CHECK constraint benar-benar ditegakkan. MariaDB tidak menjadi target.
- **Docker (Laravel Sail) hanya untuk development.** Production tidak memakai Docker (lihat Fase 12). Semua perintah `php`, `composer`, `artisan`, `npm`, dan test dijalankan lewat `./vendor/bin/sail ...`. Pastikan image MySQL di Sail berversi minimal 8.0.16.
- Filament (versi stabil terbaru) untuk admin panel
- Blade + Livewire + Tailwind CSS untuk situs publik
- Pest atau PHPUnit untuk test
- Queue: database driver (cukup untuk awal), scheduler Laravel untuk job terjadwal

### Konvensi kode

- **Logika bisnis ada di Action/Service class** (`app/Actions` atau `app/Services`), bukan di controller, komponen Livewire, atau resource Filament. Controller/Livewire/Filament hanya memanggil service.
- Primary key memakai **UUID** (kecuali `property_profiles`). Di MySQL, gunakan `$table->uuid()` (CHAR(36)) dan trait `HasUuids` bawaan Laravel (UUID v7 yang berurutan, supaya index tetap efisien). Foreign key memakai `foreignUuid()`.
- **Catatan khusus MySQL:**
    - Kolom `json` tidak boleh punya nilai default literal; beri default lewat model/cast.
    - Collation default tidak membedakan huruf besar/kecil. Untuk kolom yang harus case-sensitive (`reservations.code`, `ical_feeds.export_token`), gunakan collation `utf8mb4_bin`, atau generate nilai huruf kecil saja dan bandingkan secara konsisten.
    - Pastikan `date` dan `datetime` tidak diubah zona waktunya oleh driver; simpan timestamp dalam UTC, tanggal menginap sebagai `date`.
    - Level isolasi default InnoDB adalah REPEATABLE READ. Pencegahan double booking mengandalkan `lockForUpdate()` pada baris `units` di dalam transaksi (lihat T4.5/T4.6), bukan constraint tanggal di database (MySQL tidak punya exclusion constraint).
    - Batasi panjang kolom string yang diindeks agar sesuai batas index (mis. `varchar(191)` atau gunakan prefix bila perlu).
- Tanggal menginap disimpan sebagai tipe **`date`** (bukan timestamp), berdasarkan zona waktu dari `property_profiles.timezone`.
- **Check-out bersifat eksklusif**: malam terakhir = `check_out - 1 hari`. Dua rentang bentrok jika `a.start < b.end AND a.end > b.start`.
- Uang disimpan sebagai `decimal(14,2)`. Mata uang dari `property_profiles.currency`.
- Status dan tipe memakai **PHP backed enum** + CHECK constraint di database (didukung MySQL 8.0.16+; tulis lewat `DB::statement` di migrasi karena Schema builder tidak menyediakan helper CHECK).
- Jangan hardcode nama villa, kontak, warna, jam check-in/out, mata uang, atau teks kebijakan. Ambil dari `property_profiles` atau database.
- Setiap perubahan status reservasi **wajib** lewat satu service (`ReservationStateMachine`) yang menulis `reservation_status_logs`.
- Tulis test untuk setiap service di bagian domain.

### Status reservasi

`pending` → `awaiting_payment` → `confirmed` → `completed`
Cabang: `pending → rejected`, `pending|awaiting_payment|confirmed → cancelled`, `awaiting_payment → expired`, `expired → awaiting_payment` (reactivate, T4.7).
Membatalkan reservasi `confirmed` tidak memicu refund (non-refundable); refund pengecualian dicatat manual.

### Aturan ketersediaan

- Unit terisi jika ada `availability_blocks` yang beririsan, **atau** reservasi berstatus `awaiting_payment` / `confirmed` yang beririsan.
- Status `pending` **tidak** memblokir tanggal untuk publik.
- Validasi ulang ketersediaan **di dalam transaksi database** (dengan `lockForUpdate` pada baris unit) setiap kali reservasi masuk ke `awaiting_payment` atau `confirmed`.

### Aturan harga

Untuk tiap malam dari `check_in` sampai `check_out - 1`: ambil `rate_rules` milik rate plan terpilih yang `start_date <= malam` dan (`end_date IS NULL` atau `end_date >= malam`), hari cocok dengan `days_of_week_mask`, urutkan `priority DESC`, ambil satu. Jika tidak ada yang cocok: **malam itu tidak boleh dijual** (lempar exception domain), jangan jatuh ke harga 0. Hasil disalin ke `reservation_nights` sebagai snapshot.

`days_of_week_mask`: Senin=1, Selasa=2, Rabu=4, Kamis=8, Jumat=16, Sabtu=32, Minggu=64 (semua hari = 127).

### Kesiapan integrasi (jangan dilanggar)

- Ketersediaan diambil lewat interface `AvailabilityProvider`; implementasi sekarang `InternalAvailabilityProvider`.
- Pembuatan reservasi lewat interface `ReservationProvider`; implementasi sekarang `InternalReservationProvider`.
- Simpan `source`, `external_source`, `external_id` di `reservations`.
- Tabel `integration_mappings` dan `outbox_events` sudah dibuat. Setiap perubahan status penting menulis event ke `outbox_events` (mis. `reservation.created`, `reservation.confirmed`).
- Modul reservasi harus bisa dimatikan/diganti tanpa merusak lapisan konten (halaman, galeri, SEO).

---

## Fase 1: Setup project

### T1.1 Inisialisasi Laravel

- Buat project Laravel, set `APP_NAME`, `APP_LOCALE=en`, `APP_FALLBACK_LOCALE=en`, timezone aplikasi `UTC` (konversi tampilan memakai timezone property).
- Pasang dan konfigurasikan Laravel Sail dengan service MySQL (dan hanya service yang dibutuhkan). Di `.env`, `DB_HOST=mysql`. Pastikan database `testing` untuk test tersedia.
- Konfigurasi koneksi MySQL di `.env` dan `.env.example` (`DB_CONNECTION=mysql`, charset `utf8mb4`, `strict` mode aktif di `config/database.php`).
- Pastikan `.gitignore` sesuai (`.env`, `vendor`, `node_modules`, `storage` privat). Inisialisasi repository dan commit dilakukan developer sendiri.
- **Selesai jika:** `php artisan migrate` berjalan ke MySQL dan halaman default muncul.

### T1.2 Tooling kualitas kode

- Pasang Laravel Pint, PHPStan/Larastan (level moderat), dan Pest/PHPUnit.
- Tambahkan script composer: `lint`, `analyse`, `test`.
- **Selesai jika:** ketiga script berjalan tanpa error di project kosong.

### T1.3 Pasang Filament

- Install Filament, buat panel admin di path `/admin`, buat user admin awal lewat seeder (bukan hardcode password; baca dari `.env`).
- Bahasa panel admin: konfirmasi ke developer apakah Inggris atau Indonesia (default Inggris).
- **Selesai jika:** login admin berhasil.

### T1.4 Pasang Tailwind dan struktur view publik

- Siapkan Vite + Tailwind, layout dasar `resources/views/layouts/public.blade.php`.
- Siapkan folder `resources/views/components` untuk komponen yang dipakai ulang (kartu kamar, form booking, galeri).
- **Selesai jika:** halaman beranda placeholder tampil dengan style Tailwind.

---

## Fase 2: Database dan model

Buat satu migrasi per kelompok tabel. Semua foreign key dengan index. Tambahkan CHECK constraint untuk kolom enum.

### T2.1 Admin dan profil property

- Tabel `admin_users` (pakai tabel `users` bawaan Laravel yang diberi kolom `role`: `owner|staff`).
- Tabel `property_profiles` (1 baris): `type` (villa|homestay|guesthouse), `name`, `timezone`, `currency`, `checkin_time`, `checkout_time`, `whatsapp`, `email`, `address`, `lat`, `lng`, `seo_settings` (json), `theme` (json), `bank_account_info` (json, untuk instruksi transfer), `payment_window_hours` (int, default 24), `pending_reminder_hours` (int).
- Helper `PropertyProfile::current()` dengan cache.

### T2.2 Kamar dan fasilitas

- `room_types`: `id`, `name`, `slug` (unik), `description`, `max_adults`, `max_children`, `bedrooms`, `bathrooms`, `size_m2`, `is_published`.
- `units`: `room_type_id`, `code`, `status` (active|inactive).
- `amenities`: `name`, `icon`, `category`.
- `room_type_amenity` (junction, composite PK).
- `media`: polimorfik (`owner_type`, `owner_id`, `url`, `alt`, `sort_order`). Pertimbangkan Spatie Media Library jika lebih praktis.

### T2.3 Harga

- `cancellation_policies`: `name`, `rules` (json), `description` (teks tampil ke tamu).
- `rate_plans`: `room_type_id`, `cancellation_policy_id`, `name`, `meal_plan` (room_only|breakfast|half_board|full_board), `min_stay`, `max_stay`, `is_active`.
- `rate_rules`: `rate_plan_id`, `name`, `start_date`, `end_date` (**nullable**), `days_of_week_mask`, `price_per_night`, `min_stay_override` (nullable), `priority`.
- `tax_fees`: `name`, `type` (percent|fixed), `value`, `is_included_in_price`, `applies_to` (per_night|per_booking|per_guest), `is_active` (default `false`).

### T2.4 Ketersediaan dan iCal

- `ical_feeds`: `room_type_id`, `channel` (booking|expedia|airbnb|other), `direction` (import|export), `url`, `export_token`, `last_synced_at`, `last_status`.
- `availability_blocks`: `unit_id`, `ical_feed_id` (nullable), `start_date`, `end_date`, `source` (manual|ical|ota_manual), `reason`, `external_uid`. Index pada (`unit_id`, `start_date`, `end_date`).

### T2.5 Reservasi dan pembayaran

- `guests`: `full_name`, `email`, `phone`, `country`.
- `reservations`: `code` (unik), `guest_id`, `status`, `source` (direct_web|booking_com|expedia|manual), `check_in`, `check_out`, `adults`, `children`, `special_requests`, `currency`, `subtotal`, `tax_total` (default 0), `total`, `payment_status` (unpaid|deposit|paid|refunded), `expires_at`, `confirmed_at`, `ota_blocked_at`, `reject_reason`, `external_source`, `external_id`, `policy_snapshot` (json).
- `reservation_rooms`: `reservation_id`, `room_type_id`, `unit_id` (nullable), `rate_plan_id`, `total_price`.
- `reservation_nights`: `reservation_room_id`, `night_date`, `price`.
- `reservation_status_logs`: `reservation_id`, `admin_user_id` (nullable untuk aksi sistem), `from_status`, `to_status`, `note`, `created_at`.
- `payments`: `reservation_id`, `verified_by` (nullable), `amount`, `method`, `status` (pending|paid|failed|refunded|expired), `provider`, `provider_ref`, `paid_at`, `proof_url`.
- Index pada (`status`, `check_in`, `check_out`) di `reservations`.

### T2.6 Pendukung

- `pages` (`slug` unik, `title`, `body`, `is_published`), `faqs` (`question`, `answer`, `sort_order`), `reviews` (`guest_name`, `rating`, `comment`, `source`, `is_published`).
- `notification_templates` (`code`, `channel`, `subject`, `body`; unik pada `code + channel`), `notification_logs` (`reservation_id`, `channel`, `template`, `status`, `error_message`, `sent_at`).
- `integration_mappings` (`entity_type`, `internal_id`, `provider`, `external_id`, `last_synced_at`), `outbox_events` (`type`, `payload`, `status`, `processed_at`).

### T2.7 Model, relasi, enum

- Buat Eloquent model untuk semua tabel dengan relasi, cast (date, json, enum), dan factory.
- **Selesai jika:** `php artisan migrate:fresh` sukses dan factory bisa menghasilkan data untuk semua model.

---

## Fase 3: Seeder

### T3.1 Seeder data dasar

- `PropertyProfileSeeder`: isi dari `.env`/config (nama villa dummy, timezone `Asia/Makassar`, currency `IDR`).
- `AdminUserSeeder`: owner pertama dari `.env`.
- `CancellationPolicySeeder`: template **Non-Refundable** dengan teks jelas untuk tamu.
- `AmenitySeeder`: daftar fasilitas umum villa (kolam renang, Wi-Fi, AC, dapur, parkir, dll).
- `NotificationTemplateSeeder`: template email untuk `reservation_received`, `reservation_awaiting_payment`, `reservation_confirmed`, `reservation_rejected`, `reservation_expired`, `admin_new_request`, `pre_arrival_reminder`.
- Seeder demo (hanya environment non-production): satu `room_type`, satu `unit`, rate plan, dan rate rule contoh.

---

## Fase 4: Service domain (inti sistem)

Semua service punya test. Gunakan data deterministik dan `Carbon::setTestNow`.

### T4.1 Interface dan provider ketersediaan

- Definisikan `AvailabilityProvider` (`isAvailable`, `availableUnits`, `blockedDates`).
- Implementasi `InternalAvailabilityProvider` sesuai "Aturan ketersediaan".
- Bind di service container.
- **Test:** rentang bentrok, check-out sama dengan check-in tamu lain (harus boleh), pending tidak memblokir, awaiting_payment/confirmed memblokir, block manual dan iCal memblokir, unit inactive.

### T4.2 Pricing service

- `CalculatePrice(roomType, ratePlan, checkIn, checkOut, adults, children)` mengembalikan DTO: harga per malam, subtotal, `tax_total` (dari `tax_fees` aktif saja), total.
- Validasi: kapasitas `max_adults`/`max_children`, `min_stay`/`max_stay`, `min_stay_override` dari rule yang berlaku, rate plan aktif, tipe kamar published.
- Malam tanpa rule: lempar `PriceNotConfiguredException`.
- **Test:** weekday vs weekend, rule priority, rule tanpa `end_date`, malam tanpa harga, min stay override, pajak nonaktif = 0.

### T4.3 Reservation state machine

- `ReservationStateMachine::transition(reservation, toStatus, actor, note)` memvalidasi transisi yang diizinkan, menulis `reservation_status_logs`, dan menulis `outbox_events`.
- Transisi tidak valid melempar exception.
- **Test:** semua transisi valid dan invalid.

### T4.4 Create reservation request

- `CreateReservationRequest` (implementasi `ReservationProvider`): validasi ketersediaan dan harga, buat/ambil `guests` (cocokkan via email), buat `reservations` (status `pending`, source `direct_web`), `reservation_rooms`, `reservation_nights`, isi `policy_snapshot`, generate `code` yang tidak mudah ditebak (mis. 8 karakter acak), dalam satu transaksi.
- Kirim event `reservation.created` (memicu notifikasi).
- **Test:** sukses, tanggal tidak tersedia, harga belum diatur, kapasitas melebihi batas.

### T4.5 Approve dan request payment

- `ApproveReservation`: dalam transaksi, `lockForUpdate` pada unit, validasi ulang ketersediaan, tetapkan `unit_id` di `reservation_rooms`, set `expires_at = now + payment_window_hours`, transisi ke `awaiting_payment`.
- `RejectReservation`: wajib `reject_reason`, transisi ke `rejected`.
- **Test:** berhasil, gagal karena bentrok, dua approve bersamaan untuk tanggal sama (hanya satu berhasil).

### T4.6 Record payment dan confirm

- `RecordPayment`: buat `payments` (status `paid`), isi `paid_at`, `proof_url`, `verified_by`; hitung `payment_status` (`deposit` jika total pembayaran < total, `paid` jika >=).
- `ConfirmReservation`: dalam transaksi, validasi ulang ketersediaan, transisi ke `confirmed`, isi `confirmed_at`. Jika `payment_status = paid`, otomatis dipanggil setelah `RecordPayment`; kebijakan untuk DP (confirm atau tidak) dibuat sebagai pengaturan di `property_profiles` (`confirm_on_deposit`, default `false`).
- **Test:** pembayaran penuh, kurang, lebih, pembayaran setelah expired ditolak (admin harus `ReactivateReservation` terlebih dahulu).

### T4.7 Cancel, expire, reactivate, complete

- `CancelReservation` (alasan wajib, kebijakan non-refundable: tidak ada refund otomatis, catat di log).
- `ExpireStaleReservations`: untuk reservasi `awaiting_payment` dengan `expires_at < now`, transisi ke `expired` dan lepas tanggal.
- `ReactivateReservation`: hanya jika tanggal masih tersedia.
- `CompleteReservation`: untuk `confirmed` yang `check_out < today` (timezone property).

### T4.8 Reservasi manual dan blok OTA

- `CreateManualReservation` untuk input booking OTA/telepon (source `booking_com|expedia|manual`), langsung `confirmed`, validasi bentrok tetap berlaku.
- `MarkOtaBlocked` mengisi `ota_blocked_at`.

---

## Fase 5: Admin panel (Filament)

### T5.1 Resource konten

- Resource: `PropertyProfile` (edit satu baris, tanpa create/delete), `RoomType` (dengan relation manager `Unit`, fasilitas, media), `Amenity`, `Page`, `Faq`, `Review`.
- Resource `TaxFee` dibuat tetapi **disembunyikan** dari navigasi.

### T5.2 Pengaturan harga (UI tanpa istilah teknis)

- Halaman **"Pricing"** per tipe kamar. Sembunyikan konsep rate plan.
- Saat tipe kamar pertama kali diset harga dasar, sistem otomatis membuat `rate_plan` "Non-Refundable" (meal_plan `room_only`, terhubung ke policy non-refundable, aktif) dan `rate_rule` "Base Price" (mask 127, priority terendah, `end_date` kosong).
- Form tambah aturan khusus: **Weekend price** (pilih hari), **Seasonal price** (rentang tanggal + min stay), **Special dates** (tanggal tertentu). Priority diisi otomatis berdasarkan jenis.
- Pratinjau kalender harga akhir per tanggal; tanggal tanpa harga ditandai merah.

### T5.3 Kalender ketersediaan

- Halaman kalender per unit: tampilkan reservasi (`awaiting_payment`, `confirmed`), block manual, dan block iCal dengan warna berbeda.
- Aksi: buat/hapus block manual, ubah harga/min stay untuk rentang tanggal (membuat/memperbarui `rate_rules`).

### T5.4 Resource reservasi

- Daftar dengan filter status, tanggal, sumber; badge status berwarna.
- Halaman detail: data tamu, rincian malam, total, riwayat status, pembayaran, log notifikasi.
- Aksi header (tampil sesuai status): **Approve & Request Payment**, **Reject** (modal alasan), **Record Payment** (modal nominal, metode, ref, tanggal, upload bukti), **Cancel**, **Reactivate**, **Mark as Completed**.
- Checklist **"Blocked on OTA"** (mengisi `ota_blocked_at`).
- Peringatan jika ada reservasi `pending` lain yang bentrok.
- Semua aksi memanggil service dari Fase 4, tanpa logika di resource.
- Aksi **Create manual reservation**.

### T5.5 Dashboard

- Widget: jumlah `pending` (dengan umur tertua), `awaiting_payment` yang hampir expired, check-in/check-out hari ini, reservasi `confirmed` yang belum "Blocked on OTA", status sinkron iCal (feed error), okupansi bulan ini.

### T5.6 Resource pendukung

- Resource `IcalFeed` (dengan tombol "Sync now" dan tampilkan `last_status`), `NotificationTemplate`, `NotificationLog` (read-only), `CancellationPolicy`.
- Hak akses: `owner` akses penuh; `staff` tidak boleh mengubah pengaturan sistem, template, atau pengguna (gunakan Filament policy).

### T5.7 Readiness check

- Halaman/widget "Ready to sell?" yang memeriksa: profil property lengkap, tipe kamar punya kapasitas dan minimal 1 foto, minimal 1 unit aktif, rate plan aktif dengan policy, **setiap malam 365 hari ke depan punya harga**, template notifikasi aktif, feed iCal tidak error. Tampilkan tanggal yang belum berharga.
- Tipe kamar tidak boleh dipublikasikan jika pemeriksaan wajib gagal.

---

## Fase 6: Situs publik

### T6.1 Halaman konten

- Rute dan view: Home, Villa detail (`/villa/{slug}` atau sesuai jumlah tipe kamar), Gallery, Location (peta), About, Contact (form pesan + tombol WhatsApp), FAQ, Policies (`/policies/{slug}`).
- Konten dari database. Tombol WhatsApp sticky di semua halaman.
- Meta SEO per halaman, Open Graph, `sitemap.xml`, `robots.txt`, dan JSON-LD schema.org `LodgingBusiness`/`VacationRental` dari `property_profiles`.

### T6.2 Widget pencarian dan harga

- Komponen Livewire: pilih tanggal dan jumlah tamu, panggil `AvailabilityProvider` + `CalculatePrice`.
- Jika tidak tersedia: tampilkan tanggal kosong terdekat atau ajakan WhatsApp.
- Jika tersedia: tampilkan harga per malam, total, dan ringkasan kebijakan non-refundable.
- Tampilkan kalender tanggal yang terblokir/tidak bisa dipilih.

### T6.3 Form request-to-book

- Langkah: ringkasan → data tamu (nama, email, telepon/WhatsApp, negara, special requests) → centang persetujuan kebijakan non-refundable (wajib) → submit.
- Validasi di server (FormRequest), lalu panggil `CreateReservationRequest`.
- Proteksi spam: honeypot, rate limit per IP, dan captcha tak terlihat (mis. Cloudflare Turnstile).
- Halaman sukses `/booking/success/{code}`: jelaskan bahwa permintaan **belum terkonfirmasi** dan estimasi waktu respons.

### T6.4 Halaman status reservasi

- `/booking/status`: input kode + email, tampilkan status dan langkah berikutnya.
- Saat `awaiting_payment`: tampilkan nominal, batas waktu, instruksi transfer (dari `property_profiles.bank_account_info`), dan **upload bukti transfer** (validasi tipe/ukuran file, simpan di storage privat).
- Rate limit pada halaman ini untuk mencegah enumerasi kode.

### T6.5 Polish

- Responsif (mobile first), performa gambar (lazy load, ukuran responsif, format modern), aksesibilitas dasar, halaman 404/500 bermerek.
- Format tanggal yang jelas ("25 Oct 2026") dan mata uang dari profil.

---

## Fase 7: Notifikasi

### T7.1 Email

- Konfigurasi mailer (SMTP/provider), nama dan alamat pengirim dari `property_profiles`.
- Listener untuk event reservasi yang mengirim email via queue memakai `notification_templates` (render dengan variabel: kode, tanggal, total, link status, dll). Catat ke `notification_logs` (queued/sent/failed + error).
- Email ke admin saat permintaan baru masuk.
- **Test:** email terkirim per transisi, kegagalan tercatat, tidak ada kirim ganda.

### T7.2 Pengingat

- Pengingat ke admin untuk `pending` yang melewati batas respons.
- Pengingat ke tamu untuk `awaiting_payment` mendekati expired.
- Pengingat pra-kedatangan (H-3) untuk reservasi `confirmed`.
- (Opsional, nanti) saluran WhatsApp di belakang interface `NotificationChannel`.

---

## Fase 8: Sinkronisasi iCal

### T8.1 Import

- `SyncIcalFeed` mengunduh feed (timeout, batas ukuran, validasi URL: hanya http/https, blokir alamat internal untuk mencegah SSRF), parse event, buat/perbarui/hapus `availability_blocks` berdasarkan `external_uid`, dan simpan `last_synced_at` + `last_status`.
- Event yang hilang dari feed menghapus block terkait.
- **Test:** feed valid, feed rusak, event dihapus, tanggal all-day dengan DTEND eksklusif.

### T8.2 Export

- Endpoint `GET /ical/{export_token}.ics` menghasilkan feed dari reservasi `awaiting_payment`/`confirmed` dan block manual untuk tipe kamar terkait. Token tidak mudah ditebak dan bisa diregenerasi dari admin.

### T8.3 Job terjadwal

- Scheduler: sinkron semua feed import tiap 15-30 menit; peringatan ke admin jika sebuah feed gagal berturut-turut.

---

## Fase 9: Job terjadwal

### T9.1 Scheduler

Daftarkan di `routes/console.php`:

- `ExpireStaleReservations` (tiap 5-15 menit).
- Sinkron iCal (lihat T8.3).
- `CompleteReservation` untuk yang sudah check-out (harian).
- Pengingat (lihat T7.2).
- Pemroses `outbox_events` (menandai terproses; di fase 1 belum ada konsumen eksternal).
- Prune data lama sesuai kebijakan (log notifikasi, file bukti sementara).

---

## Fase 10: Keamanan dan hardening

### T10.1 Checklist

- Rate limiting pada form booking, kontak, status, dan login admin.
- Panel admin dengan 2FA (opsional tapi dianjurkan) dan password policy kuat.
- Upload bukti transfer: validasi MIME dan ukuran, simpan di disk privat, akses lewat URL bertanda tangan.
- Mass-assignment dilindungi (`$fillable`), validasi input di server, output di-escape (Blade default), konten CMS HTML disanitasi.
- Security headers (CSP dasar, HSTS, X-Frame-Options, Referrer-Policy).
- Kode reservasi tidak berurutan dan tidak bisa dienumerasi.
- `APP_DEBUG=false` di production, secret hanya di `.env`.
- Backup database terjadwal + uji restore.

---

## Fase 11: Pengujian

### T11.1 Test suite

- Unit test semua service Fase 4 (target coverage tinggi di domain).
- Feature test alur utama end-to-end: pencarian → request → approve → record payment → confirmed → completed.
- Test konkurensi: dua approve/confirm bersamaan pada tanggal sama.
- Test otorisasi Filament (owner vs staff).
- Test browser untuk alur booking (Laravel Dusk atau Playwright) minimal satu skenario utama.
- CI (GitHub Actions): jalankan `lint`, `analyse`, `test` pada setiap push.

### T11.2 UAT bersama klien

- Skenario: permintaan masuk, tolak, approve, bayar, expired, input booking OTA manual, blok tanggal manual, ubah harga Natal, sinkron iCal.
- Catat revisi dan prioritaskan.

---

## Fase 12: Deployment

### T12.1 Infrastruktur

- Pilih hosting (VPS dengan Nginx + PHP-FPM + MySQL 8, atau platform terkelola dengan MySQL 8). Siapkan server, user non-root, firewall, dan update keamanan.
- Domain, DNS, dan sertifikat TLS (Let's Encrypt/Caddy), redirect HTTP ke HTTPS.
- **Admin di subdomain terpisah** (mis. `admin.domain.com`) atau path yang dibatasi, dengan sesi/cookie terpisah bila memungkinkan.

### T12.2 Konfigurasi production

- `.env` production: `APP_ENV=production`, `APP_DEBUG=false`, kredensial DB, mailer, storage disk, `APP_URL`.
- Jalankan `php artisan config:cache`, `route:cache`, `view:cache`, `event:cache`, dan `storage:link`.
- Queue worker dikelola Supervisor/systemd (restart otomatis), cron untuk `schedule:run` tiap menit.
- Penyimpanan file (foto, bukti) di disk lokal yang di-backup atau object storage (S3-compatible); pertimbangkan CDN untuk gambar.

### T12.3 Pipeline deploy

- Skrip/CI deploy: pull, `composer install --no-dev --optimize-autoloader`, build aset (`npm ci && npm run build`), `php artisan migrate --force`, cache, restart queue (`queue:restart`), dengan strategi zero-downtime sederhana (symlink release) bila memungkinkan.
- Rencana rollback terdokumentasi.

### T12.4 Monitoring

- Log error terpusat (mis. Sentry), uptime monitor, pemantauan queue gagal (`failed_jobs`), dan peringatan disk/DB.
- Backup database harian (mis. `mysqldump --single-transaction` atau Percona XtraBackup) dan backup file; uji restore berkala.

### T12.5 Go-live checklist

- [ ] Readiness check di admin lolos, harga terisi 365 hari ke depan.
- [ ] Email uji terkirim ke inbox (bukan spam); SPF/DKIM/DMARC dikonfigurasi.
- [ ] Alur booking dicoba dengan data nyata oleh klien.
- [ ] Feed iCal OTA terpasang di kedua arah dan tervalidasi.
- [ ] Google Search Console + sitemap dikirim, analytics aktif.
- [ ] Halaman kebijakan (pembatalan, privasi, syarat) final dan disetujui klien.
- [ ] Akun admin staf dibuat, pelatihan singkat dan panduan SOP harian diberikan.
- [ ] Backup dan monitoring aktif.

---

## Fase 13: Pasca-rilis dan persiapan masa depan

### T13.1 Dokumentasi

- `README.md`: setup lokal, env, perintah penting, arsitektur singkat.
- `docs/`: ERD terbaru, alur status reservasi, SOP admin harian, daftar keputusan yang sudah final.
- Dokumen **"Cara menjadikan project ini template untuk property lain"**: salin repo, database baru, ubah `PropertyProfileSeeder`, tema, dan konten.

### T13.2 Persiapan integrasi vendor (hanya jika klien berlangganan)

- Evaluasi vendor: API publik? embed booking engine? webhook? impor data? biaya per reservasi?
- Skenario A (booking engine vendor): matikan modul reservasi internal, pertahankan lapisan konten; tombol Book mengarah/embed ke vendor.
- Skenario B (API vendor): implementasikan adapter `AvailabilityProvider`/`ReservationProvider` baru dan konsumen `outbox_events`, isi `integration_mappings`.
- Skenario C (hybrid): migrasi bertahap dan impor reservasi lama sebelum cutover.

### T13.3 Fitur yang sengaja ditunda

- Payment gateway (webhook mengisi `payments` dan memanggil `ConfirmReservation`).
- Kanal WhatsApp otomatis.
- Pajak (aktifkan menu `TaxFee` dan isi tarif).
- Bahasa tambahan (butuh kolom locale baru).
- Rate plan fleksibel (munculkan menu Rate Plan di admin).
- Ekstrak inti reservasi menjadi package Composer jika property kedua/ketiga dibuat.
