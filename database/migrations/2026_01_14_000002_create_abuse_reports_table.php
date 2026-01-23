<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Create Abuse Reports Table
|--------------------------------------------------------------------------
|
| 👉 Users can report profiles for abuse
| 👉 Admin can mark reports as open or resolved
|
*/
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('abuse_reports', function (Blueprint $table) {
            $table->id();
            
            // Reporter (user who submitted the report)
            $table->foreignId('reporter_user_id')
                  ->constrained('users')
                  ->cascadeOnDelete();
            
            // Reported profile
            $table->foreignId('reported_profile_id')
                  ->constrained('matrimony_profiles')
                  ->cascadeOnDelete();
            
            // Report details
            $table->text('reason');
            $table->string('status')->default('open'); // open, resolved
            
            // Resolution details (filled by admin)
            $table->text('resolution_reason')->nullable();
            $table->foreignId('resolved_by_admin_id')
                  ->nullable()
                  ->constrained('users');
            $table->timestamp('resolved_at')->nullable();
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('abuse_reports');
    }
};