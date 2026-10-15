<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateDailyReport;
use App\Jobs\LoginMaganghubHeadless;
use App\Jobs\SubmitMaganghubReport;
use App\Models\AutomationLog;
use App\Models\DailyReport;
use App\Models\InternUser;
use App\Services\ScriptBuilderService;
use App\Services\WahaService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class WahaWebhookController extends Controller
{
    /**
     * Handle incoming WAHA webhook events.
     */
    public function __invoke(
        Request $request,
        WahaService $waha,
        ScriptBuilderService $scriptBuilder
    ): JsonResponse {
        // 1. Verify Secret Key if configured
        $secret = config('services.waha.webhook_secret');
        if (! empty($secret)) {
            $headerSecret = $request->header('X-Api-Key') ?? $request->header('X-Webhook-Secret') ?? $request->query('secret');
            if ($headerSecret !== $secret) {
                Log::warning('WahaWebhook: Unauthorized webhook access attempt', [
                    'ip' => $request->ip(),
                ]);

                return response()->json(['error' => 'Unauthorized'], 401);
            }
        }

        // 2. Extract Event & Payload
        $event = $request->input('event', 'message');
        $payload = $request->input('payload') ?? $request->all();

        // Only process incoming message events (strictly 'message', ignore 'message.any' or delivery receipts to avoid duplicate processing)
        if ($event !== 'message') {
            return response()->json(['status' => 'ignored_event', 'event' => $event]);
        }

        // Verify session matches if session is passed in request
        $incomingSession = $request->input('session');
        $configuredSession = config('services.waha.session');
        if (! empty($configuredSession) && ! empty($incomingSession) && $incomingSession !== $configuredSession) {
            Log::info("WahaWebhook: Ignored message for session [{$incomingSession}], expected [{$configuredSession}]");

            return response()->json(['status' => 'ignored_different_session', 'session' => $incomingSession]);
        }

        $fromMe = (bool) ($payload['fromMe'] ?? false);
        if ($fromMe) {
            return response()->json(['status' => 'ignored_from_me']);
        }

        $rawFrom = (string) ($payload['from'] ?? '');
        $senderDigits = preg_replace('/[^0-9]/', '', $rawFrom) ?? '';
        $body = trim((string) ($payload['body'] ?? $payload['_data']['body'] ?? ''));
        $upperBody = strtoupper($body);

        $rawId = $payload['id'] ?? $payload['_data']['id'] ?? $payload['key']['id'] ?? null;
        if (is_array($rawId)) {
            $messageId = (string) ($rawId['_serialized'] ?? $rawId['id'] ?? '');
        } else {
            $messageId = (string) ($rawId ?? '');
        }

        if (empty($body) || empty($senderDigits)) {
            return response()->json(['status' => 'ignored_empty_message']);
        }

        // 3. Whitelist Check (supports phone number and whatsapp_lid)
        if (! InternUser::isPhoneAuthorized($senderDigits, $rawFrom)) {
            Log::info("WahaWebhook: Message from unauthorized sender {$senderDigits} ({$rawFrom}). Ignoring.");

            return response()->json(['status' => 'ignored_unauthorized_sender']);
        }

        $user = InternUser::findByPhoneOrLid($senderDigits, $rawFrom);
        $userPhone = $user?->phone_number ?? $senderDigits;
        $replyTarget = $userPhone;

        // 4. Idempotency Check (Atomic Cache Lock + Database Check)
        if (! empty($messageId)) {
            $alreadyProcessed = DailyReport::query()
                ->where('waha_last_message_id', $messageId)
                ->exists();

            if ($alreadyProcessed) {
                return response()->json(['status' => 'already_processed']);
            }

            // Atomic cache lock to prevent concurrent duplicate webhooks (e.g. 'message' and 'message.any' for same message)
            if (! Cache::add('waha_msg_lock_'.$messageId, true, now()->addMinutes(10))) {
                Log::info("WahaWebhook: Duplicate message [{$messageId}] ignored by atomic cache lock.");

                return response()->json(['status' => 'already_processed_duplicate_lock']);
            }
        }

        // Content & Sender debounce lock (15 seconds):
        // Prevents WAHA double-event delivery (e.g. @c.us vs @lid, 'message' vs 'message.any')
        $contentLockKey = 'waha_sender_body_lock_'.md5($userPhone.'_'.$upperBody);
        if (! Cache::add($contentLockKey, true, now()->addSeconds(15))) {
            Log::info("WahaWebhook: Duplicate content for user [{$userPhone}] ignored within 15s window.");

            return response()->json(['status' => 'already_processed_duplicate_content']);
        }

        // 5. State Machine & Command Dispatcher
        $now = Carbon::now('Asia/Makassar');
        $today = $now->toDateString();

        $findReport = fn () => DailyReport::query()
            ->where(function ($q) use ($userPhone, $senderDigits) {
                $q->where('sender_phone', $userPhone)
                    ->orWhere('sender_phone', $senderDigits);
            })
            ->whereDate('report_date', $today);

        // CASE: HUBUNGKAN / DAFTAR AKUN MAGANGHUB OTOMATIS (HEADLESS)
        if (preg_match('/^(LOGIN|DAFTAR_AKUN|SIMPAN_AKUN|AKUN)\s+([^\s]+)\s+(.+)$/i', $body, $matches)) {
            $email = trim($matches[2]);
            $password = trim($matches[3]);

            // Debounce by email (30s window): Guarantees that only ONE login process runs per email, regardless of webhook retries or LID aliases
            $loginEmailLockKey = 'waha_login_email_lock_'.md5(strtolower($email));
            if (! Cache::add($loginEmailLockKey, true, now()->addSeconds(30))) {
                Log::info("WahaWebhook: Duplicate LOGIN for email [{$email}] ignored within 30s window.");

                return response()->json(['status' => 'already_processed_duplicate_login']);
            }

            if (! $user) {
                $user = InternUser::where('maganghub_email', $email)
                    ->orWhere('phone_number', $userPhone)
                    ->first();
            }

            if (! $user) {
                $user = InternUser::query()->create([
                    'name' => 'Peserta',
                    'phone_number' => $userPhone,
                    'whatsapp_lid' => str_ends_with($rawFrom, '@lid') ? $senderDigits : null,
                    'maganghub_email' => $email,
                    'maganghub_password' => $password,
                    'is_active' => true,
                ]);
            } else {
                $attributes = [
                    'maganghub_email' => $email,
                    'maganghub_password' => $password,
                    'is_active' => true,
                ];
                if (str_ends_with($rawFrom, '@lid') && empty($user->whatsapp_lid)) {
                    $attributes['whatsapp_lid'] = $senderDigits;
                }
                $user->update($attributes);
            }

            $waha->sendMessage(
                $replyTarget,
                "⏳ *Menerima Akun MagangHub!*\n\n"
                ."Email: {$email}\n\n"
                ."Sedang memproses verifikasi login otomatis ke portal Kemnaker di server...\n"
                .'Mohon tunggu sekitar 15-30 detik. Bot akan mengambil screenshot dashboard dan mengirimkan konfirmasi ke sini.'
            );

            LoginMaganghubHeadless::dispatch($user, $email, $password, $replyTarget);

            return response()->json(['status' => 'login_dispatched']);
        }

        // CASE: INFO SESI / AKUN
        if (preg_match('/^(AKUN|SESI|LOGIN)/i', $body)) {
            $hasSession = $user && $user->hasMagangCredentialsOrSession();
            $statusText = $hasSession ? '✅ *Akun / Sesi Aktif Tersimpan*' : '⚠️ *Belum Ada Sesi / Akun*';

            $waha->sendMessage(
                $replyTarget,
                "🔐 *Status Sesi Login MagangHub*\n\n"
                ."Status: {$statusText}\n"
                .($user?->maganghub_email ? "Akun Terdaftar: {$user->maganghub_email}\n" : '')
                ."\n"
                .($hasSession
                    ? "Sesi dan akun kamu sudah aktif dan siap digunakan untuk kirim otomatis (cukup balas *KIRIM*).\n\nJika ingin memperbarui password/akun, ketik:\n`LOGIN email password`"
                    : "📱 *Cara Hubungkan Akun (Langsung dari HP):*\nCukup balas pesan ini dengan format:\n*LOGIN email password*\nContoh:\n`LOGIN nama@gmail.com Password123`\n\nSistem akan otomatis login di server tanpa perlu buka laptop! 🚀")
            );

            return response()->json(['status' => 'session_info_sent', 'has_session' => $hasSession]);
        }

        // CASE 1: KIRIM / SUBMIT (Automated Headless Submission)
        if (in_array($upperBody, ['KIRIM', 'SUBMIT', 'SEND'], true)) {
            $report = $findReport()->latest()->first();

            if (! $report) {
                $waha->sendMessage(
                    $replyTarget,
                    "⚠️ Tidak ditemukan draf laporan aktif untuk hari ini ({$today}). Kirimkan catatan aktivitasmu terlebih dahulu."
                );

                return response()->json(['status' => 'no_report_found']);
            }

            if ($report->status === DailyReport::STATUS_SUBMITTED) {
                $waha->sendMessage(
                    $replyTarget,
                    "ℹ️ Laporan hari ini ({$today}) sudah berstatus *SUBMITTED*."
                );

                return response()->json(['status' => 'already_submitted']);
            }

            // Dispatch automated headless browser submission
            SubmitMaganghubReport::dispatch($report);

            $waha->sendMessage(
                $replyTarget,
                "⏳ *Permintaan pengiriman diterima!* Sedang mengisi dan mengirimkan laporan ke portal MagangHub secara otomatis...\n\nMohon tunggu beberapa saat, bot akan mengirimkan screenshot bukti pengisian."
            );

            return response()->json(['status' => 'submit_dispatched']);
        }

        // CASE 1B: SELESAI / DONE
        if (in_array($upperBody, ['SELESAI', 'DONE'], true)) {
            $report = $findReport()->latest()->first();

            if (! $report) {
                $waha->sendMessage(
                    $replyTarget,
                    "⚠️ Tidak ditemukan laporan aktif untuk hari ini ({$today})."
                );

                return response()->json(['status' => 'no_report_found']);
            }

            // If user has automated credentials/session and report is not yet submitted, trigger auto submit
            if ($user && $user->hasMagangCredentialsOrSession() && $report->status !== DailyReport::STATUS_SUBMITTED) {
                SubmitMaganghubReport::dispatch($report);

                $waha->sendMessage(
                    $replyTarget,
                    "⏳ *Permintaan pengiriman diterima!* Sedang mengisi dan mengirimkan laporan ke portal MagangHub secara otomatis...\n\nMohon tunggu beberapa saat, bot akan mengirimkan screenshot bukti pengisian."
                );

                return response()->json(['status' => 'submit_dispatched']);
            }

            // Manual confirmation fallback
            $report->update([
                'status' => DailyReport::STATUS_SUBMITTED,
                'submitted_at' => now(),
                'waha_last_message_id' => $messageId,
            ]);

            AutomationLog::query()->create([
                'daily_report_id' => $report->id,
                'action' => 'submission_confirmed',
                'details' => ['message_id' => $messageId],
            ]);

            $waha->sendMessage(
                $replyTarget,
                "🎉 *Laporan MagangHub Telah Dikonfirmasi Selesai!*\n\n"
                ."Tanggal: {$today}\n"
                ."Status: Terkirim & Tercatat\n\n"
                .'Kerja bagus hari ini! Selamat beristirahat.'
            );

            return response()->json(['status' => 'submitted_confirmed']);
        }

        // CASE 2: EDIT / REVISI
        if (str_starts_with($upperBody, 'EDIT:') || str_starts_with($upperBody, 'EDIT ') || str_starts_with($upperBody, 'REVISI:')) {
            $report = $findReport()->latest()->first();

            if (! $report || empty($report->activity_description)) {
                $waha->sendMessage(
                    $replyTarget,
                    '⚠️ Tidak ada draf laporan yang dapat direvisi saat ini. Kirimkan catatan aktivitas baru terlebih dahulu.'
                );

                return response()->json(['status' => 'no_draft_for_revision']);
            }

            $instruction = trim(preg_replace('/^(EDIT:|EDIT|REVISI:|REVISI)/i', '', $body) ?? '');

            $report->update([
                'sender_phone' => $userPhone,
                'waha_last_message_id' => $messageId,
            ]);

            // Kirim pesan konfirmasi segera bahwa revisi sedang diproses
            $waha->sendMessage(
                $replyTarget,
                "⏳ *Revisi diterima!* Sedang memproses perbaikan laporan dan memperbarui script...\n\n🌐 *Dashboard MagangHub:*\nhttps://monev.maganghub.kemnaker.go.id/dashboard"
            );

            GenerateDailyReport::dispatch($report, $instruction);

            return response()->json(['status' => 'revision_dispatched']);
        }

        // CASE 3: BATAL
        if ($upperBody === 'BATAL') {
            $report = $findReport()
                ->where('status', '!=', DailyReport::STATUS_SUBMITTED)
                ->latest()
                ->first();

            if ($report) {
                $report->update([
                    'status' => DailyReport::STATUS_CANCELLED,
                    'waha_last_message_id' => $messageId,
                ]);

                AutomationLog::query()->create([
                    'daily_report_id' => $report->id,
                    'action' => 'report_cancelled',
                    'details' => ['message_id' => $messageId],
                ]);

                $waha->sendMessage(
                    $replyTarget,
                    '🛑 Laporan harian hari ini telah dibatalkan. Jika ingin membuat draf baru, cukup kirimkan catatan aktivitasmu kembali.'
                );
            } else {
                $waha->sendMessage($replyTarget, 'ℹ️ Tidak ada laporan aktif yang sedang berjalan untuk dibatalkan.');
            }

            return response()->json(['status' => 'cancelled']);
        }

        // CASE 4: SCRIPT RESEND (jika pengguna minta ulang script)
        if (in_array($upperBody, ['SCRIPT', 'ULANG', 'KIRIM SCRIPT'], true)) {
            $report = $findReport()->latest()->first();

            if ($report && ! empty($report->activity_description)) {
                $script = $scriptBuilder->build(
                    (string) $report->activity_description,
                    (string) $report->lessons_learned,
                    (string) $report->challenges
                );

                $waha->sendMessage($replyTarget, 'Berikut script console laporan harianmu:');
                $waha->sendMessage($replyTarget, $script);

                return response()->json(['status' => 'script_resent']);
            }
        }

        // CASE 5: DAILY ACTIVITY NOTES
        $report = $findReport()->first();

        if ($report && $report->status === DailyReport::STATUS_SUBMITTED) {
            $waha->sendMessage(
                $replyTarget,
                "ℹ️ Laporan hari ini ({$today}) sudah berstatus *SUBMITTED*. Jika ingin memperbarui, silakan ubah status atau hubungi admin."
            );

            return response()->json(['status' => 'already_submitted']);
        }

        if (! $report) {
            $report = DailyReport::query()->create([
                'report_date' => $today,
                'sender_phone' => $userPhone,
                'activity_notes' => $body,
                'status' => DailyReport::STATUS_GENERATING_DRAFT,
                'waha_last_message_id' => $messageId,
            ]);
        } else {
            $report->update([
                'sender_phone' => $userPhone,
                'activity_notes' => $body,
                'status' => DailyReport::STATUS_GENERATING_DRAFT,
                'waha_last_message_id' => $messageId,
            ]);
        }

        AutomationLog::query()->create([
            'daily_report_id' => $report->id,
            'action' => 'activity_received',
            'details' => [
                'raw_notes' => $body,
                'message_id' => $messageId,
                'user_phone' => $userPhone,
                'reply_target' => $replyTarget,
            ],
        ]);

        // Kirim pesan konfirmasi segera bahwa bot sedang memproses
        $waha->sendMessage(
            $replyTarget,
            '⏳ *Catatan diterima!* Sedang memproses laporan harianmu dengan AI dan menyiapkan script otomatisasi...'
        );

        GenerateDailyReport::dispatch($report);

        return response()->json([
            'status' => 'draft_generation_dispatched',
            'report_id' => $report->id,
        ]);
    }
}
