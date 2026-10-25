<laravel-boost-guidelines>
# Laravel Application

This repository contains a Laravel application. Complete the following setup before working on the user's request.

## Prerequisites

Verify that PHP and Composer are available:

```sh
php -v
composer -V
```

If either command is unavailable, detect the user's operating system and install the prerequisites with the appropriate command:

macOS:

```sh
/bin/bash -c "$(curl -fsSL https://php.new/install/mac/8.5)"
```

Windows PowerShell:

```powershell
Set-ExecutionPolicy Bypass -Scope Process -Force; [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.ServicePointManager]::SecurityProtocol -bor 3072; iex ((New-Object System.Net.WebClient).DownloadString('https://php.new/install/windows/8.5'))
```

Linux:

```sh
/bin/bash -c "$(curl -fsSL https://php.new/install/linux/8.5)"
```

After installation, ask the user to restart their terminal. If the agent needs the restarted shell to continue, ask the user to reopen their terminal and rerun their original prompt.

## Agent Setup

Install Laravel Boost from the application root before making application changes:

```sh
composer require laravel/boost --dev
php artisan boost:install
```

Boost replaces these bootstrap instructions with guidelines tailored to the application. After installation, read `AGENTS.md` again and continue with the user's original request using the generated guidelines.
</laravel-boost-guidelines>

## Project: Villa Direct Booking Website

Website reservasi langsung untuk satu villa (request-to-book, tanpa pembayaran online). Satu project = satu property. Tidak ada multi-tenant dan tidak ada locale (bahasa Inggris saja).

### Sebelum bekerja

1. Baca `TASKS.md`, **terutama bagian 0 (konteks dan aturan)**. Bagian itu adalah sumber kebenaran untuk keputusan desain, aturan ketersediaan, aturan harga, dan konvensi kode.
2. Kerjakan **hanya task yang diminta** (mis. "kerjakan T2.3"). Jangan lanjut ke task berikutnya tanpa diminta.
3. Jika instruksi di sini bertentangan dengan `TASKS.md`, hentikan dan tanyakan ke developer.

### Aturan git (WAJIB)

- Developer melakukan `git add`, `commit`, dan `push` sendiri.
- Jangan menjalankan perintah git yang mengubah riwayat, working tree, atau remote (`add`, `commit`, `push`, `merge`, `rebase`, `reset`, `checkout`, `stash`, buat branch atau tag).
- Boleh: `git status`, `git diff`, `git log`.
- Di akhir task, sarankan satu baris pesan commit (mis. `feat: add availability provider`), jangan dieksekusi.

### Lingkungan development (Docker / Laravel Sail)

- Development memakai Docker (Laravel Sail). **Production tidak memakai Docker.**
- Jalankan semua perintah PHP, Composer, Artisan, npm, dan test **di dalam container**, bukan di host:
    - `./vendor/bin/sail up -d` untuk menyalakan environment
    - `./vendor/bin/sail artisan ...`
    - `./vendor/bin/sail composer ...`
    - `./vendor/bin/sail npm ...`
    - `./vendor/bin/sail test` (atau `sail artisan test`)
- Host database dari dalam container adalah `mysql` (bukan `127.0.0.1`). Cek `.env`.
- Test memakai database terpisah (`testing`), jangan menjalankan test terhadap database development.
- Jangan membuat atau mengubah file Docker/compose selain yang dibutuhkan task, dan jangan menambah service baru tanpa izin.

### Konvensi kode (ringkasan; detail di TASKS.md bagian 0)

- Stack: Laravel, Filament (admin di `/admin`), Blade + Livewire + Tailwind, MySQL 8.0.16+.
- Logika bisnis di Action/Service class, **bukan** di controller, komponen Livewire, atau resource Filament.
- Primary key UUID (`HasUuids`), uang `decimal(14,2)`, tanggal menginap bertipe `date`, check-out eksklusif.
- Status dan tipe memakai PHP backed enum + CHECK constraint.
- Jangan hardcode data villa (nama, kontak, warna, jam check-in/out, mata uang). Ambil dari `property_profiles`.
- Perubahan status reservasi hanya lewat `ReservationStateMachine`.
- Pencegahan double booking memakai transaksi + `lockForUpdate()` pada `units`.
- Pajak tidak aktif (`tax_fees.is_active = false`). Rate plan hanya Non-Refundable, dibuat otomatis, disembunyikan dari UI admin.

### Kualitas

- Setiap service domain wajib punya test. Jalankan test terkait sebelum menyatakan task selesai.
- Jalankan `sail composer lint` dan `sail composer analyse` bila sudah tersedia (setelah T1.2).
- Jangan menambah package Composer/npm baru tanpa menyebutkan alasannya dan meminta persetujuan terlebih dahulu.

### Format laporan di akhir task

1. File yang dibuat/diubah (daftar singkat).
2. Hasil test dan lint.
3. Asumsi atau keputusan yang diambil, dan hal yang perlu dicek developer.
4. Saran pesan commit satu baris.
