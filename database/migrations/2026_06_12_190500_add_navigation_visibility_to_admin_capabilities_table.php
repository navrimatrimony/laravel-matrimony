<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<string>
     */
    private array $columns = [
        'can_access_command_center',
        'can_access_members',
        'can_access_intake_ocr',
        'can_access_trust_safety',
        'can_access_matching_discovery',
        'can_access_showcase_engine',
        'can_access_suchak_network',
        'can_access_commerce',
        'can_access_data_governance',
        'can_access_master_data',
        'can_access_site_experience',
        'can_access_system_access',
    ];

    public function up(): void
    {
        Schema::table('admin_capabilities', function (Blueprint $table) {
            foreach ($this->columns as $column) {
                if (! Schema::hasColumn('admin_capabilities', $column)) {
                    $table->boolean($column)->default(false)->after('can_manage_serious_intents');
                }
            }
        });

        $capabilityRows = DB::table('admin_capabilities')->pluck('admin_id');
        if ($capabilityRows->isEmpty()) {
            return;
        }

        $admins = DB::table('users')
            ->whereIn('id', $capabilityRows)
            ->get(['id', 'admin_role', 'is_admin']);

        foreach ($admins as $admin) {
            DB::table('admin_capabilities')
                ->where('admin_id', $admin->id)
                ->update($this->defaultAccessForRole($admin->admin_role, (bool) $admin->is_admin));
        }
    }

    public function down(): void
    {
        Schema::table('admin_capabilities', function (Blueprint $table) {
            foreach (array_reverse($this->columns) as $column) {
                if (Schema::hasColumn('admin_capabilities', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    /**
     * @return array<string, bool>
     */
    private function defaultAccessForRole(?string $adminRole, bool $isLegacyAdmin): array
    {
        $defaults = array_fill_keys($this->columns, false);
        $defaults['can_access_command_center'] = true;

        if ($adminRole === 'super_admin') {
            return array_fill_keys($this->columns, true);
        }

        if ($adminRole === 'data_admin') {
            foreach ([
                'can_access_members',
                'can_access_intake_ocr',
                'can_access_trust_safety',
                'can_access_matching_discovery',
                'can_access_data_governance',
                'can_access_master_data',
            ] as $column) {
                $defaults[$column] = true;
            }
        }

        if ($adminRole === 'auditor') {
            foreach ([
                'can_access_trust_safety',
                'can_access_data_governance',
            ] as $column) {
                $defaults[$column] = true;
            }
        }

        if ($adminRole === null && $isLegacyAdmin) {
            $defaults['can_access_system_access'] = true;
        }

        return $defaults;
    }
};
