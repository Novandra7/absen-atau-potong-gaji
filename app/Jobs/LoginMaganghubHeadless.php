<?php

namespace App\Jobs;

use App\Models\InternUser;
use App\Services\MagangHubAutomationService;
use App\Services\WahaService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class LoginMaganghubHeadless implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public int $uniqueFor = 180;

    public function uniqueId(): string
    {
        return 'login_maganghub_'.strtolower($this->email);
    }

    public function __construct(
        public InternUser|string $userOrPhone,
        public string $email,
        public string $password,
        public ?string $replyTarget = null
    ) {}

    /**
     * Execute the job.
     */
    public function handle(
        MagangHubAutomationService $automator,
        WahaService $waha
    ): void {
        $runningLockKey = 'login_maganghub_running_'.strtolower($this->email);
        if (! Cache::add($runningLockKey, true, now()->addSeconds(180))) {
            Log::warning("LoginMaganghubHeadless: Job already running for {$this->email}, skipping duplicate.");

            return;
        }

        try {
            $phone = $this->userOrPhone instanceof InternUser
                ? $this->userOrPhone->phone_number
                : InternUser::cleanPhoneNumber($this->userOrPhone);

            $user = $this->userOrPhone instanceof InternUser
                ? $this->userOrPhone
                : InternUser::findByPhone($phone);

            $target = $this->replyTarget ?: ($user ? $user->getPreferredChatId() : $phone);
            $now = Carbon::now('Asia/Makassar')->format('d M Y H:i:s');

            Log::info("LoginMaganghubHeadless: Starting login for {$phone} ({$this->email})");

            $result = $automator->loginHeadless($this->userOrPhone, $this->email, $this->password);

            if ($result['success']) {
                Log::info("LoginMaganghubHeadless: Login success for {$phone}");

                $userName = $user ? $user->name : 'Peserta';

                if (! empty($result['screenshot']) && file_exists((string) $result['screenshot'])) {
                    $waha->sendImage(
                        $target,
                        (string) $result['screenshot'],
                        "📸 Bukti Berhasil Login Dashboard MagangHub ({$now} WITA)"
                    );
                }

                $waha->sendMessage(
                    $target,
                    "🎉 *Akun MagangHub Berhasil Terhubung!*\n\n"
                    ."User: {$userName}\n"
                    ."Email: {$this->email}\n"
                    ."Waktu: {$now} WITA\n"
                    ."Status: Sesi Login Aktif & Tersimpan\n\n"
                    .'Mulai sekarang, bot akan otomatis mengisi dan mengirimkan laporan harian Anda ke portal MagangHub setiap kali Anda membalas *KIRIM*. Anda tidak perlu lagi login manual menggunakan laptop! 🚀'
                );
            } else {
                Log::warning("LoginMaganghubHeadless: Login failed for {$phone}: ".($result['error'] ?? 'Unknown error'));

                if (! empty($result['screenshot']) && file_exists((string) $result['screenshot'])) {
                    $waha->sendImage(
                        $target,
                        (string) $result['screenshot'],
                        "⚠️ Tangkapan Layar Kendala Login Kemnaker ({$now} WITA)"
                    );
                }

                $errorMsg = $result['error'] ?? 'Terjadi kesalahan saat mencoba login ke portal Kemnaker.';

                $waha->sendMessage(
                    $target,
                    "❌ *Gagal Menghubungkan Akun MagangHub*\n\n"
                    ."Email: {$this->email}\n"
                    ."Alasan: {$errorMsg}\n\n"
                    ."Silakan periksa kembali email dan password akun Kemnaker Anda.\n\n"
                    ."Untuk mencoba lagi, kirimkan pesan dengan format:\n"
                    .'*LOGIN email password*'
                );
            }
        } finally {
            Cache::forget($runningLockKey);
        }
    }
}
