<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Create Interests Table (SSOT v3.1)
|--------------------------------------------------------------------------
|
| 👉 Interest = MatrimonyProfile → MatrimonyProfile
| 👉 User कधीही involved नाही
|
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interests', function (Blueprint $table) {

            $table->id();

            // Sender Matrimony Profile
            $table->foreignId('sender_profile_id')
                  ->constrained('matrimony_profiles')
                  ->cascadeOnDelete();

            // Receiver Matrimony Profile
            $table->foreignId('receiver_profile_id')
                  ->constrained('matrimony_profiles')
                  ->cascadeOnDelete();

            // Interest status (future ready)
            $table->string('status')->default('pending');

            $table->timestamps();

            // One interest per pair
            $table->unique(
                ['sender_profile_id', 'receiver_profile_id'],
                'unique_interest_pair'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interests');
    }
};
