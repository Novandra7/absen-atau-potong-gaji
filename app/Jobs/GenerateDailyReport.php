<?php

namespace App\Jobs;

use App\Models\AutomationLog;
use App\Models\DailyReport;
use App\Models\InternUser;
use App\Services\ReportGeneratorService;
use App\Services\ScriptBuilderService;
use App\Services\WahaService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateDailyReport implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public DailyReport $report,
        public ?string $revisionInstruction = null
    ) {}

    /**
     * Execute the job.
     */
    public function handle(
        ReportGeneratorService $generator,
        ScriptBuilderService $scriptBuilder,
        WahaService $waha
    ): void {
        $this->report->update([
            'status' => DailyReport::STATUS_GENERATING_DRAFT,
        ]);

        $previousDraft = null;
        if (! empty($this->revisionInstruction) && ! empty($this->report->activity_description)) {
            $previousDraft = "Uraian Aktivitas:\n".$this->report->activity_description."\n\n"
                ."Pembelajaran:\n".$this->report->lessons_learned."\n\n"
                ."Kendala:\n".$this->report->challenges;
        }

        $draft = $generator->generateDraft(
            rawNotes: (string) $this->report->activity_notes,
            previousDraft: $previousDraft,
            revisionInstruction: $this->revisionInstruction
        );

        // Build the console automation script
        $script = $scriptBuilder->build(
            $draft['uraianAktivitas'],
            $draft['pembelajaran'],
            $draft['kendala']
        );

        $this->report->update([
            'activity_description' => $draft['uraianAktivitas'],
            'lessons_learned' => $draft['pembelajaran'],
            'challenges' => $draft['kendala'],
            'status' => DailyReport::STATUS_AWAITING_APPROVAL,
        ]);

        AutomationLog::query()->create([
            'daily_report_id' => $this->report->id,
            'action' => ! empty($this->revisionInstruction) ? 'draft_revised' : 'draft_generated',
            'details' => [
                'draft' => $draft,
                'revision' => $this->revisionInstruction,
            ],
        ]);

        // Resolve WhatsApp target (always send to user's canonical phone number for reliable delivery)
        $user = InternUser::findByPhoneOrLid($this->report->sender_phone);
        $target = $user ? $user->phone_number : $this->report->sender_phone;

        // Send directly the ready-to-copy JavaScript script
        $waha->sendMessage($target, $script);

        // Send clickable dashboard link and brief instructions
        $waha->sendMessage(
            $target,
            "🌐 *Dashboard MagangHub:*\nhttps://monev.maganghub.kemnaker.go.id/dashboard\n\n"
            ."💡 *Langkah Pengisian:*\n1. Buka link dashboard di atas pada browser laptop/PC\n2. Tekan tombol *F12* (atau klik kanan -> Inspect -> tab Console)\n3. Paste script di atas dan tekan *Enter*"
        );
    }
}
