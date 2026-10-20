<?php

use App\Jobs\SendWhatsAppReminder;
use App\Jobs\SubmitMaganghubReport;
use App\Models\DailyReport;
use App\Models\InternUser;
use App\Services\MagangHubAutomationService;
use App\Services\WahaService;
use Carbon\Carbon;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule daily reminder at 17:30 WITA on weekdays to all active users
Schedule::job(new SendWhatsAppReminder)
    ->weekdays()
    ->at('17:30')
    ->timezone('Asia/Makassar')
    ->withoutOverlapping();

// Artisan helper to test sending reminder immediately
Artisan::command('app:send-reminder-test {phone?}', function (?string $phone = null) {
    $target = $phone ?: 'seluruh rekan magang aktif';
    $this->info("Mengirim pengingat WhatsApp ke: {$target}...");
    SendWhatsAppReminder::dispatchSync($phone, force: true);
    $this->info('✅ Pengingat berhasil diproses.');
})->purpose('Mengirim pengingat WhatsApp secara manual untuk pengujian');

// Artisan command: Mendaftarkan atau mengaktifkan nomor user
Artisan::command('app:add-user {name} {phone} {--lid=} {--email=} {--password=} {--notes=}', function (string $name, string $phone) {
    $cleanPhone = InternUser::cleanPhoneNumber($phone);
    $notes = $this->option('notes');
    $lid = $this->option('lid');
    $email = $this->option('email');
    $password = $this->option('password');

    $attributes = [
        'name' => $name,
        'is_active' => true,
        'notes' => $notes,
    ];
    if (! empty($lid)) {
        $attributes['whatsapp_lid'] = preg_replace('/[^0-9]/', '', $lid);
    }
    if (! empty($email)) {
        $attributes['maganghub_email'] = $email;
    }
    if (! empty($password)) {
        $attributes['maganghub_password'] = $password;
    }

    $user = InternUser::query()->updateOrCreate(
        ['phone_number' => $cleanPhone],
        $attributes
    );

    $msg = "✅ Berhasil mendaftarkan/mengaktifkan user: {$user->name} ({$user->phone_number})";
    if ($user->whatsapp_lid) {
        $msg .= " [LID: {$user->whatsapp_lid}]";
    }
    if ($user->maganghub_email) {
        $msg .= " [MagangHub: {$user->maganghub_email}]";
    }
    $this->info($msg);
})->purpose('Mendaftarkan nomor WhatsApp dan akun MagangHub baru');

// Artisan command: Menampilkan seluruh user terdaftar
Artisan::command('app:list-users', function () {
    $users = InternUser::query()->orderBy('name')->get();

    $this->info('=== DAFTAR REKAN MAGANG TERDAFTAR ===');

    if ($users->isEmpty()) {
        $this->warn('Belum ada user yang terdaftar di database.');
    } else {
        $rows = $users->map(function ($u) {
            $accountStatus = '-';
            if (file_exists($u->getSessionFilePath())) {
                $accountStatus = '🍪 Sesi Aktif';
            } elseif (! empty($u->maganghub_email)) {
                $accountStatus = '🔑 '.$u->maganghub_email;
            }

            return [
                $u->id,
                $u->name,
                $u->phone_number,
                $u->whatsapp_lid ?: '-',
                $accountStatus,
                $u->is_active ? '✅ Aktif' : '❌ Nonaktif',
                $u->notes ?: '-',
                $u->created_at->format('Y-m-d H:i'),
            ];
        });

        $this->table(['ID', 'Nama', 'Nomor WA', 'WhatsApp LID', 'MagangHub Portal', 'Status', 'Catatan', 'Didaftarkan'], $rows);
    }

    $allActive = InternUser::getAllActivePhones();
    $this->line("\nTotal nomor aktif (termasuk .env): ".count($allActive).' nomor: '.implode(', ', $allActive));
})->purpose('Melihat daftar semua nomor WhatsApp yang terdaftar');

// Artisan command: Menonaktifkan atau menghapus nomor user
Artisan::command('app:remove-user {phone}', function (string $phone) {
    $cleanPhone = InternUser::cleanPhoneNumber($phone);
    $user = InternUser::findByPhone($cleanPhone);

    if (! $user) {
        $this->error("Nomor {$cleanPhone} tidak ditemukan di database.");

        return;
    }

    $user->update(['is_active' => false]);
    $this->info("User {$user->name} ({$user->phone_number}) telah dinonaktifkan.");
})->purpose('Menonaktifkan nomor WhatsApp dari pengingat');

