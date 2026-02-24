<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-5: Replace matrimony_profiles.contact_visible_to JSON with normalized table.
 * Backfill existing JSON data so drop migration can run safely.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_contact_visibility', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_profile_id')
                ->constrained('matrimony_profiles')
                ->cascadeOnDelete();
            $table->foreignId('viewer_profile_id')
                ->constrained('matrimony_profiles')
                ->cascadeOnDelete();
            $table->string('granted_via', 64)->default('interest_accept'); // interest_accept | admin | manual
            $table->timestamp('granted_at')->useCurrent();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['owner_profile_id', 'viewer_profile_id'], 'profile_contact_visibility_owner_viewer_unique');
            $table->index(['owner_profile_id', 'revoked_at']);
        });

        // Backfill from contact_visible_to JSON so existing grants are preserved before column drop
        if (Schema::hasTable('matrimony_profiles') && Schema::hasColumn('matrimony_profiles', 'contact_visible_to')) {
            $profiles = DB::table('matrimony_profiles')
                ->whereNotNull('contact_visible_to')
                ->select('id', 'contact_visible_to')
                ->get();
            foreach ($profiles as $row) {
                $decoded = is_string($row->contact_visible_to)
                    ? json_decode($row->contact_visible_to, true)
                    : $row->contact_visible_to;
                if (!is_array($decoded)) {
                    continue;
                }
                $ownerId = (int) $row->id;
                $now = now();
                foreach ($decoded as $viewerId) {
                    $viewerId = (int) $viewerId;
                    if ($viewerId <= 0 || $viewerId === $ownerId) {
                        continue;
                    }
                    DB::table('profile_contact_visibility')->insertOrIgnore([
                        'owner_profile_id' => $ownerId,
                        'viewer_profile_id' => $viewerId,
                        'granted_via' => 'interest_accept',
                        'granted_at' => $now,
                        'revoked_at' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_contact_visibility');
    }
};
