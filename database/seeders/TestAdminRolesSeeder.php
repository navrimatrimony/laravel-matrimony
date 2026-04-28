<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class TestAdminRolesSeeder extends Seeder
{
    /** Shared login for all seeded admin test accounts (local / staging only). */
    private const ADMIN_SEED_PASSWORD = 'Password@123';

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Model::unguard();

        User::updateOrCreate(
            ['email' => 'super_admin_test@example.com'],
            [
                'name' => 'Super Admin Test',
                'password' => Hash::make(self::ADMIN_SEED_PASSWORD),
                'gender' => 'Male',
                'is_admin' => true,
                'admin_role' => 'super_admin',
            ]
        );

        User::updateOrCreate(
            ['email' => 'data_admin_test@example.com'],
            [
                'name' => 'Data Admin Test',
                'password' => Hash::make(self::ADMIN_SEED_PASSWORD),
                'gender' => 'Male',
                'is_admin' => true,
                'admin_role' => 'data_admin',
            ]
        );

        User::updateOrCreate(
            ['email' => 'auditor_test@example.com'],
            [
                'name' => 'Auditor Test',
                'password' => Hash::make(self::ADMIN_SEED_PASSWORD),
                'gender' => 'Male',
                'is_admin' => true,
                'admin_role' => 'auditor',
            ]
        );

        Model::reguard();
    }
}