// Aliases for user management commands
Artisan::command('user:add {name} {phone} {--lid=} {--notes=}', function (string $name, string $phone) {
    $this->call('app:add-user', [
        'name' => $name,
        'phone' => $phone,
        '--lid' => $this->option('lid'),
        '--notes' => $this->option('notes'),
    ]);
})->purpose('Mendaftarkan peserta magang baru (alias untuk app:add-user)');

Artisan::command('user:list', function () {
    $this->call('app:list-users');
})->purpose('Menampilkan seluruh peserta magang (alias untuk app:list-users)');

Artisan::command('user:remove {phone}', function (string $phone) {
    $this->call('app:remove-user', ['phone' => $phone]);
})->purpose('Menonaktifkan peserta magang (alias untuk app:remove-user)');

// Artisan helper to inspect today's report status
Artisan::command('app:maganghub-status {phone?}', function (?string $phone = null) {
    $now = Carbon::now('Asia/Makassar');
    $today = $now->toDateString();

    $query = DailyReport::query()->whereDate('report_date', $today);
    if (! empty($phone)) {
        $query->where('sender_phone', InternUser::cleanPhoneNumber($phone));
    }

    $reports = $query->latest()->get();

    $this->info("=== STATUS LAPORAN MAGANGHUB ({$today}) ===");
    if ($reports->isEmpty()) {
        $this->warn("Belum ada laporan yang tercatat untuk hari ini ({$today}).");

        return;
    }

    foreach ($reports as $report) {
        $user = InternUser::findByPhone($report->sender_phone);
        $userName = $user ? $user->name : 'Unknown';

        $this->line("\n-------------------------------------------");
        $this->info("User: {$userName} ({$report->sender_phone}) | ID: {$report->id}");
        $this->line("Status: {$report->status}");
        $this->line('Catatan Mentah: '.($report->activity_notes ?: '(Kosong)'));
        $this->line('Aktivitas AI: '.($report->activity_description ?: '(Kosong)'));
        $this->line('Pembelajaran: '.($report->lessons_learned ?: '(Kosong)'));
        $this->line('Kendala: '.($report->challenges ?: '(Kosong)'));
    }
})->purpose('Mengecek status dan isi draf laporan MagangHub hari ini');

// Artisan command: Mengatur email & password akun MagangHub user
Artisan::command('app:set-credentials {phone} {email} {password}', function (string $phone, string $email, string $password) {
    $cleanPhone = InternUser::cleanPhoneNumber($phone);
    $user = InternUser::findByPhone($cleanPhone);

    if (! $user) {
        $this->error("User dengan nomor {$cleanPhone} tidak ditemukan.");

        return 1;
    }

    $user->update([
        'maganghub_email' => $email,
        'maganghub_password' => $password,
    ]);

    $this->info("✅ Kredensial MagangHub untuk {$user->name} ({$user->phone_number}) berhasil disimpan!");
    $this->line("Email: {$email}");
})->purpose('Menyimpan atau memperbarui email dan password MagangHub user');

// Artisan command: Login headless otomatis menggunakan email & password akun MagangHub/Kemnaker
Artisan::command('app:maganghub-login-headless {phone} {email?} {password?}', function (string $phone, ?string $email = null, ?string $password = null) {
    $cleanPhone = InternUser::cleanPhoneNumber($phone);
    $user = InternUser::findByPhone($cleanPhone);

    if (! $user) {
        $user = InternUser::query()->create([
            'name' => 'Peserta',
            'phone_number' => $cleanPhone,
            'is_active' => true,
        ]);
        $this->info("User baru otomatis dibuat untuk nomor {$cleanPhone}.");
    }

    $loginEmail = $email ?: $user->maganghub_email;
    $loginPassword = $password ?: $user->maganghub_password;

    if (empty($loginEmail) && $this->input->isInteractive()) {
        $loginEmail = (string) $this->ask('Masukkan Email atau No. HP akun Kemnaker: ');
    }

    if (empty($loginPassword) && $this->input->isInteractive()) {
        $loginPassword = (string) $this->secret('Masukkan Password akun Kemnaker: ');
    }

    if (empty($loginEmail) || empty($loginPassword)) {
        $this->error('Email dan password Kemnaker diperlukan.');
        $this->line("Contoh: php artisan app:maganghub-login-headless {$cleanPhone} user@email.com sandi123");

        return 1;
    }

    $this->info("🚀 Menjalankan headless login MagangHub untuk {$user->name} ({$cleanPhone}) dengan email: {$loginEmail}...");
    $this->line('Script sedang membuka portal Kemnaker di latar belakang (tanpa GUI / X server), memasukkan kredensial, dan mengarahkan ke dashboard...');

    $automator = app(MagangHubAutomationService::class);
    $waha = app(WahaService::class);

    $result = $automator->loginHeadless($user, $loginEmail, $loginPassword);

    if ($result['success']) {
        $this->info('✅ Login headless berhasil! Sesi dan profil browser MagangHub telah tersimpan.');

        if (! empty($result['screenshot']) && file_exists((string) $result['screenshot'])) {
            $this->line("📸 Screenshot bukti login: {$result['screenshot']}");

            $waha->sendImage(
                $cleanPhone,
                (string) $result['screenshot'],
                '📸 Bukti Berhasil Login Dashboard MagangHub'
            );
        }

        $waha->sendMessage(
            $cleanPhone,
            "🎉 *Akun MagangHub Berhasil Terhubung!*\n\n"
            ."Email: {$loginEmail}\n"
            ."Status: Sesi Login Aktif & Tersimpan\n\n"
            .'Mulai sekarang, bot akan otomatis mengisi dan mengirimkan laporan harian Anda ke portal MagangHub setiap kali Anda membalas *KIRIM*. Anda tidak perlu lagi login manual menggunakan laptop! 🚀'
        );

        $this->info("✅ Notifikasi dan bukti screenshot berhasil dikirimkan ke WhatsApp {$cleanPhone}!");

        return 0;
    }

    $this->error('❌ Gagal login: '.($result['error'] ?? 'Terjadi kesalahan pada proses browser headless.'));

    return 1;
})->purpose('Melakukan login otomatis tanpa GUI (headless) menggunakan email & password Kemnaker dan menyimpan sesi');

