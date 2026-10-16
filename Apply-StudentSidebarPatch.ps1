$ErrorActionPreference = 'Stop'

# This script is packaged at the project root. Extract this ZIP directly into
# C:\xampp\htdocs\tesda-connect-v2, then execute it from that same directory.
$projectRoot = $PSScriptRoot
if (-not (Test-Path (Join-Path $projectRoot 'artisan')) -or -not (Test-Path (Join-Path $projectRoot 'routes\web.php'))) {
    throw "Hindi makita ang Laravel project root sa $projectRoot. I-extract ang ZIP nang direkta sa C:\xampp\htdocs\tesda-connect-v2, kung nasaan ang artisan file."
}

$patchRoot = Join-Path $projectRoot 'patch-files'
$sidebarPath = Join-Path $projectRoot 'resources\views\layouts\app\sidebar.blade.php'
$webPath = Join-Path $projectRoot 'routes\web.php'
$routesSource = Join-Path $patchRoot 'routes\student.php'
$requirementsSource = Join-Path $patchRoot 'resources\views\livewire\learner\requirements.blade.php'
$requirementsTarget = Join-Path $projectRoot 'resources\views\livewire\learner\requirements.blade.php'

foreach ($required in @($sidebarPath, $webPath, $routesSource, $requirementsSource)) {
    if (-not (Test-Path $required -PathType Leaf)) { throw "Missing required file: $required" }
}

$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$backupRoot = Join-Path $projectRoot "storage\app\patch-backups\student-sidebar-$stamp"
New-Item -ItemType Directory -Path $backupRoot -Force | Out-Null
$utf8NoBom = New-Object System.Text.UTF8Encoding($false)

# Copy-Item does not create nested destination folders; create them first.
function Copy-ToPatchBackup {
    param(
        [Parameter(Mandatory = $true)][string]$SourcePath,
        [Parameter(Mandatory = $true)][string]$RelativePath
    )
    $destinationPath = Join-Path $backupRoot $RelativePath
    $destinationDirectory = Split-Path -Parent $destinationPath
    New-Item -ItemType Directory -Path $destinationDirectory -Force | Out-Null
    Copy-Item -LiteralPath $SourcePath -Destination $destinationPath -Force
}

# Update only the role-branch marker in the current sidebar so the global
# toast bridge, Registrar consolidation, and any custom styling remain intact.
$sidebar = [System.IO.File]::ReadAllText($sidebarPath)
if (-not $sidebar.Contains('data-tesda-sidebar-group="learner-dashboard"')) {
    $pattern = '(?m)^([ \t]*)@else[ \t]*\r?\n[ \t]*<flux:sidebar\.group heading="MAIN MENU" class="grid text-blue-200">'
    $match = [regex]::Match($sidebar, $pattern)
    if (-not $match.Success) {
        throw 'Hindi makita ang inaasahang MAIN MENU role-branch marker sa sidebar. Walang sidebar na nabago; ibalik/ipasend ang kasalukuyang sidebar para maitahi nang ligtas.'
    }

    $indent = $match.Groups[1].Value
    $nl = "`r`n"
    $lines = @(
        ($indent + "@elseif (`$user->hasRole('applicant', 'scholar'))"),
        ($indent + '    <flux:sidebar.group heading="DASHBOARD" expandable :expanded="false" data-tesda-sidebar-group="learner-dashboard" class="grid text-blue-200">'),
        ($indent + '        <flux:sidebar.item icon="home" :href="route(''learner.portal'')" :current="request()->routeIs(''learner.portal'')" wire:navigate>'),
        ($indent + '            Student / Scholar Dashboard'),
        ($indent + '        </flux:sidebar.item>'),
        ($indent + '    </flux:sidebar.group>'),
        '',
        ($indent + '    <flux:sidebar.group heading="ENROLLMENT SUBMISSION" expandable :expanded="false" data-tesda-sidebar-group="learner-enrollment" class="grid text-blue-200">'),
        ($indent + '        <flux:sidebar.item icon="document-text" :href="route(''learner.requirements'')" :current="request()->routeIs(''learner.requirements'')" wire:navigate>'),
        ($indent + '            View Requirements'),
        ($indent + '        </flux:sidebar.item>'),
        ($indent + '        <flux:sidebar.item icon="clipboard-document-list" :href="route(''learner.registration'')" :current="request()->routeIs(''learner.registration'')" wire:navigate>'),
        ($indent + '            Submit Enrollment / Registration Form'),
        ($indent + '        </flux:sidebar.item>'),
        ($indent + '    </flux:sidebar.group>'),
        ($indent + '@else'),
        ($indent + '    <flux:sidebar.group heading="MAIN MENU" class="grid text-blue-200">')
    )
    $replacement = $lines -join $nl
    $updatedSidebar = $sidebar.Substring(0, $match.Index) + $replacement + $sidebar.Substring($match.Index + $match.Length)
    Copy-ToPatchBackup -SourcePath $sidebarPath -RelativePath 'resources\views\layouts\app\sidebar.blade.php'
    [System.IO.File]::WriteAllText($sidebarPath, $updatedSidebar, $utf8NoBom)
    Write-Host 'Updated Student / Scholar sidebar only (preserved other layout code).' -ForegroundColor Green
} else {
    Write-Host 'Student / Scholar sidebar groups are already present; no duplicate groups added.' -ForegroundColor DarkGray
}

