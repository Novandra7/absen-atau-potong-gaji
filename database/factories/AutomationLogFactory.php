<?php

namespace Database\Factories;

use App\Models\AutomationLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AutomationLog>
 */
class AutomationLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'daily_report_id' => DailyReport::factory(),
            'action' => 'reminder_sent',
            'details' => ['message' => 'Jangan lupa catat aktivitas magang hari ini.'],
        ];
    }
}
