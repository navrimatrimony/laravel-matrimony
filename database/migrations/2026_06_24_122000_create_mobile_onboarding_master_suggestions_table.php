<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mobile_onboarding_master_suggestions')) {
            $this->ensureSuggestedByUserForeign();

            return;
        }

        Schema::create('mobile_onboarding_master_suggestions', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 32);
            $table->string('label', 160);
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('working_with_id')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 32)->default('pending');
            $table->foreignId('suggested_by_user_id');
            $table->timestamps();

            $table->index(['type', 'status']);
            $table->index('category_id');
            $table->index('working_with_id');
            $table->foreign('suggested_by_user_id', 'mob_onb_master_sugg_user_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_onboarding_master_suggestions');
    }

    private function ensureSuggestedByUserForeign(): void
    {
        if (! Schema::hasColumn('mobile_onboarding_master_suggestions', 'suggested_by_user_id')) {
            return;
        }

        if ($this->hasForeignConstraint('mob_onb_master_sugg_user_fk')) {
            return;
        }

        Schema::table('mobile_onboarding_master_suggestions', function (Blueprint $table): void {
            $table->foreign('suggested_by_user_id', 'mob_onb_master_sugg_user_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });
    }

    private function hasForeignConstraint(string $name): bool
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() !== 'mysql') {
            return false;
        }

        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', $connection->getDatabaseName())
            ->where('TABLE_NAME', 'mobile_onboarding_master_suggestions')
            ->where('CONSTRAINT_NAME', $name)
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->exists();
    }
};
