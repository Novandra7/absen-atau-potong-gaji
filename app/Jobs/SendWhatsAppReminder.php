<?php

namespace App\Jobs;

use App\Models\AutomationLog;
use App\Models\DailyReport;
use App\Models\InternUser;
use App\Services\WahaService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SendWhatsAppReminder implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public ?string $targetPhone = null,
        public bool $force = false
    ) {}

    /**
     * Execute the job.
     */
    public function handle(WahaService $waha): void
    {
        $phones = ! empty($this->targetPhone)
            ? [InternUser::cleanPhoneNumber($this->targetPhone)]
            : InternUser::getAllActivePhones();

        if (empty($phones)) {
            Log::warning('SendWhatsAppReminder: No registered phone numbers found.');

            return;
        }

        $now = Carbon::now('Asia/Makassar');
        $today = $now->toDateString();
        $indonesianDate = $now->locale('id')->translatedFormat('l, d F Y');

        foreach ($phones as $phone) {
            $user = InternUser::findByPhone($phone);
            $nameGreeting = $user ? "Halo *{$user->name}*! " : 'Halo! ';

            // Check if report is already in progress or completed
            $existingReport = DailyReport::query()
                ->where('sender_phone', $phone)
                ->whereDate('report_date', $today)
                ->first();

            if (! $this->force && $existingReport && in_array($existingReport->status, [
                DailyReport::STATUS_SUBMITTED,
                DailyReport::STATUS_AWAITING_APPROVAL,
            ], true)) {
                Log::info("SendWhatsAppReminder: Report for {$phone} on {$today} already in progress or completed ({$existingReport->status}). Skipping.");

                continue;
            }

            if ($existingReport) {
                $report = $existingReport;
                $report->update([
                    'status' => DailyReport::STATUS_PENDING_INPUT,
                ]);
            } else {
                $report = DailyReport::query()->create([
                    'report_date' => $today,
                    'sender_phone' => $phone,
                    'status' => DailyReport::STATUS_PENDING_INPUT,
                ]);
            }

            $message = "⏰ *Pengingat Laporan Harian MagangHub*\n"
                ."{$nameGreeting}Waktunya mencatat laporan magang hari ini:\n"
                ."📅 *{$indonesianDate}*\n\n"
                ."Jangan lupa catat aktivitas magangmu hari ini ya!\n\n"
                ."Cukup balas pesan ini dengan catatan apa saja yang kamu kerjakan hari ini, AI akan otomatis menyusunnya dan menyiapkan script console pengisian otomatis.\n\n"
                ."🌐 *Link Dashboard MagangHub:*\nhttps://monev.maganghub.kemnaker.go.id/dashboard";

            $target = $user ? $user->getPreferredChatId() : $phone;
            $sent = $waha->sendMessage($target, $message);

            AutomationLog::query()->create([
                'daily_report_id' => $report->id,
                'action' => 'reminder_sent',
                'details' => [
                    'target_phone' => $phone,
                    'user_name' => $user?->name,
                    'sent_success' => $sent,
                    'time_wita' => $now->toDateTimeString(),
                ],
            ]);
        }
    }
}
