<?php

namespace App\Jobs;

use App\Models\AutomationLog;
use App\Models\DailyReport;
use App\Models\InternUser;
use App\Services\MagangHubAutomationService;
use App\Services\ScriptBuilderService;
use App\Services\WahaService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

class SubmitMaganghubReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 200;

    public function __construct(
        public DailyReport $report,
        public bool $submitForm = true
    ) {}

    /**
     * Execute the job.
     */
    public function handle(
        MagangHubAutomationService $automator,
        WahaService $waha,
        ScriptBuilderService $scriptBuilder
    ): void {
        $this->report->update([
            'status' => DailyReport::STATUS_FILLING,
        ]);

        $user = InternUser::findByPhoneOrLid($this->report->sender_phone);
        $target = $user ? $user->phone_number : $this->report->sender_phone;
        $reportDate = $this->report->report_date ? $this->report->report_date->toDateString() : Carbon::now('Asia/Makassar')->toDateString();

        $result = $automator->submitReport($this->report, submitForm: $this->submitForm);

        if ($result['success']) {
            if ($this->submitForm) {
                $this->report->update([
                    'status' => DailyReport::STATUS_SUBMITTED,
                    'submitted_at' => now(),
                    'screenshot_path' => $result['screenshot'],
                    'error_message' => null,
                ]);

                AutomationLog::query()->create([
                    'daily_report_id' => $this->report->id,
                    'action' => 'headless_submitted',
                    'details' => [
                        'screenshot' => $result['screenshot'],
                        'message' => $result['message'],
                    ],
                ]);

                if (! empty($result['screenshot']) && file_exists((string) $result['screenshot'])) {
                    $waha->sendImage(
                        $target,
                        (string) $result['screenshot'],
                        "📸 Bukti Pengisian Otomatis Portal MagangHub ({$reportDate})"
                    );
                }

                $waha->sendMessage(
                    $target,
                    "🎉 *Laporan MagangHub Berhasil Terkirim!*\n\n"
                    ."Tanggal: {$reportDate}\n"
                    ."Status: Terkirim & Tercatat Resmi\n\n"
                    .'Seluruh formulir telah diisi dan disubmit otomatis ke portal MagangHub. Selamat beristirahat!'
                );
            } else {
                $this->report->update([
                    'status' => DailyReport::STATUS_AWAITING_APPROVAL,
                    'screenshot_path' => $result['screenshot'],
                    'error_message' => null,
                ]);

                if (! empty($result['screenshot']) && file_exists((string) $result['screenshot'])) {
                    $waha->sendImage(
                        $target,
                        (string) $result['screenshot'],
                        "📸 Pratinjau Pengisian Portal MagangHub ({$reportDate})"
                    );
                }

                $waha->sendMessage(
                    $target,
                    "🔍 *Pratinjau Pengisian MagangHub Berhasil!*\n\n"
                    ."Tanggal: {$reportDate}\n"
                    ."Status: Draf Terisi (Belum Disimpan)\n\n"
                    .'Ketik *KIRIM* jika ingin mengirimkan secara permanen ke MagangHub.'
                );
            }
        } else {
            $this->report->update([
                'status' => DailyReport::STATUS_FAILED,
                'error_message' => $result['error'],
            ]);

            AutomationLog::query()->create([
                'daily_report_id' => $this->report->id,
                'action' => 'headless_failed',
                'details' => [
                    'error' => $result['error'],
                ],
            ]);

            // Build console script as fallback
            $script = $scriptBuilder->build(
                (string) $this->report->activity_description,
                (string) $this->report->lessons_learned,
                (string) $this->report->challenges
            );

            $errorMsg = $result['error'] ?? 'Terjadi kendala saat menghubungkan ke portal MagangHub.';
            $isSessionError = str_contains((string) $errorMsg, 'Sesi login');
            $sessionAdvice = $isSessionError
                ? "💡 *Solusi Login Manual:*\nJalankan perintah ini sekali di laptop/server untuk login & simpan sesi:\n`php artisan app:maganghub-login {$target}`\n\n"
                : '';

            $waha->sendMessage(
                $target,
                "⚠️ *Pengisian Otomatis ke MagangHub Menemui Kendala*\n\n"
                ."Alasan: {$errorMsg}\n\n"
                .$sessionAdvice
                ."💡 *Cadangan Script Console:*\n"
                .'Berikut script jika kamu ingin mengisinya langsung via browser PC/Laptop:'
            );

            $waha->sendMessage($target, $script);

            $waha->sendMessage(
                $target,
                "🌐 *Dashboard MagangHub:*\nhttps://monev.maganghub.kemnaker.go.id/dashboard\n\n"
                .'Balas *KIRIM* untuk mencoba kirim ulang, atau balas *SELESAI* jika kamu telah mengisinya secara manual.'
            );
        }
    }
}
