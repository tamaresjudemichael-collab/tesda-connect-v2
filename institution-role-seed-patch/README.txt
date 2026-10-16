TESDA Connect V2 — Minimal institution + role accounts seed

WHAT CHANGES
- Seeds two institutions: BST and MIF.
- Seeds exactly four intended demo accounts in each role: administrator, registrar,
  focal_person, applicant, and scholar (20 accounts defined in total).
- Keeps the familiar first account for each role and adds accounts 2-4.
- Administrator demo accounts are linked to both institutions. Other roles get one
  institution each: accounts 1-2 to BST and 3-4 to MIF.
- DatabaseSeeder no longer calls OrganicAgricultureCompetencyTaskSeeder.
- DemoDataSeeder no longer creates demo courses, training batches, enrollment
  applications, announcements, or activity logs.

DEMO LOGIN CREDENTIALS
Role: administrator | Password for all four: admin123
  admin@tesdaconnect.test, admin2@tesdaconnect.test,
  admin3@tesdaconnect.test, admin4@tesdaconnect.test
Role: registrar | Password for all four: registrar123
  registrar@tesdaconnect.test, registrar2@tesdaconnect.test,
  registrar3@tesdaconnect.test, registrar4@tesdaconnect.test
Role: focal_person | Password for all four: focal123
  focal@tesdaconnect.test, focal2@tesdaconnect.test,
  focal3@tesdaconnect.test, focal4@tesdaconnect.test
Role: applicant | Password for all four: applicant123
  applicant@tesdaconnect.test, applicant2@tesdaconnect.test,
  applicant3@tesdaconnect.test, applicant4@tesdaconnect.test
Role: scholar | Password for all four: scholar123
  scholar@tesdaconnect.test, scholar2@tesdaconnect.test,
  scholar3@tesdaconnect.test, scholar4@tesdaconnect.test

INSTALL
1. Extract the ZIP into the Laravel project root, so the folder
   institution-role-seed-patch sits beside app, database, and artisan.
2. From the project root, run:
   .\institution-role-seed-patch\Apply-Institution-Role-Seed.ps1
3. If PowerShell blocks scripts for this one process, run:
   powershell -ExecutionPolicy Bypass -File .\institution-role-seed-patch\Apply-Institution-Role-Seed.ps1
4. Run:
   php artisan db:seed
5. Verify counts/roles in the database or with Tinker.

IMPORTANT
- No migration is included or required by this patch.
- The seeder is repeatable and uses updateOrCreate; it does not delete other existing
  users or pre-existing demo data from your database. Therefore this changes what
  future seeding creates but does not guarantee an already-populated database will
  contain only 20 users. Back up your database before manually cleaning records.
- As requested, new database seeding will not populate courses/batches/applications;
  screens that require those records will be empty until you add operational data.
