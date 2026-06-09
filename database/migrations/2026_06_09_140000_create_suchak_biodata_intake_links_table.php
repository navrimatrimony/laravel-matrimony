<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suchak_biodata_intake_links', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('suchak_account_id');
            $table->unsignedBigInteger('biodata_intake_id');
            $table->unsignedBigInteger('matrimony_profile_id')->nullable();
            $table->string('source_status')->default('intake_uploaded');
            $table->unsignedBigInteger('created_by_user_id');
            $table->timestamps();

            $table->index('suchak_account_id');
            $table->index('biodata_intake_id');
            $table->index('matrimony_profile_id');
            $table->index('source_status');
            $table->index('created_by_user_id');
            $table->index('created_at');
            $table->index(['suchak_account_id', 'source_status', 'created_at'], 'suchak_source_account_status_created_idx');

            $table->foreign('suchak_account_id')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('biodata_intake_id')->references('id')->on('biodata_intakes')->restrictOnDelete();
            $table->foreign('matrimony_profile_id')->references('id')->on('matrimony_profiles')->restrictOnDelete();
            $table->foreign('created_by_user_id')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suchak_biodata_intake_links');
    }
};
