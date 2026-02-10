<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Database\Seeders\TestAdminRolesSeeder;
use Database\Seeders\FieldRegistryCoreSeeder;


class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
{
    

    $this->call([
        FieldRegistryCoreSeeder::class,
        TestAdminRolesSeeder::class,
        MinimalLocationSeeder::class,
        LocationEnrichmentSeeder::class,
    ]);
}

}
