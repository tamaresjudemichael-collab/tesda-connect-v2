# TESDA Connect V2 — Training Calendar CarbonPeriod fix

Fixes the current `system.calendar` 500 error:

`Class "Illuminate\\Support\\CarbonPeriod" not found`

`CarbonPeriod` belongs to the `Carbon` namespace, so the calendar grid now uses `\\Carbon\\CarbonPeriod::create(...)`. The unsupported `calendar-plus` icon was also already replaced with `calendar-days` in this included Blade file.

## Apply

1. Extract this ZIP into `C:\\xampp\\htdocs\\tesda-connect-v2`, allowing overwrite of the included file.
2. Run:

```powershell
php artisan view:clear
php artisan test --filter=AdministratorTrainingCalendarTest
php artisan test
```

No migration or seeding is required.
