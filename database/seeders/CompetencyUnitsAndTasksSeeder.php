<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CompetencyUnitsAndTasksSeeder extends Seeder
{
    private const INSTITUTION_ID = 2;
    private const COURSE_CODE = 'OAP-NC-II';

    public function run(): void
    {
        DB::transaction(function (): void {
            $courseId = DB::table('courses')
                ->where('institution_id', self::INSTITUTION_ID)
                ->where('code', self::COURSE_CODE)
                ->value('id');

            if (! $courseId) {
                throw new \RuntimeException(
                    'Course OAP-NC-II for institution ID '.self::INSTITUTION_ID.
                    ' was not found. Run CoursesSeeder first.'
                );
            }

            $now = now();

            $units = [
                [1, 'RAISE ORGANIC CHICKEN', 1],
                [2, 'PRODUCE ORGANIC VEGETABLE', 2],
                [3, 'PRODUCE ORGANIC FERTILIZER', 3],
                [4, 'PRODUCE ORGANIC CONCOCTIONS AND EXTRACTS', 4],
                [5, "RAISE ORGANIC HOG'S", 5],
                [6, 'RAISE ORGANIC SMALL RUMINANTS', 6],
            ];

            foreach ($units as [$number, $name, $order]) {
                $key = ['course_id' => $courseId, 'unit_number' => $number];
                $exists = DB::table('competency_units')->where($key)->exists();
                $values = ['name' => $name, 'sort_order' => $order, 'updated_at' => $now];
                if (! $exists) {
                    $values['created_at'] = $now;
                }
                DB::table('competency_units')->updateOrInsert($key, $values);
            }

            $unitIds = DB::table('competency_units')
                ->where('course_id', $courseId)
                ->whereIn('unit_number', range(1, 6))
                ->pluck('id', 'unit_number');

            $tasks = [
                [1,'progress',1,'Select Healthy Stocks',1],
                [1,'progress',2,'Install Cage Equipment',2],
                [1,'progress',3,'Bedding Materials',3],
                [1,'progress',4,'Setup Brooding Facility',4],
                [1,'progress',5,'Food Making and Materials',5],
                [1,'progress',6,'Feed Chicken',6],
                [1,'progress',7,'Manage Health and Growth of Chicken',7],
                [1,'progress',8,'Produce Fertilizer',8],
                [1,'progress',9,'Harvest Chicken',9],
                [1,'progress',10,'Make Record',10],

                [2,'progress',1,'Establish Nursery',1],
                [2,'progress',2,'Mixing of Potting Media',2],
                [2,'progress',3,'Identify Seeds',3],
                [2,'progress',4,'Land Preparation',4],
                [2,'progress',5,'Introduce Microorganism',5],
                [2,'progress',6,'Introduce Microorganism',6],
                [2,'progress',7,'Perform Plant Care and Management',7],
                [2,'progress',8,'Perform Harvest and Post Harvest Activities',8],

                [3,'progress',1,'Prepare Composting Area',1],
                [3,'progress',2,'Identify and Prepare Raw Materials',2],
                [3,'progress',3,'Carry Cut Composting Process',3],
                [3,'progress',4,'Vermi-Composting',4],
                [3,'progress',5,'Harvest Compost',5],

                [4,'progress',1,'Prepare Tools and Equipment',1],
                [4,'progress',2,'Process Concoctions',2],
                [4,'progress',3,'Package Concoctions',3],

                [5,'progress',1,'Identify and Select Healthy Stock',1],
                [5,'progress',2,'Identify Materials, Bedding, and Mixing',2],
                [5,'progress',3,'Perform Health Care and Sanitation',3],
                [5,'progress',4,'Feed Hog',4],
                [5,'progress',5,'Make Organic from Waste',5],
                [5,'progress',6,'Make Record',6],

                [6,'progress',1,'Choose/Select Healthy Breeders',1],
                [6,'progress',2,'Determine Suitable Small Ruminant',2],
                [6,'progress',3,'Cage (Housing) Requirements',3],
                [6,'progress',4,'Install Cage (Housing) Requirements',4],
                [6,'progress',5,'Feed Small Ruminants',5],
                [6,'progress',6,'Manage Health, Growth and Breeding of Small Ruminants',6],
                [6,'progress',7,'Harvest (Finish) Ruminants',7],

                [1,'achievement',11,'Select Healthy Stock and Suitable Housing',11],
                [1,'achievement',12,'Set-up Cage Equipment',2],
                [1,'achievement',13,'Feed Chicken',3],
                [1,'achievement',14,'Grow and Harvest Chicken',4],
            ];

            foreach ($tasks as [$unitNumber, $chartType, $taskNumber, $name, $order]) {
                if (! isset($unitIds[$unitNumber])) {
                    throw new \RuntimeException("Competency Unit {$unitNumber} not found for course ID ".self::COURSE_ID);
                }

                $key = [
                    'competency_unit_id' => $unitIds[$unitNumber],
                    'chart_type' => $chartType,
                    'task_number' => $taskNumber,
                ];
                $exists = DB::table('competency_tasks')->where($key)->exists();
                $values = ['name' => $name, 'sort_order' => $order, 'updated_at' => $now];
                if (! $exists) {
                    $values['created_at'] = $now;
                }

                DB::table('competency_tasks')->updateOrInsert($key, $values);
            }
        });
    }
}
