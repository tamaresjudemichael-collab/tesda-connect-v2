$ErrorActionPreference = 'Stop'

$projectRoot = (Get-Location).Path
if (-not (Test-Path (Join-Path $projectRoot 'artisan') -PathType Leaf)) {
    throw 'Patakbuhin ang script mula sa project root na may artisan, halimbawa C:\xampp\htdocs\tesda-connect-v2.'
}

$stagedRoute = Join-Path (Split-Path -Parent $MyInvocation.MyCommand.Path) 'routes\student.php'
$destinationRoute = Join-Path $projectRoot 'routes\student.php'
$webRoutes = Join-Path $projectRoot 'routes\web.php'
if (-not (Test-Path $webRoutes -PathType Leaf)) {
    throw 'Hindi makita ang routes\web.php sa project root.'
}
if (-not (Test-Path $destinationRoute -PathType Leaf)) {
    if (-not (Test-Path $stagedRoute -PathType Leaf)) {
        throw 'Hindi makita ang student.php route file sa hotfix package.'
    }
    $routeDirectory = Split-Path -Parent $destinationRoute
    New-Item -ItemType Directory -Force -Path $routeDirectory | Out-Null
    Copy-Item -LiteralPath $stagedRoute -Destination $destinationRoute
    Write-Host 'Na-install: routes/student.php' -ForegroundColor Green
} else {
    Write-Host 'Existing routes/student.php found; hindi ito papalitan.' -ForegroundColor DarkGray
}

$content = [System.IO.File]::ReadAllText($webRoutes)
$requireLine = "require __DIR__.'/student.php';"
if ($content.Contains($requireLine)) {
    Write-Host 'Naka-register na ang student route file sa routes/web.php.' -ForegroundColor DarkGray
    exit 0
}

$anchor = "require __DIR__.'/settings.php';"
if (-not $content.Contains($anchor)) {
    throw "Hindi mahanap ang require __DIR__.'/settings.php'; sa routes/web.php. Walang binagong routes/web.php. Idagdag nang mano-mano ang `require __DIR__.'/student.php';` bago ang settings.php require."
}

$backupRoot = Join-Path $projectRoot ("storage\app\patch-backups\student-route-fix-" + (Get-Date -Format 'yyyyMMdd-HHmmss'))
New-Item -ItemType Directory -Force -Path $backupRoot | Out-Null
Copy-Item -LiteralPath $webRoutes -Destination (Join-Path $backupRoot 'web.php') -Force
$updated = $content.Replace($anchor, $requireLine + "`r`n`r`n" + $anchor)
$utf8NoBom = New-Object System.Text.UTF8Encoding($false)
[System.IO.File]::WriteAllText($webRoutes, $updated, $utf8NoBom)
Write-Host 'Na-register ang learner.requirements route sa routes/web.php.' -ForegroundColor Green
Write-Host "Backup: $backupRoot" -ForegroundColor Cyan