Artisan::command('app:maganghub-login {phone} {email?} {password?}', function (string $phone, ?string $email = null, ?string $password = null) {
    return $this->call('app:maganghub-login-headless', [
        'phone' => $phone,
        'email' => $email,
        'password' => $password,
    ]);
})->purpose('Alias untuk app:maganghub-login-headless');

// Artisan command: Logout dan hapus sesi MagangHub user
Artisan::command('magang:logout {phone?}', function (?string $phone = null) {
    $targetPhone = null;

    if (! empty($phone)) {
        $targetPhone = InternUser::cleanPhoneNumber($phone);
    } else {
        $users = InternUser::query()->get();
        if ($users->isEmpty()) {
            $this->warn('Belum ada user yang terdaftar di database.');

            return 0;
        } elseif ($users->count() === 1) {
            $targetPhone = $users->first()->phone_number;
        } else {
            $options = $users->mapWithKeys(fn ($u) => [$u->phone_number => "{$u->name} ({$u->phone_number})"])->toArray();
            $targetPhone = $this->choice('Pilih peserta magang untuk logout:', $options);
        }
    }

    $cleanPhone = InternUser::cleanPhoneNumber($targetPhone);
    $user = InternUser::findByPhone($cleanPhone);

    if (! $user) {
        $this->error("User dengan nomor {$cleanPhone} tidak ditemukan di database.");

        return 1;
    }

    $user->clearMagangSession();

    $this->info("✅ Berhasil logout! Seluruh tiket sesi (cookies & profil) untuk {$user->name} ({$cleanPhone}) telah dihapus dari server.");

    // Send confirmation to WhatsApp if waha available
    try {
        $waha = app(WahaService::class);
        $waha->sendMessage(
            $user->getPreferredChatId(),
            "🚪 *Sesi MagangHub Dihapus*\n\n"
            ."Sesi akun Anda telah dihapus dari server.\n\n"
            .'Untuk menghubungkan kembali, silakan ketik pesan: *LOGIN email password*.'
        );
    } catch (Throwable) {
        // ignore if offline
    }

    return 0;
})->purpose('Menghapus tiket sesi login dan profil browser MagangHub user');

Artisan::command('app:maganghub-logout {phone?}', function (?string $phone = null) {
    return $this->call('magang:logout', ['phone' => $phone]);
})->purpose('Alias untuk magang:logout');

// Artisan command: Trigger langsung submit headless browser untuk hari ini
Artisan::command('app:submit-report {phone} {--preview}', function (string $phone) {
    $cleanPhone = InternUser::cleanPhoneNumber($phone);
    $today = Carbon::now('Asia/Makassar')->toDateString();

    $report = DailyReport::query()
        ->where('sender_phone', $cleanPhone)
        ->whereDate('report_date', $today)
        ->latest()
        ->first();

    if (! $report || empty($report->activity_description)) {
        $this->error("Tidak ditemukan draf laporan aktif untuk hari ini ({$today}) bagi nomor {$cleanPhone}.");

        return 1;
    }

    $preview = (bool) $this->option('preview');
    $this->info('Memproses '.($preview ? 'pratinjau pengisian' : 'pengiriman')." laporan otomatis untuk {$cleanPhone}...");
    SubmitMaganghubReport::dispatchSync($report, submitForm: ! $preview);
    $this->info('✅ Proses pengiriman laporan selesai dijalankan.');
})->purpose('Mengirim draf laporan hari ini ke MagangHub menggunakan headless browser');

