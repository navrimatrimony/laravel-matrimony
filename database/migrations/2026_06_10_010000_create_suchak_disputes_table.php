<?php

use App\Models\SuchakDispute;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suchak_disputes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('suchak_account_id');
            $table->unsignedBigInteger('matrimony_profile_id')->nullable();
            $table->unsignedBigInteger('representation_id')->nullable();
            $table->unsignedBigInteger('opened_by_user_id')->nullable();
            $table->unsignedBigInteger('assigned_admin_user_id')->nullable();
            $table->string('dispute_type')->default(SuchakDispute::TYPE_REPRESENTATION_CLAIM);
            $table->string('status')->default(SuchakDispute::STATUS_OPEN);
            $table->string('priority')->default(SuchakDispute::PRIORITY_NORMAL);
            $table->text('summary');
            $table->text('evidence_summary')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamp('opened_at')->useCurrent();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index('suchak_account_id', 'suchak_disputes_account_idx');
            $table->index('matrimony_profile_id', 'suchak_disputes_profile_idx');
            $table->index('representation_id', 'suchak_disputes_repr_idx');
            $table->index('opened_by_user_id', 'suchak_disputes_opened_by_idx');
            $table->index('assigned_admin_user_id', 'suchak_disputes_admin_idx');
            $table->index('dispute_type', 'suchak_disputes_type_idx');
            $table->index('status', 'suchak_disputes_status_idx');
            $table->index('priority', 'suchak_disputes_priority_idx');
            $table->index('opened_at', 'suchak_disputes_opened_at_idx');
            $table->index('resolved_at', 'suchak_disputes_resolved_at_idx');

            $table->foreign('suchak_account_id', 'suchak_disputes_account_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('matrimony_profile_id', 'suchak_disputes_profile_fk')->references('id')->on('matrimony_profiles')->restrictOnDelete();
            $table->foreign('representation_id', 'suchak_disputes_repr_fk')->references('id')->on('suchak_profile_representations')->restrictOnDelete();
            $table->foreign('opened_by_user_id', 'suchak_disputes_opened_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('assigned_admin_user_id', 'suchak_disputes_admin_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suchak_disputes');
    }
};
