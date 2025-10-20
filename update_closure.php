<?php
$file = 'audit_booking_module.php';
$code = file_get_contents($file);
$needle = '        if ($expr === \'\\Closure\' || str_starts_with($expr, \'function(\')) {';
$replacement = '        if ($expr === \'\\Closure\' || str_starts_with($expr, \'function(\') || str_starts_with($expr, \'static function(\')) {';
$pos = strpos($code, $needle);
if ($pos === false) {
    fwrite(STDERR, "needle not found\n");
    exit(1);
}
$code = substr_replace($code, $replacement, $pos, strlen($needle));
file_put_contents($file, $code);
