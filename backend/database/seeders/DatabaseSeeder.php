<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([SettingsSeeder::class, BranchSeeder::class, ContentSeeder::class]);

        // البيانات التجريبية في التطوير فقط
        if (app()->environment('local', 'testing')) {
            $this->call(DemoCatalogSeeder::class);
        }
    }
}
