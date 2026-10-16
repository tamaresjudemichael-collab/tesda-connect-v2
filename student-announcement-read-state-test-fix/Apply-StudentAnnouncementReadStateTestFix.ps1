$ErrorActionPreference = 'Stop'
$projectRoot = (Get-Location).Path
$artisanPath = Join-Path $projectRoot 'artisan'
$testPath = Join-Path $projectRoot 'tests\Feature\StudentScholarWorkspaceTest.php'

if (-not (Test-Path -LiteralPath $artisanPath -PathType Leaf)) {
    throw 'Run this script from the TESDA Connect V2 project root (the folder containing artisan).'
}
if (-not (Test-Path -LiteralPath $testPath -PathType Leaf)) {
    throw "StudentScholarWorkspaceTest.php was not found at: $testPath"
}

$content = [System.IO.File]::ReadAllText($testPath)
$old = 'expect($deadline->views()->count())->toBe(0);'
$new = '$view = $deadline->views()->first();' + [Environment]::NewLine + [Environment]::NewLine + '    expect($deadline->views()->count())->toBe(1)' + [Environment]::NewLine + '        ->and($view?->read_at)->toBeNull();'

$count = [regex]::Matches($content, [regex]::Escape($old)).Count
if ($count -eq 0) {
    if ($content.Contains('expect($deadline->views()->count())->toBe(1)') -and $content.Contains('$view?->read_at') -and $content.Contains('->toBeNull()')) {
        Write-Host 'Student announcement impression/read-state test is already updated.' -ForegroundColor Yellow
        exit 0
    }
    throw 'The expected old assertion was not found. No files were changed; inspect StudentScholarWorkspaceTest.php before retrying.'
}
if ($count -ne 1) {
    throw "Expected exactly one stale assertion, found $count. No files were changed."
}

$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$backupRoot = Join-Path $projectRoot "storage\app\patch-backups\student-announcement-read-state-$stamp"
$backupTest = Join-Path $backupRoot 'tests\Feature\StudentScholarWorkspaceTest.php'
New-Item -ItemType Directory -Path (Split-Path -Parent $backupTest) -Force | Out-Null
Copy-Item -LiteralPath $testPath -Destination $backupTest

$updated = $content.Replace($old, $new)
$encoding = [System.Text.UTF8Encoding]::new($false)
[System.IO.File]::WriteAllText($testPath, $updated, $encoding)

Write-Host 'Updated StudentScholarWorkspaceTest.php.' -ForegroundColor Green
Write-Host "Backup: $backupTest"
Write-Host 'The test now distinguishes an announcement impression from a read acknowledgement (read_at remains null).'
