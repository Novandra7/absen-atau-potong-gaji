<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsAppReminder;
use App\Models\DailyReport;
use App\Models\InternUser;
use App\Services\WahaService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Mockery;
use Tests\TestCase;

class MultiUserReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.waha.webhook_secret', 'test_secret');
        Config::set('services.waha.user_phone', null);
        Config::set('services.waha.user_phones', null);
    }

    public function test_it_sends_reminders_to_all_active_users(): void
    {
        $user1 = InternUser::factory()->create([
            'name' => 'Awang',
            'phone_number' => '6281111111111',
            'is_active' => true,
        ]);

        $user2 = InternUser::factory()->create([
            'name' => 'Arif',
            'phone_number' => '6282222222222',
            'is_active' => true,
        ]);

        $inactiveUser = InternUser::factory()->create([
            'name' => 'Budi Inactive',
            'phone_number' => '6283333333333',
            'is_active' => false,
        ]);

        $sentPhones = [];
        $wahaMock = Mockery::mock(WahaService::class);
        $wahaMock->shouldReceive('sendMessage')
            ->twice()
            ->andReturnUsing(function ($phone, $message) use (&$sentPhones) {
                $sentPhones[] = $phone;

                return true;
            });

        $job = new SendWhatsAppReminder;
        $job->handle($wahaMock);

        $this->assertContains('6281111111111', $sentPhones);
        $this->assertContains('6282222222222', $sentPhones);
        $this->assertNotContains('6283333333333', $sentPhones);
    }

    public function test_it_skips_reminder_for_user_who_already_submitted_today(): void
    {
        $today = Carbon::now('Asia/Makassar')->toDateString();

        $user1 = InternUser::factory()->create([
            'name' => 'Awang',
            'phone_number' => '6281111111111',
            'is_active' => true,
        ]);

        $user2 = InternUser::factory()->create([
            'name' => 'Arif',
            'phone_number' => '6282222222222',
            'is_active' => true,
        ]);

        // User 1 already submitted today
        DailyReport::factory()->create([
            'sender_phone' => '6281111111111',
            'report_date' => $today,
            'status' => DailyReport::STATUS_SUBMITTED,
        ]);

        $sentPhones = [];
        $wahaMock = Mockery::mock(WahaService::class);
        $wahaMock->shouldReceive('sendMessage')
            ->once()
            ->andReturnUsing(function ($phone, $message) use (&$sentPhones) {
                $sentPhones[] = $phone;

                return true;
            });

        $job = new SendWhatsAppReminder;
        $job->handle($wahaMock);

        // Only user 2 should receive reminder
        $this->assertNotContains('6281111111111', $sentPhones);
        $this->assertContains('6282222222222', $sentPhones);
    }

    public function test_webhook_isolates_reports_between_different_users(): void
    {
        $today = Carbon::now('Asia/Makassar')->toDateString();

        $user1 = InternUser::factory()->create([
            'name' => 'Awang',
            'phone_number' => '6281111111111',
            'is_active' => true,
        ]);

        $user2 = InternUser::factory()->create([
            'name' => 'Arif',
            'phone_number' => '6282222222222',
            'is_active' => true,
        ]);

        $report1 = DailyReport::factory()->create([
            'sender_phone' => '6281111111111',
            'report_date' => $today,
            'status' => DailyReport::STATUS_AWAITING_APPROVAL,
        ]);

        $report2 = DailyReport::factory()->create([
            'sender_phone' => '6282222222222',
            'report_date' => $today,
            'status' => DailyReport::STATUS_AWAITING_APPROVAL,
        ]);

        $wahaMock = Mockery::mock(WahaService::class);
        $wahaMock->shouldReceive('sendMessage')->andReturn(true);
        $this->app->instance(WahaService::class, $wahaMock);

        // User 1 sends SELESAI
        $response = $this->withHeaders(['X-Api-Key' => 'test_secret'])
            ->postJson('/api/webhooks/waha', [
                'event' => 'message',
                'payload' => [
                    'from' => '6281111111111@c.us',
                    'fromMe' => false,
                    'body' => 'SELESAI',
                    'id' => 'msg_1',
                ],
            ]);

        $response->assertStatus(200)->assertJson(['status' => 'submitted_confirmed']);

        // Assert report1 is SUBMITTED, but report2 is still AWAITING_APPROVAL
        $this->assertEquals(DailyReport::STATUS_SUBMITTED, $report1->fresh()->status);
        $this->assertEquals(DailyReport::STATUS_AWAITING_APPROVAL, $report2->fresh()->status);
    }
}
