<?php

$root = dirname(__DIR__);
$webRoutes = $root.DIRECTORY_SEPARATOR.'routes'.DIRECTORY_SEPARATOR.'web.php';
$includeLine = "require __DIR__.'/admin-oversight.php';";
$settingsLine = "require __DIR__.'/settings.php';";

if (! is_file($webRoutes)) {
    fwrite(STDERR, "Could not find routes/web.php. Run this script from the extracted project root.\n");
    exit(1);
}

$content = file_get_contents($webRoutes);
if ($content === false) {
    fwrite(STDERR, "Could not read routes/web.php. Check file permissions.\n");
    exit(1);
}

if (str_contains($content, $includeLine)) {
    fwrite(STDOUT, "Admin oversight routes are already registered; no changes made.\n");
    exit(0);
}

if (! str_contains($content, $settingsLine)) {
    fwrite(STDERR, "Could not find the expected settings route include. Add this line manually before the settings include:\n{$includeLine}\n");
    exit(1);
}

$backup = $webRoutes.'.bak-admin-oversight-'.date('Ymd-His');
if (! copy($webRoutes, $backup)) {
    fwrite(STDERR, "Could not back up routes/web.php. No changes made.\n");
    exit(1);
}

$updated = str_replace($settingsLine, $includeLine."\n\n".$settingsLine, $content, $count);
if ($count !== 1 || file_put_contents($webRoutes, $updated) === false) {
    copy($backup, $webRoutes);
    fwrite(STDERR, "Could not update routes/web.php. The backup was restored if possible.\n");
    exit(1);
}

fwrite(STDOUT, "Registered admin oversight routes. Backup saved to: {$backup}\n");
