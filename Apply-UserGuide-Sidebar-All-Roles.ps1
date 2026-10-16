$ErrorActionPreference = 'Stop'
$projectRoot = $PSScriptRoot
$patchRoot = Join-Path $PSScriptRoot 'patch-files'

$routesPath = Join-Path $projectRoot 'routes\web.php'
$sidebarPath = Join-Path $projectRoot 'resources\views\layouts\app\sidebar.blade.php'
$guideTarget = Join-Path $projectRoot 'resources\views\livewire\user-guide.blade.php'
$testTarget = Join-Path $projectRoot 'tests\Feature\UserGuideTest.php'
$guideSource = Join-Path $patchRoot 'resources\views\livewire\user-guide.blade.php'
$testSource = Join-Path $patchRoot 'tests\Feature\UserGuideTest.php'

foreach ($path in @($routesPath, $sidebarPath, $guideSource, $testSource)) {
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
        throw "Required file was not found: $path. Extract the entire ZIP into the Laravel project root. No files have been changed."
    }
}

$routesText = [System.IO.File]::ReadAllText($routesPath)
$sidebarText = [System.IO.File]::ReadAllText($sidebarPath)
$originalRoutes = $routesText
$originalSidebar = $sidebarText

# Ensure the handbook route exists once, inside the current authenticated/active route group.
if ($routesText -notmatch '->name\([''"]user-guide[''"]\)') {
    $routeAnchor = [regex]::Match($routesText, 'Volt::route\([''\"]dashboard[''\"],\s*[''\"]dashboard[''\"]\)->name\([''\"]dashboard[''\"]\);')
    if (-not $routeAnchor.Success) {
        throw 'Could not find the dashboard Volt route in routes/web.php. No files have been changed.'
    }
    $routeLine = "`r`n    Volt::route('user-guide', 'user-guide')->name('user-guide');"
    $routesText = $routesText.Insert($routeAnchor.Index + $routeAnchor.Length, $routeLine)
}

# Put one common handbook link after every role-specific navigation section.
# The data attribute makes re-running the installer idempotent.
if ($sidebarText -notmatch 'data-user-guide-sidebar') {
    $navClose = [regex]::Match($sidebarText, '(?i)</flux:sidebar\.nav>')
    if (-not $navClose.Success) {
        throw 'Could not find the closing flux:sidebar.nav tag. No files have been changed.'
    }
    $sidebarItem = @'

                <div data-user-guide-sidebar class="mt-auto w-full pt-6 pb-2">
                    <flux:sidebar.item
                        icon="book-open"
                        :href="route('user-guide')"
                        :current="request()->routeIs('user-guide')"
                        wire:navigate
                    >
                        User Guide &amp; Role Handbook
                    </flux:sidebar.item>
                </div>
'@
    $sidebarText = $sidebarText.Insert($navClose.Index, $sidebarItem)
}

# Make a timestamped backup of current target files before writing any changes.
$backupStamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$backupRoot = Join-Path $projectRoot "storage\app\patch-backups\user-guide-sidebar-$backupStamp"
$targets = @($routesPath, $sidebarPath, $guideTarget, $testTarget)
foreach ($target in $targets) {
    if (Test-Path -LiteralPath $target -PathType Leaf) {
        $relative = $target.Substring($projectRoot.Length).TrimStart('\')
        $backupFile = Join-Path $backupRoot $relative
        New-Item -ItemType Directory -Path (Split-Path -Parent $backupFile) -Force | Out-Null
        Copy-Item -LiteralPath $target -Destination $backupFile -Force
    }
}

$utf8NoBom = New-Object System.Text.UTF8Encoding($false)
[System.IO.File]::WriteAllText($routesPath, $routesText, $utf8NoBom)
[System.IO.File]::WriteAllText($sidebarPath, $sidebarText, $utf8NoBom)
foreach ($target in @($guideTarget, $testTarget)) {
    New-Item -ItemType Directory -Path (Split-Path -Parent $target) -Force | Out-Null
}
Copy-Item -LiteralPath $guideSource -Destination $guideTarget -Force
Copy-Item -LiteralPath $testSource -Destination $testTarget -Force

Write-Host ''
Write-Host 'User Guide & Role Handbook sidebar link installed for all roles.' -ForegroundColor Green
Write-Host "Backup folder: $backupRoot"
Write-Host 'Next run:'
Write-Host '  php artisan route:clear'
Write-Host '  php artisan view:clear'
Write-Host '  php artisan route:list --name=user-guide'
Write-Host '  php artisan test --filter=UserGuideTest'
