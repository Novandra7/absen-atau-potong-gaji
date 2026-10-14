<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('daily_reports', function (Blueprint $table) {
            $table->id();
            $table->date('report_date');
            $table->string('sender_phone', 30);
            $table->text('activity_notes')->nullable();
            $table->text('activity_description')->nullable();
            $table->text('lessons_learned')->nullable();
            $table->text('challenges')->nullable();
            $table->string('status')->default('pending_input');
            $table->string('waha_last_message_id')->nullable()->index();
            $table->string('screenshot_path')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('filled_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->index(['report_date', 'sender_phone']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_reports');
    }
};
