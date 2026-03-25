<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_kyc_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matrimony_profile_id')
                ->constrained('matrimony_profiles')
                ->restrictOnDelete();
            $table->string('id_document_path', 512);
            $table->string('status', 32)->default('pending'); // pending | approved | rejected
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('admin_note')->nullable();
            $table->timestamps();

            $table->index(['matrimony_profile_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_kyc_submissions');
    }
};
