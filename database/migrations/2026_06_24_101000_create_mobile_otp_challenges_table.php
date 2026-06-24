<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mobile_otp_challenges')) {
            return;
        }

        Schema::create('mobile_otp_challenges', function (Blueprint $table): void {
            $table->id();
            $table->uuid('challenge_id')->unique();
            $table->string('mobile', 20)->index();
            $table->string('channel', 20)->default('sms');
            $table->string('purpose', 40)->default('login_or_register');
            $table->string('otp_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(5);
            $table->timestamp('expires_at')->index();
            $table->timestamp('verified_at')->nullable()->index();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('resend_available_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('locale', 5)->nullable();
            $table->string('terms_version', 64)->nullable();
            $table->string('privacy_version', 64)->nullable();
            $table->boolean('whatsapp_alerts_opt_in')->nullable();
            $table->timestamps();

            $table->index(['mobile', 'created_at']);
            $table->index(['mobile', 'verified_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_otp_challenges');
    }
};
