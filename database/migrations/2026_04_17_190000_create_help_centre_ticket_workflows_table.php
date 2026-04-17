<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_centre_ticket_workflows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('help_centre_ticket_id')->unique()->constrained('help_centre_tickets')->cascadeOnDelete();
            $table->foreignId('assigned_admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('priority', 16)->default('normal');
            $table->timestamp('first_response_due_at')->nullable()->index();
            $table->timestamp('first_response_at')->nullable()->index();
            $table->timestamp('resolved_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_centre_ticket_workflows');
    }
};
