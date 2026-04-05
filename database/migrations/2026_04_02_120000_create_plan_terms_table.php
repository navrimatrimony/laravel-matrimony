<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('plan_terms')) {
            $this->backfillPlanTerms();

            return;
        }

        Schema::create('plan_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->string('billing_key', 32);
            $table->unsignedInteger('duration_days');
            $table->decimal('price', 12, 2)->default(0);
            $table->unsignedTinyInteger('discount_percent')->nullable();
            $table->boolean('is_visible')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['plan_id', 'billing_key']);
            $table->index(['plan_id', 'is_visible', 'sort_order']);
        });

        $this->backfillPlanTerms();
    }

    private function backfillPlanTerms(): void
    {
        if (! Schema::hasTable('plans') || ! Schema::hasTable('plan_terms')) {
            return;
        }

        $now = now();
        foreach (DB::table('plans')->orderBy('id')->get() as $p) {
            if (strtolower((string) $p->slug) === 'free') {
                continue;
            }
            $monthly = (float) $p->price;
            $disc = $p->discount_percent;
            $defs = [
                ['monthly', 30, $monthly, $disc, true, 10],
                ['quarterly', 90, round($monthly * 3 * 0.95), null, false, 20],
                ['half_yearly', 180, round($monthly * 6 * 0.90), null, false, 30],
                ['yearly', 365, round($monthly * 12 * 0.85), null, false, 40],
            ];
            foreach ($defs as $d) {
                DB::table('plan_terms')->updateOrInsert(
                    ['plan_id' => $p->id, 'billing_key' => $d[0]],
                    [
                        'duration_days' => $d[1],
                        'price' => $d[2],
                        'discount_percent' => $d[3],
                        'is_visible' => $d[4],
                        'sort_order' => $d[5],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_terms');
    }
};
