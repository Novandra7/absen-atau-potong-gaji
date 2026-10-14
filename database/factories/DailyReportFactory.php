<?php

namespace Database\Factories;

use App\Models\DailyReport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyReport>
 */
class DailyReportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'report_date' => fake()->date(),
            'sender_phone' => '6281234567890',
            'activity_notes' => 'Mengerjakan fitur auth dan integrasi API',
            'activity_description' => 'Menyelesaikan modul autentikasi dan integrasi API.',
            'lessons_learned' => 'Memahami penanganan webhook dan state machine.',
            'challenges' => 'Tidak ada kendala signifikan.',
            'status' => DailyReport::STATUS_AWAITING_APPROVAL,
            'waha_last_message_id' => fake()->uuid(),
        ];
    }

    public function pendingInput(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DailyReport::STATUS_PENDING_INPUT,
            'activity_description' => null,
            'lessons_learned' => null,
            'challenges' => null,
        ]);
    }

    public function awaitingApproval(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DailyReport::STATUS_AWAITING_APPROVAL,
        ]);
    }

    public function filled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DailyReport::STATUS_AWAITING_FINAL_REVIEW,
            'approved_at' => now(),
            'filled_at' => now(),
            'screenshot_path' => 'screenshots/test.png',
        ]);
    }

    public function submitted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DailyReport::STATUS_SUBMITTED,
            'approved_at' => now(),
            'filled_at' => now(),
            'submitted_at' => now(),
        ]);
    }
}
