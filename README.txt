# TESDA Connect course and competency seeders

This package contains:

- `CoursesSeeder.php` — creates or updates Organic Agriculture Production NC I for institution ID 2.
- `CompetencyUnitsAndTasksSeeder.php` — creates or updates the 6 competency units and 43 tasks from the supplied SQL data. It finds the course using institution ID 2 + course code `OAP-NC-II`, so it does not depend on a hardcoded course primary key.

## Important prerequisite

The `institutions` table must already contain institution ID `2`. This package does not create an institution record.

## Install

Copy both PHP files into your Laravel project's `database/seeders/` directory.

In `database/seeders/DatabaseSeeder.php`, call them in this order:

```php
$this->call([
    CoursesSeeder::class,
    CompetencyUnitsAndTasksSeeder::class,
]);
```

If your institutions are also created by a seeder, call that seeder before `CoursesSeeder`.

Then run:

```bash
php artisan db:seed
```

Or run individually, in order:

```bash
php artisan db:seed --class=CoursesSeeder
php artisan db:seed --class=CompetencyUnitsAndTasksSeeder
```

Both seeders use update-or-insert behavior based on the table's natural unique keys. They do not delete unrelated courses, units, or tasks.

## Course record included

- Institution ID: `2`
- Course code: `OAP-NC-II`
- Course name: `Organic Agriculture Production NC I`
- Qualification level: `NC II`
- Training hours: `232`
- Active: `1`

The course's ID is assigned by the database. The competency seeder looks it up by institution ID and course code.
