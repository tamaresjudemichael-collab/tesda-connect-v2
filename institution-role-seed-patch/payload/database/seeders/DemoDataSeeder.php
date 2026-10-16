<?php

namespace Database\Seeders;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        // Core institution records only; no courses, batches, applications,
        // announcements, activity logs, or competency demo data are created.
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

        // Four demo accounts for each application role (20 accounts total).
        // Accounts 1-2 for institution-scoped roles belong to BST; 3-4 belong to MIF.
        $roleAccounts = [
            'administrator' => [
                ['name' => 'System Administrator', 'username' => 'admin', 'email' => 'admin@tesdaconnect.test', 'password' => 'admin123'],
                ['name' => 'System Administrator 2', 'username' => 'admin2', 'email' => 'admin2@tesdaconnect.test', 'password' => 'admin123'],
                ['name' => 'System Administrator 3', 'username' => 'admin3', 'email' => 'admin3@tesdaconnect.test', 'password' => 'admin123'],
                ['name' => 'System Administrator 4', 'username' => 'admin4', 'email' => 'admin4@tesdaconnect.test', 'password' => 'admin123'],
            ],
            'registrar' => [
                ['name' => 'Rina Santos', 'username' => 'registrar', 'email' => 'registrar@tesdaconnect.test', 'password' => 'registrar123'],
                ['name' => 'Noel Garcia', 'username' => 'registrar2', 'email' => 'registrar2@tesdaconnect.test', 'password' => 'registrar123'],
                ['name' => 'Liza Mendoza', 'username' => 'registrar3', 'email' => 'registrar3@tesdaconnect.test', 'password' => 'registrar123'],
                ['name' => 'Mark Reyes', 'username' => 'registrar4', 'email' => 'registrar4@tesdaconnect.test', 'password' => 'registrar123'],
            ],
            'focal_person' => [
                ['name' => 'Marvin Cruz', 'username' => 'focal', 'email' => 'focal@tesdaconnect.test', 'password' => 'focal123'],
                ['name' => 'Ana Villanueva', 'username' => 'focal2', 'email' => 'focal2@tesdaconnect.test', 'password' => 'focal123'],
                ['name' => 'Carlo Dizon', 'username' => 'focal3', 'email' => 'focal3@tesdaconnect.test', 'password' => 'focal123'],
                ['name' => 'Fe Meneses', 'username' => 'focal4', 'email' => 'focal4@tesdaconnect.test', 'password' => 'focal123'],
            ],
            'applicant' => [
                ['name' => 'Maria Santos', 'username' => 'applicant', 'email' => 'applicant@tesdaconnect.test', 'password' => 'applicant123'],
                ['name' => 'Eduardo Cruz', 'username' => 'applicant2', 'email' => 'applicant2@tesdaconnect.test', 'password' => 'applicant123'],
                ['name' => 'Josephine Bautista', 'username' => 'applicant3', 'email' => 'applicant3@tesdaconnect.test', 'password' => 'applicant123'],
                ['name' => 'Pedro Ramos', 'username' => 'applicant4', 'email' => 'applicant4@tesdaconnect.test', 'password' => 'applicant123'],
            ],
            'scholar' => [
                ['name' => 'Juan Dela Cruz', 'username' => 'scholar', 'email' => 'scholar@tesdaconnect.test', 'password' => 'scholar123'],
                ['name' => 'Josefa Mercado', 'username' => 'scholar2', 'email' => 'scholar2@tesdaconnect.test', 'password' => 'scholar123'],
                ['name' => 'Paolo Garcia', 'username' => 'scholar3', 'email' => 'scholar3@tesdaconnect.test', 'password' => 'scholar123'],
                ['name' => 'Teresa Reyes', 'username' => 'scholar4', 'email' => 'scholar4@tesdaconnect.test', 'password' => 'scholar123'],
            ],
        ];

        foreach ($roleAccounts as $role => $accounts) {
            foreach ($accounts as $index => $account) {
                $plainPassword = $account['password'];
                unset($account['password']);

                $user = User::updateOrCreate(
                    ['email' => $account['email']],
                    [
                        ...$account,
                        'role' => $role,
                        'password' => Hash::make($plainPassword),
                        'account_status' => 'active',
                    ],
                );
                $user->forceFill(['email_verified_at' => now()])->save();

                if ($role === 'administrator') {
                    // Administrators need visibility across both institutions.
                    $user->institutions()->sync([
                        $bst->id => ['is_primary' => true],
                        $mif->id => ['is_primary' => false],
                    ]);
                    continue;
                }

                $institution = $index < 2 ? $bst : $mif;
                $user->institutions()->sync([
                    $institution->id => ['is_primary' => true],
                ]);
            }
        }
    }
}
