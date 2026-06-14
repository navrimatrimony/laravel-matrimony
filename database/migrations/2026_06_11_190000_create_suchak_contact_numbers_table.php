<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        Schema::create('suchak_contact_numbers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('suchak_account_id');
            $table->string('phone_number', 20);
            $table->string('label', 80)->nullable();
            $table->boolean('is_whatsapp')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('suchak_account_id');
            $table->index('phone_number');
            $table->unique(['suchak_account_id', 'phone_number'], 'suchak_contact_numbers_account_phone_unique');
            $table->foreign('suchak_account_id')->references('id')->on('suchak_accounts')->cascadeOnDelete();
        });

        DB::table('suchak_policies')->upsert([
            [
                'policy_key' => 'suchak_auto_publish_on_approval',
                'policy_value' => 'false',
                'value_type' => 'boolean',
                'description' => 'Automatically publish a Suchak account publicly when admin approval succeeds.',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['policy_key'], ['policy_value', 'value_type', 'description', 'is_active', 'updated_at']);
    }

    public function down(): void
    {
        DB::table('suchak_policies')
            ->where('policy_key', 'suchak_auto_publish_on_approval')
            ->delete();

        Schema::dropIfExists('suchak_contact_numbers');
    }
};