// Artisan command: Cek status sesi login MagangHub untuk user
Artisan::command('app:check-session {phone?}', function (?string $phone = null) {
    $phones = [];
    if (! empty($phone)) {
        $phones[] = InternUser::cleanPhoneNumber($phone);
    } else {
        $phones = InternUser::getAllActivePhones();
    }

    if (empty($phones)) {
        $this->warn('Tidak ada user aktif yang terdaftar.');

        return;
    }

    $this->info('=== STATUS SESI LOGIN MAGANGHUB ===');

    foreach ($phones as $p) {
        $user = InternUser::findByPhone($p);
        $name = $user ? $user->name : 'Unknown';

        $sessionFile = storage_path("app/maganghub_sessions/{$p}.json");
        $profileDir = storage_path("app/maganghub_profiles/{$p}");

        $hasSessionFile = file_exists($sessionFile);
        $hasProfileDir = is_dir($profileDir) && file_exists("{$profileDir}/Default");

        $this->line("\n-------------------------------------------");
        $this->info("User: {$name} ({$p})");
        $this->line("• Lokasi Profil Browser: {$profileDir}");
        $this->line('  Status: '.($hasProfileDir ? '✅ Ditemukan (Folder Default terdeteksi)' : '❌ Tidak ditemukan / Kosong'));

        $this->line("• Lokasi File Session: {$sessionFile}");
        $this->line('  Status: '.($hasSessionFile ? '✅ Ditemukan (Update: '.date('Y-m-d H:i:s', filemtime($sessionFile)).')' : '❌ Tidak ditemukan'));

        if ($hasProfileDir || $hasSessionFile) {
            $this->info('👉 KESIMPULAN: ✅ Sesi MagangHub SIAP DIGUNAKAN untuk otomasi.');
        } else {
            $this->error('👉 KESIMPULAN: ❌ Sesi BELUM TERSEDIA. Silakan salin folder profil dari laptop.');
        }
    }
})->purpose('Mengecek ketersediaan profil dan sesi login MagangHub');

// Artisan command: Test browser headless membuka dashboard MagangHub & kirim screenshot ke WA
Artisan::command('app:test-maganghub-browser {phone?}', function (?string $phone = null) {
    $targetPhone = $phone ? InternUser::cleanPhoneNumber($phone) : (InternUser::getAllActivePhones()[0] ?? null);

    if (! $targetPhone) {
        $this->error('Nomor telepon tidak ditentukan dan tidak ada user aktif di sistem.');

        return 1;
    }

    $user = InternUser::findByPhone($targetPhone);
    $userName = $user ? $user->name : 'Peserta';

    $this->info("🚀 Menjalankan headless browser untuk mengecek login dashboard MagangHub bagi {$userName} ({$targetPhone})...");

    $automator = app(MagangHubAutomationService::class);
    $waha = app(WahaService::class);

    $result = $automator->testDashboard($targetPhone);

    if ($result['success'] && ! empty($result['screenshot']) && file_exists((string) $result['screenshot'])) {
        $this->info("✅ Berhasil login dan mengambil screenshot dashboard: {$result['screenshot']}");

        $this->line("Mengirimkan screenshot bukti ke WhatsApp {$targetPhone}...");
        $now = Carbon::now('Asia/Makassar')->format('d M Y H:i:s');

        $sentImage = $waha->sendImage(
            $targetPhone,
            (string) $result['screenshot'],
            "📸 Bukti Uji Otomasi Browser: Dashboard MagangHub ({$now} WITA)"
        );

        $waha->sendMessage(
            $targetPhone,
            "🎉 *Pengujian Browser Headless Berhasil!*\n\n"
            ."User: {$userName}\n"
            ."Waktu: {$now} WITA\n\n"
            .'Browser berhasil membuka dashboard MagangHub dengan profil sesi Anda dan mengambil screenshot halaman secara penuh.'
        );

        if ($sentImage) {
            $this->info("✅ Screenshot berhasil dikirim ke WhatsApp {$targetPhone}!");
        } else {
            $this->warn('⚠️ Screenshot berhasil diambil tapi gagal terkirim ke WhatsApp. Pastikan WAHA aktif.');
        }

        return 0;
    }

    $this->error('❌ Gagal membuka dashboard MagangHub: '.($result['error'] ?? 'Terjadi kendala pada browser headless.'));

    return 1;
})->purpose('Menguji browser headless login ke dashboard MagangHub dan mengirim screenshot bukti ke WhatsApp');
