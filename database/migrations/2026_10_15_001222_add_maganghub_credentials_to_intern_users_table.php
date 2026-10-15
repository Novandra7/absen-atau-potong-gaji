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
        Schema::table('intern_users', function (Blueprint $table) {
            $table->string('maganghub_email')->nullable()->after('whatsapp_lid');
            $table->text('maganghub_password')->nullable()->after('maganghub_email');
            $table->boolean('maganghub_auto_submit')->default(true)->after('maganghub_password');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('intern_users', function (Blueprint $table) {
            $table->dropColumn(['maganghub_email', 'maganghub_password', 'maganghub_auto_submit']);
        });
    }
};
