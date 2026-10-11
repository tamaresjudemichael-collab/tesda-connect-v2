<?php

namespace Database\Seeders;

use App\Models\ActivityLog;
use App\Models\Announcement;
use App\Models\Course;
use App\Models\EnrollmentApplication;
use App\Models\Institution;
use App\Models\TrainingBatch;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $bst = Institution::updateOrCreate(
            ['code' => 'BST'],
            [
                'name' => 'Balli School of Technology',
                'address' => 'Brgy. Tangalan, Mamburao, Occidental Mindoro',
                'contact_email' => 'bst@tesdaconnect.test',
                'contact_number' => '0917 000 1001',
                'is_active' => true,
            ],
        );

        $mif = Institution::updateOrCreate(
            ['code' => 'MIF'],
            [
                'name' => 'Mamburao Integrated Farm',
                'address' => 'Brgy. Tangalan, Mamburao, Occidental Mindoro',
                'contact_email' => 'mif@tesdaconnect.test',
                'contact_number' => '0917 000 1002',
                'is_active' => true,
            ],
        );

        $users = collect([
            ['name' => 'System Administrator', 'username' => 'admin', 'email' => 'admin@tesdaconnect.test', 'role' => 'administrator', 'password' => 'admin123'],
            ['name' => 'Rina Santos', 'username' => 'registrar', 'email' => 'registrar@tesdaconnect.test', 'role' => 'registrar', 'password' => 'registrar123'],
            ['name' => 'Marvin Cruz', 'username' => 'focal', 'email' => 'focal@tesdaconnect.test', 'role' => 'focal_person', 'password' => 'focal123'],
            ['name' => 'Juan Dela Cruz', 'username' => 'scholar', 'email' => 'scholar@tesdaconnect.test', 'role' => 'scholar', 'password' => 'scholar123'],
        ])->mapWithKeys(function (array $account) use ($bst, $mif) {
            $plainPassword = $account['password'];
            unset($account['password']);

            $user = User::updateOrCreate(
                ['email' => $account['email']],
                [
                    ...$account,
                    'password' => Hash::make($plainPassword),
                    'email_verified_at' => now(),
                    'account_status' => 'active',
                ],
            );

            $user->institutions()->syncWithoutDetaching([
                $bst->id => ['is_primary' => true],
                $mif->id => ['is_primary' => false],
            ]);

            return [$account['role'] => $user];
        });

        $oap = Course::updateOrCreate(
            ['institution_id' => $mif->id, 'code' => 'OAP-NC2'],
            ['name' => 'Organic Agriculture Production NC II', 'qualification_level' => 'NC II', 'training_hours' => 232, 'is_active' => true],
        );
        $bpp = Course::updateOrCreate(
            ['institution_id' => $bst->id, 'code' => 'BPP-NC2'],
            ['name' => 'Bread and Pastry Production NC II', 'qualification_level' => 'NC II', 'training_hours' => 141, 'is_active' => true],
        );
        $css = Course::updateOrCreate(
            ['institution_id' => $bst->id, 'code' => 'CSS-NC2'],
            ['name' => 'Computer Systems Servicing NC II', 'qualification_level' => 'NC II', 'training_hours' => 280, 'is_active' => true],
        );

        $batches = collect([
            ['institution_id' => $mif->id, 'course_id' => $oap->id, 'code' => 'MIF-OAP-2026-01', 'name' => 'OAP Batch 1', 'capacity' => 30, 'starts_at' => now()->subWeeks(5)->toDateString(), 'ends_at' => now()->addMonths(2)->toDateString(), 'status' => 'in_training'],
            ['institution_id' => $mif->id, 'course_id' => $oap->id, 'code' => 'MIF-OAP-2026-02', 'name' => 'OAP Batch 2', 'capacity' => 30, 'starts_at' => now()->addWeek()->toDateString(), 'ends_at' => now()->addMonths(3)->toDateString(), 'status' => 'upcoming'],
            ['institution_id' => $bst->id, 'course_id' => $bpp->id, 'code' => 'BST-BPP-2026-01', 'name' => 'BPP Batch 1', 'capacity' => 25, 'starts_at' => now()->subWeeks(2)->toDateString(), 'ends_at' => now()->addMonths(2)->toDateString(), 'status' => 'in_training'],
            ['institution_id' => $bst->id, 'course_id' => $css->id, 'code' => 'BST-CSS-2026-01', 'name' => 'CSS Batch 1', 'capacity' => 25, 'starts_at' => now()->addWeeks(2)->toDateString(), 'ends_at' => now()->addMonths(4)->toDateString(), 'status' => 'upcoming'],
        ])->mapWithKeys(function (array $batch) {
            $record = TrainingBatch::updateOrCreate(['code' => $batch['code']], $batch);

            return [$batch['code'] => $record];
        });

        $applications = [
            ['reference_no' => 'TC-2026-0001', 'learner_user_id' => $users['scholar']->id, 'institution_id' => $mif->id, 'course_id' => $oap->id, 'training_batch_id' => $batches['MIF-OAP-2026-01']->id, 'learner_name' => 'Juan Dela Cruz', 'email' => 'scholar@tesdaconnect.test', 'contact_number' => '0917 555 0101', 'application_status' => 'verified', 'record_completeness' => 100, 'submitted_at' => now()->subDays(8), 'verified_at' => now()->subDays(3), 'scholar_id' => 'SCH-2026-001'],
            ['reference_no' => 'TC-2026-0002', 'institution_id' => $mif->id, 'course_id' => $oap->id, 'training_batch_id' => $batches['MIF-OAP-2026-02']->id, 'learner_name' => 'Maria Santos', 'email' => 'maria.santos@example.test', 'contact_number' => '0917 555 0102', 'application_status' => 'under_review', 'record_completeness' => 75, 'submitted_at' => now()->subDays(2)],
            ['reference_no' => 'TC-2026-0003', 'institution_id' => $bst->id, 'course_id' => $bpp->id, 'training_batch_id' => $batches['BST-BPP-2026-01']->id, 'learner_name' => 'Eduardo Cruz', 'email' => 'eduardo.cruz@example.test', 'contact_number' => '0917 555 0103', 'application_status' => 'confirmed', 'record_completeness' => 100, 'submitted_at' => now()->subDays(12), 'verified_at' => now()->subDays(7), 'confirmed_at' => now()->subDays(2), 'scholar_id' => 'SCH-2026-002'],
            ['reference_no' => 'TC-2026-0004', 'institution_id' => $bst->id, 'course_id' => $css->id, 'training_batch_id' => $batches['BST-CSS-2026-01']->id, 'learner_name' => 'Josephine Bautista', 'email' => 'josephine.bautista@example.test', 'contact_number' => '0917 555 0104', 'application_status' => 'returned_for_revision', 'record_completeness' => 50, 'submitted_at' => now()->subDay(), 'remarks' => 'Please upload a clearer valid ID.'],
        ];

        foreach ($applications as $application) {
            EnrollmentApplication::updateOrCreate(['reference_no' => $application['reference_no']], $application);
        }

        Announcement::updateOrCreate(
            ['title' => 'Schedule for OAP Batch 2 orientation'],
            ['institution_id' => $mif->id, 'author_user_id' => $users['administrator']->id, 'body' => 'Orientation starts next week. Please bring your valid identification document.', 'audience' => 'scholar', 'published_at' => now()->subHour()],
        );

        foreach ([
            ['event' => 'enrollment.created', 'description' => 'New trainee record added — Juan Dela Cruz', 'created_at' => now()->subMinutes(2)],
            ['event' => 'announcement.published', 'description' => 'Announcement posted — Schedule for OAP Batch 2 orientation', 'created_at' => now()->subHour()],
            ['event' => 'record.flagged', 'description' => 'Document incomplete flagged — Maria Santos', 'created_at' => now()->subHours(3)],
            ['event' => 'schedule.updated', 'description' => 'Schedule updated — CSS Batch 1 orientation', 'created_at' => now()->subDay()],
        ] as $event) {
            ActivityLog::updateOrCreate(
                ['event' => $event['event'], 'description' => $event['description']],
                ['user_id' => $users['administrator']->id, 'institution_id' => $mif->id, 'metadata' => [], 'created_at' => $event['created_at'], 'updated_at' => $event['created_at']],
            );
        }
    }
}
