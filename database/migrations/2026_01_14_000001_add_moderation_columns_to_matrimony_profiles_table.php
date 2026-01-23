<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Add Moderation Columns to Matrimony Profiles Table
|--------------------------------------------------------------------------
|
| 👉 Adds suspend status, soft delete, and image moderation columns
| 👉 Required for Day 4 admin moderation features
|
*/
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('matrimony_profiles', function (Blueprint $table) {
            // Soft delete support
            $table->softDeletes();
            
            // Suspend status
            $table->boolean('is_suspended')->default(false)->after('profile_photo');
            
            // Image moderation
            $table->boolean('photo_approved')->default(false)->after('is_suspended');
            $table->timestamp('photo_rejected_at')->nullable()->after('photo_approved');
            $table->text('photo_rejection_reason')->nullable()->after('photo_rejected_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('matrimony_profiles', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn([
                'is_suspended',
                'photo_approved',
                'photo_rejected_at',
                'photo_rejection_reason',
            ]);
        });
    }
};