# TESDA Connect V2 — Move User Guide to the Bottom of the Sidebar

This targeted patch moves the existing **User Guide & Role Handbook** link to the lower sidebar position marked in the screenshot: below the flexible spacer and directly above the profile / Sign out section. It does not change the handbook page, routes, scroll controls, database, permissions, or other menu entries.

## Install

1. Extract the ZIP contents into `C:\xampp\htdocs\tesda-connect-v2`, preserving the folders. `Apply-UserGuide-Bottom-Position.ps1` should be in the project root.
2. Open PowerShell in the project root and run:

```powershell
powershell -ExecutionPolicy Bypass -File .\Apply-UserGuide-Bottom-Position.ps1
php artisan view:clear
php artisan test --filter=UserGuideTest
```

The installer makes a timestamped backup under `storage\app\patch-backups\user-guide-sidebar-bottom-<timestamp>`. It is safe to run again.
