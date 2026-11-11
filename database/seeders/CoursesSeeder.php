<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CoursesSeeder extends Seeder
{
    private const INSTITUTION_ID = 2;

    public function run(): void
    {
        if (! DB::table('institutions')->where('id', self::INSTITUTION_ID)->exists()) {
            throw new RuntimeException(
                'Institution ID '.self::INSTITUTION_ID.' does not exist. Seed the institutions table first.'
            );
        }

        $key = [
            'institution_id' => self::INSTITUTION_ID,
            'code' => 'OAP-NC-II',
        ];

        $now = now();
        $exists = DB::table('courses')->where($key)->exists();

        $values = [
            'name' => 'Organic Agriculture Production NC I',
            'qualification_level' => 'NC II',
            'training_hours' => 232,
            'is_active' => 1,
            'description' => null,
            'requirements' => null,
            'curriculum_reference' => null,
            'updated_at' => $now,
        ];

        if (! $exists) {
            $values['created_at'] = $now;
        }

        DB::table('courses')->updateOrInsert($key, $values);
    }
}
