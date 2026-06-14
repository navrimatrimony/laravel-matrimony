<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Single geographic hierarchy + pincode SSOT (country → state → district → taluka → village).
 * Legacy separate tables (countries, states, cities, …) are removed; all IDs live here.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('addresses')) {
            return;
        }

        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('addresses')->nullOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('name_mr')->nullable();
            $table->string('name_en')->nullable();
            $table->enum('hierarchy', [
                'country',
                'state',
                'district',
                'taluka',
                'village',
            ]);
            $table->unsignedTinyInteger('level');
            $table->string('pincode', 16)->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->boolean('is_active')->default(true);
            $table->enum('tag', ['city', 'suburban', 'rural'])->nullable()->default(null)->comment('city/suburban/rural classification');
            $table->string('lgd_code', 32)->nullable();
            $table->timestamps();

            $table->index('name');
            $table->index('parent_id');
            $table->index('slug');
            $table->index('hierarchy');
            $table->index('tag');
            $table->unique(['parent_id', 'hierarchy', 'slug'], 'addresses_parent_hierarchy_slug_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }
};
