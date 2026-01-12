<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Create Matrimony Profiles Table
|--------------------------------------------------------------------------
|
| 👉 ही migration MATRIMONY BIODATA साठी table तयार करते
| 👉 User authentication पासून वेगळी ठेवलेली आहे
|
| लक्षात ठेव:
| - User = login / auth
| - MatrimonyProfile = biodata
|
*/

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('matrimony_profiles', function (Blueprint $table) {

            // Primary key
            $table->id();

            // User relation (एक user → एक matrimony profile)
            $table->foreignId('user_id')
                  ->constrained()
                  ->cascadeOnDelete();

            // Basic biodata fields
            $table->string('full_name');                  // users.gender snapshot
            $table->date('date_of_birth')->nullable();
            $table->string('caste')->nullable();
            $table->string('education')->nullable();
            $table->string('location')->nullable();

            // Laravel timestamps (created_at, updated_at)
            $table->timestamps();

            /*
            |--------------------------------------------------------------------------
            | Future Reminder (DON'T DO NOW)
            |--------------------------------------------------------------------------
            | - Search indexing
            | - Photo columns
            | - Status / approval flags
            |
            | हे Phase-1 नंतर
            |
            */
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('matrimony_profiles');
    }
};
