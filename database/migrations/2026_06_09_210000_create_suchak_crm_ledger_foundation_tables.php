<?php

use App\Models\SuchakLedgerEntry;
use App\Models\SuchakProfileNote;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suchak_profile_notes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('suchak_account_id');
            $table->unsignedBigInteger('matrimony_profile_id');
            $table->unsignedBigInteger('collaboration_request_id')->nullable();
            $table->string('note_type')->default(SuchakProfileNote::TYPE_GENERAL);
            $table->text('note_text');
            $table->string('visibility')->default(SuchakProfileNote::VISIBILITY_PRIVATE);
            $table->timestamp('follow_up_at')->nullable();
            $table->timestamps();

            $table->index('suchak_account_id', 'suchak_notes_account_idx');
            $table->index('matrimony_profile_id', 'suchak_notes_profile_idx');
            $table->index('collaboration_request_id', 'suchak_notes_collab_idx');
            $table->index('note_type');
            $table->index('follow_up_at');
            $table->index('created_at');
            $table->index(['suchak_account_id', 'matrimony_profile_id'], 'suchak_notes_account_profile_idx');

            $table->foreign('suchak_account_id', 'suchak_notes_account_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('matrimony_profile_id', 'suchak_notes_profile_fk')->references('id')->on('matrimony_profiles')->restrictOnDelete();
            $table->foreign('collaboration_request_id', 'suchak_notes_collab_fk')->references('id')->on('suchak_collaboration_requests')->restrictOnDelete();
        });

        Schema::create('suchak_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('suchak_account_id');
            $table->unsignedBigInteger('matrimony_profile_id');
            $table->unsignedBigInteger('pipeline_id')->nullable();
            $table->unsignedBigInteger('collaboration_request_id')->nullable();
            $table->string('entry_type');
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('currency', 3)->default('INR');
            $table->string('status')->default(SuchakLedgerEntry::STATUS_EXPECTED);
            $table->date('due_date')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index('suchak_account_id', 'suchak_ledger_account_idx');
            $table->index('matrimony_profile_id', 'suchak_ledger_profile_idx');
            $table->index('pipeline_id', 'suchak_ledger_pipeline_idx');
            $table->index('collaboration_request_id', 'suchak_ledger_collab_idx');
            $table->index('entry_type');
            $table->index('status');
            $table->index('due_date');
            $table->index('created_at');
            $table->index(['suchak_account_id', 'matrimony_profile_id'], 'suchak_ledger_account_profile_idx');

            $table->foreign('suchak_account_id', 'suchak_ledger_account_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('matrimony_profile_id', 'suchak_ledger_profile_fk')->references('id')->on('matrimony_profiles')->restrictOnDelete();
            $table->foreign('pipeline_id', 'suchak_ledger_pipeline_fk')->references('id')->on('suchak_pipelines')->restrictOnDelete();
            $table->foreign('collaboration_request_id', 'suchak_ledger_collab_fk')->references('id')->on('suchak_collaboration_requests')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suchak_ledger_entries');
        Schema::dropIfExists('suchak_profile_notes');
    }
};
