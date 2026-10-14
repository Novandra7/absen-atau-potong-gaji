<?php

use App\Jobs\SendWhatsAppReminder;
use App\Models\DailyReport;
use App\Models\InternUser;
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
Artisan::command('app:add-user {name} {phone} {--lid=} {--notes=}', function (string $name, string $phone) {
    $cleanPhone = InternUser::cleanPhoneNumber($phone);
    $notes = $this->option('notes');
    $lid = $this->option('lid');

    $attributes = [
        'name' => $name,
        'is_active' => true,
        'notes' => $notes,
    ];
    if (! empty($lid)) {
        $attributes['whatsapp_lid'] = preg_replace('/[^0-9]/', '', $lid);
    }

    $user = InternUser::query()->updateOrCreate(
        ['phone_number' => $cleanPhone],
        $attributes
    );

    $this->info("✅ Berhasil mendaftarkan/mengaktifkan user: {$user->name} ({$user->phone_number})".($user->whatsapp_lid ? " [LID: {$user->whatsapp_lid}]" : ''));
})->purpose('Mendaftarkan nomor WhatsApp penerima pengingat baru');

// Artisan command: Menampilkan seluruh user terdaftar
Artisan::command('app:list-users', function () {
    $users = InternUser::query()->orderBy('name')->get();

    $this->info('=== DAFTAR REKAN MAGANG TERDAFTAR ===');

    if ($users->isEmpty()) {
        $this->warn('Belum ada user yang terdaftar di database.');
    } else {
        $rows = $users->map(fn ($u) => [
            $u->id,
            $u->name,
            $u->phone_number,
            $u->whatsapp_lid ?: '-',
            $u->is_active ? '✅ Aktif' : '❌ Nonaktif',
            $u->notes ?: '-',
            $u->created_at->format('Y-m-d H:i'),
        ]);

        $this->table(['ID', 'Nama', 'Nomor WA', 'WhatsApp LID', 'Status', 'Catatan', 'Didaftarkan'], $rows);
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
