# Competency Units and Tasks Seeder

Includes the data from the uploaded SQL dumps:
- 6 competency units
- 39 Progress Chart tasks
- 4 Achievement Chart tasks
- 43 tasks total

## Important
The SQL source assigns every unit to `course_id = 1`. Confirm that ID 1 is
the intended course in the target database. If not, change `COURSE_ID` in
`CompetencyUnitsAndTasksSeeder.php`.

Run after migrations and after the seeder that creates the course.

## Install
Copy `database/seeders/CompetencyUnitsAndTasksSeeder.php` into the same
folder in your Laravel project.

Add to `DatabaseSeeder.php`:

```php
$this->call([
    CompetencyUnitsAndTasksSeeder::class,
]);
```

Or run directly:

```bash
php artisan db:seed --class=CompetencyUnitsAndTasksSeeder
```

The seeder is rerunnable: it updates matching units by `(course_id, unit_number)`
and tasks by `(competency_unit_id, chart_type, task_number)`, without making
duplicates or deleting unrelated records. Back up production data first.
