<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('plan_prices')) {
            $this->backfillFromPlanTerms();

            return;
        }

        Schema::create('plan_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->string('duration_type', 32);
            $table->unsignedInteger('duration_days');
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('original_price', 12, 2)->nullable();
            $table->unsignedTinyInteger('discount_percent')->nullable();
            $table->boolean('is_popular')->default(false);
            $table->boolean('is_best_seller')->default(false);
            $table->string('tag', 120)->nullable();
            $table->boolean('is_visible')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['plan_id', 'duration_type']);
            $table->index(['plan_id', 'is_visible', 'sort_order']);
        });

        $this->backfillFromPlanTerms();
    }

    private function backfillFromPlanTerms(): void
    {
        if (! Schema::hasTable('plan_prices') || ! Schema::hasTable('plan_terms')) {
            return;
        }

        $now = now();
        foreach (DB::table('plan_terms')->orderBy('plan_id')->orderBy('sort_order')->get() as $row) {
            DB::table('plan_prices')->updateOrInsert(
                ['plan_id' => $row->plan_id, 'duration_type' => $row->billing_key],
                [
                    'duration_days' => $row->duration_days,
                    'price' => $row->price,
                    'original_price' => null,
                    'discount_percent' => $row->discount_percent,
                    'is_popular' => false,
                    'is_best_seller' => false,
                    'tag' => null,
                    'is_visible' => (bool) $row->is_visible,
                    'sort_order' => $row->sort_order,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_prices');
    }
};
