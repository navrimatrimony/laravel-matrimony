<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_centre_tickets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('ticket_code', 24)->nullable()->unique();
            $table->text('query_text');
            $table->text('normalized_query')->nullable();
            $table->string('intent', 64)->index();
            $table->boolean('escalated')->default(false)->index();
            $table->string('status', 24)->default('auto_resolved')->index();
            $table->text('bot_reply');
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_centre_tickets');
    }
};
