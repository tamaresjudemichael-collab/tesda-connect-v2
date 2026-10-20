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

         
        $roleAccounts = [
            'administrator' => [
                ['name' => 'System Administrator', 'username' => 'admin', 'email' => 'admin@tesdaconnect.test', 'password' => 'admin123'],
                     ],
            'registrar' => [
                ['name' => 'Rina Santos', 'username' => 'registrar', 'email' => 'registrar@tesdaconnect.test', 'password' => 'registrar123'],
                      ],
            'focal_person' => [
                ['name' => 'Marvin Cruz', 'username' => 'focal', 'email' => 'focal@tesdaconnect.test', 'password' => 'focal123'],
                          ],
            'applicant' => [
                ['name' => 'Maria Santos', 'username' => 'applicant', 'email' => 'applicant@tesdaconnect.test', 'password' => 'applicant123'],
                            ],
            'scholar' => [
                ['name' => 'Juan Dela Cruz', 'username' => 'scholar', 'email' => 'scholar@tesdaconnect.test', 'password' => 'scholar123'],
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
