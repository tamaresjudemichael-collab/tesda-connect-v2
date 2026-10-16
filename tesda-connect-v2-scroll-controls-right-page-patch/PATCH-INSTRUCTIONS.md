# TESDA Connect V2 — Move Scroll Controls to Right Side of the Page

This patch moves the floating scroll control out of the sidebar and attaches it to the main application layout. It is fixed at the lower-right edge of the page/viewport, appears only when the document can scroll more than 80px, and toggles between down and up arrows based on the scroll position.

## Install
1. Extract the ZIP into `C:\xampp\htdocs\tesda-connect-v2` (the folder containing `artisan`).
2. Open PowerShell in that project folder.
3. Run:
   ```powershell
   .\Apply-ScrollControls-RightPage.ps1
   php artisan view:clear
   ```
4. Refresh the browser with Ctrl+F5.

The installer creates a timestamped backup under `storage\app\patch-backups`. No database migration is required.
