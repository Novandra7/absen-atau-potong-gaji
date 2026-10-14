<?php

namespace Database\Seeders;

use App\Models\DailyReport;
use Illuminate\Database\Seeder;

class DailyReportSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $report = DailyReport::query()->create([
            'report_date' => now()->toDateString(),
            'sender_phone' => (string) config('services.waha.user_phone', '6281234567890'),
            'activity_notes' => 'Setup sistem otomasi dan integrasi webhook',
            'activity_description' => 'Mengonfigurasi backend Laravel, database migration, dan service webhook WhatsApp.',
            'lessons_learned' => 'Memahami arsitektur state machine asynchronous dengan event webhook.',
            'challenges' => 'Tidak ada kendala signifikan.',
            'status' => DailyReport::STATUS_AWAITING_APPROVAL,
        ]);

        $report->logs()->create([
            'action' => 'draft_generated',
            'details' => ['source' => 'seeder'],
        ]);
    }
}
