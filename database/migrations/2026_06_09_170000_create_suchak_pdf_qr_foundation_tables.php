<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suchak_biodata_exports', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('suchak_account_id');
            $table->unsignedBigInteger('matrimony_profile_id');
            $table->unsignedBigInteger('representation_id');
            $table->string('export_type')->default('biodata_pdf');
            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('generated_by_user_id');
            $table->timestamp('downloaded_at')->nullable();
            $table->timestamp('shared_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('suchak_account_id');
            $table->index('matrimony_profile_id');
            $table->index('representation_id');
            $table->index('export_type');
            $table->index('generated_by_user_id');
            $table->index('created_at');
            $table->index(['suchak_account_id', 'matrimony_profile_id', 'created_at'], 'suchak_exports_account_profile_created_idx');

            $table->foreign('suchak_account_id')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('matrimony_profile_id')->references('id')->on('matrimony_profiles')->restrictOnDelete();
            $table->foreign('representation_id')->references('id')->on('suchak_profile_representations')->restrictOnDelete();
            $table->foreign('generated_by_user_id')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('suchak_qr_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('token_hash', 64);
            $table->unsignedBigInteger('suchak_account_id');
            $table->unsignedBigInteger('matrimony_profile_id');
            $table->unsignedBigInteger('representation_id');
            $table->unsignedBigInteger('export_id');
            $table->timestamp('expires_at');
            $table->unsignedInteger('scan_count')->default(0);
            $table->timestamp('last_scanned_at')->nullable();
            $table->timestamps();

            $table->unique('token_hash');
            $table->index('suchak_account_id');
            $table->index('matrimony_profile_id');
            $table->index('representation_id');
            $table->index('export_id');
            $table->index('expires_at');
            $table->index('created_at');
            $table->index(['suchak_account_id', 'matrimony_profile_id', 'expires_at'], 'suchak_qr_account_profile_expiry_idx');

            $table->foreign('suchak_account_id')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('matrimony_profile_id')->references('id')->on('matrimony_profiles')->restrictOnDelete();
            $table->foreign('representation_id')->references('id')->on('suchak_profile_representations')->restrictOnDelete();
            $table->foreign('export_id')->references('id')->on('suchak_biodata_exports')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suchak_qr_tokens');
        Schema::dropIfExists('suchak_biodata_exports');
    }
};
