<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive: YY/MM/batch/profile_id photo layout — batch slots (max 30 profiles per batch).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('matrimony_photo_batch_allocations')) {
            Schema::create('matrimony_photo_batch_allocations', function (Blueprint $table) {
                $table->id();
                $table->unsignedTinyInteger('yy');
                $table->unsignedTinyInteger('mm');
                $table->unsignedInteger('batch_index');
                $table->unsignedInteger('profiles_count')->default(0);
                $table->timestamps();
                $table->unique(['yy', 'mm', 'batch_index']);
            });
        }

        if (Schema::hasTable('matrimony_profiles')) {
            Schema::table('matrimony_profiles', function (Blueprint $table) {
                if (! Schema::hasColumn('matrimony_profiles', 'photo_storage_rel')) {
                    $table->string('photo_storage_rel', 160)->nullable()->after('profile_photo');
                }
                if (! Schema::hasColumn('matrimony_profiles', 'photo_batch_allocation_id')) {
                    $table->unsignedBigInteger('photo_batch_allocation_id')->nullable()->after('photo_storage_rel');
                }
            });

            if (Schema::hasTable('matrimony_photo_batch_allocations')
                && Schema::hasColumn('matrimony_profiles', 'photo_batch_allocation_id')) {
                Schema::table('matrimony_profiles', function (Blueprint $table) {
                    $table->foreign('photo_batch_allocation_id')
                        ->references('id')
                        ->on('matrimony_photo_batch_allocations')
                        ->nullOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('matrimony_profiles')) {
            Schema::table('matrimony_profiles', function (Blueprint $table) {
                if (Schema::hasColumn('matrimony_profiles', 'photo_batch_allocation_id')) {
                    $table->dropForeign(['photo_batch_allocation_id']);
                    $table->dropColumn('photo_batch_allocation_id');
                }
                if (Schema::hasColumn('matrimony_profiles', 'photo_storage_rel')) {
                    $table->dropColumn('photo_storage_rel');
                }
            });
        }

        Schema::dropIfExists('matrimony_photo_batch_allocations');
    }
};
