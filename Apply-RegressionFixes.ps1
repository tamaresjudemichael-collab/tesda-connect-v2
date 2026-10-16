$ErrorActionPreference = 'Stop'
$projectRoot = $PSScriptRoot

if (-not (Test-Path (Join-Path $projectRoot 'artisan') -PathType Leaf) -or -not (Test-Path (Join-Path $projectRoot 'routes\web.php') -PathType Leaf)) {
    throw "Hindi makita ang Laravel project root sa $projectRoot. I-extract ang ZIP direkta sa C:\xampp\htdocs\tesda-connect-v2, kung nasaan ang artisan file."
}

$patchRoot = Join-Path $projectRoot 'patch-files'
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$backupRoot = Join-Path $projectRoot "storage\app\patch-backups\regression-fixes-$stamp"
$utf8NoBom = New-Object System.Text.UTF8Encoding($false)
New-Item -ItemType Directory -Path $backupRoot -Force | Out-Null

function Backup-RelativeFile {
    param([Parameter(Mandatory=$true)][string]$RelativePath)
    $source = Join-Path $projectRoot $RelativePath
    if (Test-Path $source -PathType Leaf) {
        $destination = Join-Path $backupRoot $RelativePath
        New-Item -ItemType Directory -Path (Split-Path -Parent $destination) -Force | Out-Null
        Copy-Item -LiteralPath $source -Destination $destination -Force
    }
}

function Install-RelativeFile {
    param([Parameter(Mandatory=$true)][string]$RelativePath)
    $source = Join-Path $patchRoot $RelativePath
    $destination = Join-Path $projectRoot $RelativePath
    if (-not (Test-Path $source -PathType Leaf)) { throw "Patch file missing: $source" }
    Backup-RelativeFile -RelativePath $RelativePath
    New-Item -ItemType Directory -Path (Split-Path -Parent $destination) -Force | Out-Null
    Copy-Item -LiteralPath $source -Destination $destination -Force
    Write-Host "Installed $RelativePath" -ForegroundColor Green
}

# Restore the intake/quota guard that was missing from the earlier toast hotfix,
# while keeping toast behavior for finalized submissions.
Install-RelativeFile 'resources\views\livewire\learner\registration.blade.php'

# Preserve unique viewership metrics while tracking Read separately from Viewed.
Install-RelativeFile 'resources\views\livewire\learner\portal.blade.php'
Install-RelativeFile 'app\Models\AnnouncementView.php'

$migrationRelative = 'database\migrations\2026_10_16_080000_add_read_at_to_announcement_views.php'
$migrationPath = Join-Path $projectRoot $migrationRelative
if (-not (Test-Path $migrationPath -PathType Leaf)) {
    Install-RelativeFile $migrationRelative
} else {
    Write-Host "Migration already exists; left unchanged: $migrationRelative" -ForegroundColor DarkGray
}

# Make the staff-role branch explicit, so the Student/Scholar navigation never
# captures a Focal Person due to a broad fallback branch.
$sidebarRelative = 'resources\views\layouts\app\sidebar.blade.php'
$sidebarPath = Join-Path $projectRoot $sidebarRelative
if (Test-Path $sidebarPath -PathType Leaf) {
    $sidebar = [System.IO.File]::ReadAllText($sidebarPath)
    $explicitFocalPattern = '(?m)@elseif\s*\(\s*\$user->hasRole\(''focal_person''\)\s*\)\s*\r?\n\s*<flux:sidebar\.group heading="MAIN MENU"'
    if ($sidebar -notmatch $explicitFocalPattern) {
        $pattern = '(?m)^([\t ]*)@else[\t ]*\r?\n([\t ]*)<flux:sidebar\.group heading="MAIN MENU"'
        $match = [regex]::Match($sidebar, $pattern)
        if ($match.Success) {
            Backup-RelativeFile -RelativePath $sidebarRelative
            $replacement = $match.Groups[1].Value + "@elseif (`$user->hasRole('focal_person'))" + "`r`n" + $match.Groups[2].Value + '<flux:sidebar.group heading="MAIN MENU"'
            $sidebar = $sidebar.Substring(0, $match.Index) + $replacement + $sidebar.Substring($match.Index + $match.Length)
            [System.IO.File]::WriteAllText($sidebarPath, $sidebar, $utf8NoBom)
            Write-Host 'Made Focal Person sidebar role branch explicit.' -ForegroundColor Green
        } else {
            Write-Warning 'Could not find the generic MAIN MENU branch. Sidebar left unchanged; inspect role-specific navigation manually.'
        }
    } else {
        Write-Host 'Focal Person sidebar branch already explicit.' -ForegroundColor DarkGray
    }
} else {
    Write-Warning 'Sidebar not found; skipped sidebar role-branch update.'
}

# The Applicant/Scholar sidebar was intentionally replaced with its own groups;
# update only the old test assertion that still expected MAIN MENU for learners.
$sidebarTestRelative = 'tests\Feature\RegistrarSidebarNavigationTest.php'
$sidebarTestPath = Join-Path $projectRoot $sidebarTestRelative
if (Test-Path $sidebarTestPath -PathType Leaf) {
    $test = [System.IO.File]::ReadAllText($sidebarTestPath)
    $oldBlock = @'
    $response
        ->assertSee('MAIN MENU')
        ->assertDontSee('ENROLLMENT MANAGEMENT')
        ->assertDontSee('STUDENT RECORDS')
        ->assertDontSee('ACCOUNT MANAGEMENT')
        ->assertDontSee('Mamburao Integrated Farm (MIF)');
'@
    $newBlock = @'
    // Applicants and scholars now have their own Student / Scholar navigation groups.
    $response
        ->assertSee('DASHBOARD')
        ->assertSee('Student / Scholar Dashboard')
        ->assertSee('ENROLLMENT SUBMISSION')
        ->assertSee('View Requirements')
        ->assertSee('Submit Enrollment / Registration Form')
        ->assertDontSee('ENROLLMENT MANAGEMENT')
        ->assertDontSee('STUDENT RECORDS')
        ->assertDontSee('ACCOUNT MANAGEMENT')
        ->assertDontSee('Mamburao Integrated Farm (MIF)');
'@
    $testNormalized = $test.Replace("`r`n", "`n")
    $oldBlockNormalized = $oldBlock.Replace("`r`n", "`n")
    $newBlockNormalized = $newBlock.Replace("`r`n", "`n")
    if ($testNormalized.Contains($oldBlockNormalized)) {
        Backup-RelativeFile -RelativePath $sidebarTestRelative
        $testNormalized = $testNormalized.Replace($oldBlockNormalized, $newBlockNormalized)
        [System.IO.File]::WriteAllText($sidebarTestPath, $testNormalized.Replace("`n", "`r`n"), $utf8NoBom)
        Write-Host 'Updated learner sidebar test to match the new Student / Scholar navigation.' -ForegroundColor Green
    } else {
        Write-Host 'Old learner MAIN MENU assertion not found; sidebar test left unchanged.' -ForegroundColor DarkGray
    }
}

Write-Host "`nRegression fixes applied. Backup folder: $backupRoot" -ForegroundColor Green
Write-Host 'Next run: php artisan migrate; php artisan view:clear; php artisan route:clear' -ForegroundColor Cyan
