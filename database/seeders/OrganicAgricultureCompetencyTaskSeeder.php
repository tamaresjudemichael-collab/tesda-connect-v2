<?php

namespace Database\Seeders;

use App\Models\CompetencyTask;
use App\Models\Course;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OrganicAgricultureCompetencyTaskSeeder extends Seeder
{
    public function run(): void
    {
        $course = Course::query()
            ->where('code', 'OAP-NC-II')
            ->whereHas('institution', fn ($query) => $query->where('code', 'MIF'))
            ->first();

        if (! $course) {
            throw new RuntimeException(
                'MIF Organic Agriculture course (OAP-NC-II) was not found. Run DemoDataSeeder first.'
            );
        }

        $unitNames = [
            1 => 'Raise Organic Chicken',
            2 => 'Produce Organic Vegetable',
            3 => 'Produce Organic Fertilizer',
            4 => 'Produce Organic Concoctions and Extracts',
            5 => "Raise Organic Hog's",
            6 => 'Raise Organic Small Ruminants',
        ];

        foreach ($unitNames as $unitNumber => $unitName) {
            $course->competencyUnits()->updateOrCreate(
                ['unit_number' => $unitNumber],
                [
                    'name' => $unitName,
                    'sort_order' => $unitNumber,
                ]
            );
        }

        $units = $course->competencyUnits()->get()->keyBy('unit_number');

        $tasksByChart = [
            'achievement' => [
                1 => [
                    'Select Healthy Stocks and Suitable Housing',
                    'Setup Cage Equipment',
                    'Feed Chicken',
                    'Grow and Harvest Chicken',
                ],
                2 => [
                    'Establish Nursery',
                    'Plant Seedlings',
                    'Perform Plant Care and Management',
                    'Perform Harvest and Post Harvest Activities',
                ],
                3 => [
                    'Prepare Composting Area and Raw Materials',
                    'Compost and Harvest Fertilizer',
                ],
                4 => [
                    'Prepare for the Production of Various Concoctions',
                    'Process Concoctions',
                    'Package Concoctions',
                ],
                5 => [
                    'Select Healthy Domestic Hog Breeds and Suitable Housing',
                    "Feed Hog's",
                    "Grow and Finish Hog's",
                ],
                6 => [
                    'Choose/Select Healthy Breeders',
                    'Determine Suitable Small Ruminant Cage (housing) Requirements',
                    'Feed Small Ruminants',
                    'Harvest (Finish) Ruminants',
                ],
            ],
            'progress' => [
                1 => [
                    'Select Healthy Stocks',
                    'Install Cage Equipment',
                    'Bedding Materials',
                    'Set up Brooding Facility',
                    'Feed Making and Materials',
                    'Feed Chicken',
                    'Manage Health and Growth of Chicken',
                    'Produce Fertilizer',
                    'Harvest Chicken',
                    'Make Record',
                ],
                2 => [
                    'Establish Nursery',
                    'Mixing of Potting Media',
                    'Identify Seeds',
                    'Land Preparation',
                    'Introduce Microorganism',
                    'Apply Organic Fertilizer',
                    'Perform Plant Care and Management',
                    'Perform Harvest and Post Harvest Activities',
                ],
                3 => [
                    'Prepare Composting Area',
                    'Identify and Prepare Raw Materials',
                    'Carry Out Composting Process',
                    'Vermi-Composting',
                    'Harvest Compost',
                ],
                4 => [
                    'Prepare Tools and Equipment',
                    'Process Concoctions',
                    'Package Concoctions',
                ],
                5 => [
                    'Identify and Select Healthy Stock',
                    'Identify Materials, Bedding and Mixing',
                    'Install Housing Equipment',
                    'Identify Feed Material and Feed Making',
                    'Perform Health Care and Sanitation',
                    'Feed Hog',
                    'Make Organic From Waste',
                    'Make Record',
                ],
                6 => [
                    'Choose/Select Healthy Breeders',
                    'Determine Suitable Ruminant Cage (Housing) Requirements',
                    'Install Cage (Housing) Requirements',
                    'Feed Small Ruminants',
                    'Manage Health, Growth and Breeding of Small Ruminants',
                    'Harvest (Finish) Ruminants',
                ],
            ],
        ];

        DB::transaction(function () use ($units, $tasksByChart): void {
            foreach ($tasksByChart as $chartType => $unitTasks) {
                foreach ($unitTasks as $unitNumber => $taskNames) {
                    $unit = $units->get($unitNumber);

                    if (! $unit) {
                        throw new RuntimeException(
                            "Competency unit {$unitNumber} was not found."
                        );
                    }

                    foreach ($taskNames as $index => $name) {
                        CompetencyTask::updateOrCreate(
                            [
                                'competency_unit_id' => $unit->id,
                                'chart_type' => $chartType,
                                'task_number' => $index + 1,
                            ],
                            [
                                'name' => $name,
                                'sort_order' => $index + 1,
                            ]
                        );
                    }
                }
            }

            $strayTask = CompetencyTask::query()
                ->where('competency_unit_id', $units->get(2)->id)
                ->where('chart_type', 'progress')
                ->where('task_number', 9)
                ->where('name', 'Prepare Composting Area')
                ->first();

            if ($strayTask) {
                $hasProgress = DB::table('competency_progress')
                    ->where('competency_task_id', $strayTask->id)
                    ->exists();

                if ($hasProgress) {
                    throw new RuntimeException(
                        'The misplaced task has progress records; manual reconciliation is required.'
                    );
                }

                $strayTask->delete();
            }
        });

        $this->command?->info(
            'Organic Agriculture competency tasks synchronized successfully.'
        );
    }
}