# Also fix the known unsupported Flux icon without replacing the rest of the sidebar.
$sidebarAfterGroups = [System.IO.File]::ReadAllText($sidebarPath)
$sidebarWithIconFix = $sidebarAfterGroups.Replace('icon="clipboard-check"', 'icon="clipboard-document-list"')
if ($sidebarWithIconFix -cne $sidebarAfterGroups) {
    $sidebarBackup = Join-Path $backupRoot 'resources\views\layouts\app\sidebar.blade.php'
    if (-not (Test-Path $sidebarBackup)) {
        New-Item -ItemType Directory -Path (Split-Path -Parent $sidebarBackup) -Force | Out-Null
        Copy-ToPatchBackup -SourcePath $sidebarPath -RelativePath 'resources\views\layouts\app\sidebar.blade.php'
    }
    [System.IO.File]::WriteAllText($sidebarPath, $sidebarWithIconFix, $utf8NoBom)
    Write-Host 'Replaced unsupported Flux clipboard-check icon.' -ForegroundColor Green
}

# Replace the support files only after making backups; no migrations are needed.
$targets = @(
    @{ Source = $routesSource; Destination = (Join-Path $projectRoot 'routes\student.php'); Relative = 'routes\student.php' },
    @{ Source = $requirementsSource; Destination = $requirementsTarget; Relative = 'resources\views\livewire\learner\requirements.blade.php' }
)
foreach ($item in $targets) {
    $targetDir = Split-Path -Parent $item.Destination
    New-Item -ItemType Directory -Path $targetDir -Force | Out-Null
    if (Test-Path $item.Destination) {
        $backupPath = Join-Path $backupRoot $item.Relative
        New-Item -ItemType Directory -Path (Split-Path -Parent $backupPath) -Force | Out-Null
        Copy-ToPatchBackup -SourcePath $item.Destination -RelativePath $item.Relative
    }
    Copy-Item -LiteralPath $item.Source -Destination $item.Destination -Force
    Write-Host "Installed $($item.Relative)"
}

$web = [System.IO.File]::ReadAllText($webPath)
# Quote-safe single-quoted PowerShell regex; detects common PHP quoting styles.
$requirePattern = 'require\s+__DIR__\s*\.\s*[''"]student\.php[''"]\s*;'
if ($web -notmatch $requirePattern) {
    Copy-ToPatchBackup -SourcePath $webPath -RelativePath 'routes\web.php'
    $web = $web.TrimEnd() + "`r`n`r`n// Student / Scholar requirements route`r`nrequire __DIR__.'/student.php';`r`n"
    [System.IO.File]::WriteAllText($webPath, $web, $utf8NoBom)
    Write-Host "Registered routes/student.php in routes/web.php"
} else {
    Write-Host 'Student routes are already registered in routes/web.php'
}

Write-Host "`nStudent / Scholar sidebar patch applied. Backup: $backupRoot" -ForegroundColor Green
