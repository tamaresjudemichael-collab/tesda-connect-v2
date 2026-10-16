$ErrorActionPreference = 'Stop'
$projectRoot = (Get-Location).Path
$relativePath = 'tests\Feature\RegistrarSidebarNavigationTest.php'
$testPath = Join-Path $projectRoot $relativePath

if (-not (Test-Path -LiteralPath $testPath -PathType Leaf)) {
    throw "Cannot find $relativePath. Run this script from the TESDA Connect V2 project root."
}

$content = Get-Content -LiteralPath $testPath -Raw
$old = "->assertSee('MAIN MENU')"
$new = "->assertSee('DASHBOARD')`r`n        ->assertSee('ENROLLMENT SUBMISSION')"

if (-not $content.Contains($old)) {
    if ($content.Contains("->assertSee('ENROLLMENT SUBMISSION')")) {
        Write-Host 'The sidebar test already reflects the Student/Scholar navigation. No changes made.' -ForegroundColor Yellow
        exit 0
    }
    throw "Expected outdated assertion was not found in $relativePath. No changes made. Please share the current test file before applying any manual edit."
}

$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$backupRoot = Join-Path $projectRoot "storage\app\patch-backups\registrar-sidebar-test-$stamp"
$backupPath = Join-Path $backupRoot $relativePath
New-Item -ItemType Directory -Force -Path (Split-Path -Parent $backupPath) | Out-Null
Copy-Item -LiteralPath $testPath -Destination $backupPath

$content = $content.Replace($old, $new)
Set-Content -LiteralPath $testPath -Value $content -Encoding UTF8
Write-Host "Updated stale sidebar test assertion. Backup: $backupPath" -ForegroundColor Green
Write-Host 'The app sidebar was not changed; this aligns the test with the Student/Scholar navigation sections.'
