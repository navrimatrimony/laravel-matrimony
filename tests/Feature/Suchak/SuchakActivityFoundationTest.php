<?php

namespace Tests\Feature\Suchak;

use App\Models\AdminAuditLog;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakActivityLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class SuchakActivityFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_suchak_activity_logs_table_exists_with_day_3_columns(): void
    {
        $this->assertTrue(Schema::hasTable('suchak_activity_logs'));

        foreach ([
            'suchak_account_id',
            'actor_user_id',
            'actor_type',
            'action_type',
            'target_type',
            'target_id',
            'matrimony_profile_id',
            'admin_audit_log_id',
            'ip_address',
            'user_agent',
            'metadata_json',
            'occurred_at',
            'created_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_activity_logs', $column), $column);
        }

        $this->assertFalse(Schema::hasColumn('suchak_activity_logs', 'updated_at'));
        $this->assertFalse(Schema::hasColumn('suchak_activity_logs', 'deleted_at'));
    }

    public function test_logger_records_suchak_business_activity(): void
    {
        $account = SuchakAccount::factory()->create();

        $log = app(SuchakActivityLogger::class)->record([
            'suchak_account_id' => $account->id,
            'actor_user_id' => $account->user_id,
            'actor_type' => SuchakActivityLog::ACTOR_SUCHAK,
            'action_type' => SuchakActivityLog::ACTION_SOURCE_LINK_CREATED,
            'target_type' => 'suchak_biodata_intake_link',
            'target_id' => 123,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Feature test',
            'metadata_json' => ['context' => 'fixture'],
        ]);

        $this->assertDatabaseHas('suchak_activity_logs', [
            'id' => $log->id,
            'suchak_account_id' => $account->id,
            'actor_user_id' => $account->user_id,
            'actor_type' => SuchakActivityLog::ACTOR_SUCHAK,
            'action_type' => SuchakActivityLog::ACTION_SOURCE_LINK_CREATED,
            'target_type' => 'suchak_biodata_intake_link',
            'target_id' => 123,
        ]);

        $this->assertSame(['context' => 'fixture'], $log->fresh()->metadata_json);
    }

    public function test_admin_activity_requires_admin_audit_log_id(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(SuchakActivityLogger::class)->record([
            'actor_type' => SuchakActivityLog::ACTOR_ADMIN,
            'action_type' => SuchakActivityLog::ACTION_ADMIN_AUDIT_LINKED,
        ]);
    }

    public function test_admin_activity_can_link_to_admin_audit_log(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => 'super_admin']);
        $account = SuchakAccount::factory()->create();
        $adminAuditLog = AdminAuditLog::query()->create([
            'admin_id' => $admin->id,
            'action_type' => 'suchak_test_admin_action',
            'entity_type' => 'SuchakAccount',
            'entity_id' => $account->id,
            'reason' => 'Testing Suchak admin audit link.',
        ]);

        $activity = app(SuchakActivityLogger::class)->record([
            'suchak_account_id' => $account->id,
            'actor_user_id' => $admin->id,
            'actor_type' => SuchakActivityLog::ACTOR_ADMIN,
            'action_type' => SuchakActivityLog::ACTION_ADMIN_AUDIT_LINKED,
            'target_type' => 'suchak_account',
            'target_id' => $account->id,
            'admin_audit_log_id' => $adminAuditLog->id,
        ]);

        $this->assertTrue($activity->adminAuditLog->is($adminAuditLog));
    }

    public function test_suchak_activity_logs_are_immutable(): void
    {
        $activity = SuchakActivityLog::factory()->create();

        $this->expectException(RuntimeException::class);

        $activity->update([
            'action_type' => SuchakActivityLog::ACTION_CONSENT_REQUESTED,
        ]);
    }
}
