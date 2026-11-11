<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Database\Seeders\CoursesSeeder;
use Database\Seeders\CompetencyUnitsAndTasksSeeder;
use Database\Seeders\DemoDataSeeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed only institutions and role-based demo accounts in local/testing.
     */
    public function run(): void
    {
        if (app()->environment(['local', 'testing'])) {
            $this->call([
                DemoDataSeeder::class,
                CoursesSeeder::class,
                CompetencyUnitsAndTasksSeeder::class,
            ]);
        }
    }
}
