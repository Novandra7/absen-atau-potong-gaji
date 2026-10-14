<?php

namespace Tests\Feature;

use App\Jobs\GenerateDailyReport;
use App\Models\DailyReport;
use App\Models\InternUser;
use App\Services\WahaService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class WahaWebhookFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.waha.user_phone', '6281234567890');
        Config::set('services.waha.webhook_secret', 'test_secret');

        // Mock WahaService so no real HTTP calls are made during tests
        $wahaMock = Mockery::mock(WahaService::class);
        $wahaMock->shouldReceive('sendMessage')->andReturn(true);
        $this->app->instance(WahaService::class, $wahaMock);
    }

    public function test_it_rejects_unauthorized_webhook_request(): void
    {
        $response = $this->postJson('/api/webhooks/waha', [
            'event' => 'message',
            'payload' => ['body' => 'test'],
        ]);

        $response->assertStatus(401);
    }

    public function test_it_ignores_outgoing_bot_messages_from_me(): void
    {
        $response = $this->withHeaders(['X-Api-Key' => 'test_secret'])
            ->postJson('/api/webhooks/waha', [
                'event' => 'message',
                'payload' => [
                    'fromMe' => true,
                    'body' => 'Pesan dari bot sendiri',
                ],
            ]);

        $response->assertStatus(200)
            ->assertJson(['status' => 'ignored_from_me']);
    }

    public function test_it_ignores_messages_for_different_session(): void
    {
        Config::set('services.waha.session', 'maganghub');

        $response = $this->withHeaders(['X-Api-Key' => 'test_secret'])
            ->postJson('/api/webhooks/waha', [
                'event' => 'message',
                'session' => 'kref',
                'payload' => [
                    'from' => '6281234567890@c.us',
                    'fromMe' => false,
                    'body' => 'Catatan aktivitas harian saya',
                    'id' => 'msg_different_session',
                ],
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'ignored_different_session',
                'session' => 'kref',
            ]);
    }

    public function test_it_ignores_messages_from_unauthorized_sender(): void
    {
        $response = $this->withHeaders(['X-Api-Key' => 'test_secret'])
            ->postJson('/api/webhooks/waha', [
                'event' => 'message',
                'payload' => [
                    'from' => '628999999999@c.us',
                    'fromMe' => false,
                    'body' => 'Catatan aktivitas harian saya',
                    'id' => 'msg_123',
                ],
            ]);

        $response->assertStatus(200)
            ->assertJson(['status' => 'ignored_unauthorized_sender']);
    }

    public function test_it_receives_notes_from_authorized_user_and_dispatches_job(): void
    {
        Queue::fake();

        $response = $this->withHeaders(['X-Api-Key' => 'test_secret'])
            ->postJson('/api/webhooks/waha', [
                'event' => 'message',
                'payload' => [
                    'from' => '6281234567890@c.us',
                    'fromMe' => false,
                    'body' => 'Hari ini saya mengerjakan setup API dan dokumentasi modul auth.',
                    'id' => 'msg_unique_001',
                ],
            ]);

        $response->assertStatus(200)
            ->assertJson(['status' => 'draft_generation_dispatched']);

        $this->assertDatabaseHas('daily_reports', [
            'sender_phone' => '6281234567890',
            'activity_notes' => 'Hari ini saya mengerjakan setup API dan dokumentasi modul auth.',
            'waha_last_message_id' => 'msg_unique_001',
        ]);

        Queue::assertPushed(GenerateDailyReport::class);
    }

    public function test_it_handles_selesai_command(): void
    {
        $today = Carbon::now('Asia/Makassar')->toDateString();

        $report = DailyReport::factory()->create([
            'report_date' => $today,
            'sender_phone' => '6281234567890',
            'status' => DailyReport::STATUS_AWAITING_APPROVAL,
        ]);

        $response = $this->withHeaders(['X-Api-Key' => 'test_secret'])
            ->postJson('/api/webhooks/waha', [
                'event' => 'message',
                'payload' => [
                    'from' => '6281234567890@c.us',
                    'fromMe' => false,
                    'body' => 'SELESAI',
                    'id' => 'msg_selesai_1',
                ],
            ]);

        $response->assertStatus(200)
            ->assertJson(['status' => 'submitted_confirmed']);

        $this->assertDatabaseHas('daily_reports', [
            'id' => $report->id,
            'status' => DailyReport::STATUS_SUBMITTED,
        ]);
    }

    public function test_it_handles_batal_command(): void
    {
        $today = Carbon::now('Asia/Makassar')->toDateString();

        $report = DailyReport::factory()->create([
            'report_date' => $today,
            'sender_phone' => '6281234567890',
            'status' => DailyReport::STATUS_AWAITING_APPROVAL,
        ]);

        $response = $this->withHeaders(['X-Api-Key' => 'test_secret'])
            ->postJson('/api/webhooks/waha', [
                'event' => 'message',
                'payload' => [
                    'from' => '6281234567890@c.us',
                    'fromMe' => false,
                    'body' => 'BATAL',
                    'id' => 'msg_batal_1',
                ],
            ]);

        $response->assertStatus(200)
            ->assertJson(['status' => 'cancelled']);

        $this->assertDatabaseHas('daily_reports', [
            'id' => $report->id,
            'status' => DailyReport::STATUS_CANCELLED,
        ]);
    }

    public function test_it_handles_edit_command_and_dispatches_revision(): void
    {
        Queue::fake();

        $today = Carbon::now('Asia/Makassar')->toDateString();

        $report = DailyReport::factory()->create([
            'report_date' => $today,
            'sender_phone' => '6281234567890',
            'activity_description' => 'Aktivitas awal',
            'status' => DailyReport::STATUS_AWAITING_APPROVAL,
        ]);

        $response = $this->withHeaders(['X-Api-Key' => 'test_secret'])
            ->postJson('/api/webhooks/waha', [
                'event' => 'message',
                'payload' => [
                    'from' => '6281234567890@c.us',
                    'fromMe' => false,
                    'body' => 'EDIT: Tambahkan bahwa saya juga mereview PR tim',
                    'id' => 'msg_edit_1',
                ],
            ]);

        $response->assertStatus(200)
            ->assertJson(['status' => 'revision_dispatched']);

        Queue::assertPushed(GenerateDailyReport::class, function ($job) use ($report) {
            return $job->report->id === $report->id &&
                $job->revisionInstruction === 'Tambahkan bahwa saya juga mereview PR tim';
        });
    }

    public function test_it_handles_message_from_whatsapp_lid(): void
    {
        Queue::fake();

        $today = Carbon::now('Asia/Makassar')->toDateString();

        $user = InternUser::factory()->create([
            'name' => 'LID User',
            'phone_number' => '6285551234567',
            'whatsapp_lid' => '1990999888777',
            'is_active' => true,
        ]);

        $report = DailyReport::factory()->create([
            'report_date' => $today,
            'sender_phone' => $user->phone_number,
            'status' => DailyReport::STATUS_PENDING_INPUT,
        ]);

        $response = $this->withHeaders(['X-Api-Key' => 'test_secret'])
            ->postJson('/api/webhooks/waha', [
                'event' => 'message',
                'payload' => [
                    'from' => '1990999888777@lid',
                    'fromMe' => false,
                    'body' => 'Hari ini saya mengerjakan migrasi skema database.',
                    'id' => 'msg_lid_001',
                ],
            ]);

        $response->assertStatus(200)
            ->assertJson(['status' => 'draft_generation_dispatched']);

        $this->assertDatabaseHas('daily_reports', [
            'id' => $report->id,
            'sender_phone' => '6285551234567',
            'activity_notes' => 'Hari ini saya mengerjakan migrasi skema database.',
        ]);

        Queue::assertPushed(GenerateDailyReport::class);
    }
}
