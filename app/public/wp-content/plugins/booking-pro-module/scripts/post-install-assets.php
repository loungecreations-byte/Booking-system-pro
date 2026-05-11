<?php

declare(strict_types=1);

$skip = getenv('SBDP_SKIP_NPM_POST_INSTALL');
if ($skip === '1' || strcasecmp((string) $skip, 'true') === 0) {
    fwrite(STDOUT, "npm assets skipped via environment flag.\n");
    return 0;
}

$root = dirname(__DIR__);
$packageFile = $root . DIRECTORY_SEPARATOR . 'package.json';

if (! file_exists($packageFile)) {
    fwrite(STDOUT, "npm assets skipped: package.json not found.\n");
    return 0;
}

if (! commandExists('npm')) {
    fwrite(STDOUT, "npm assets skipped: npm executable not available.\n");
    return 0;
}

if (file_exists($root . DIRECTORY_SEPARATOR . 'package-lock.json')) {
    runCommand('npm ci', $root);
} else {
    runCommand('npm install', $root);
}

runCommand('npm run build', $root);

return 0;

function commandExists(string $command): bool
{
    $check = stripos(PHP_OS_FAMILY, 'Windows') === 0 ? 'where' : 'command -v';
    $process = proc_open(
        $check . ' ' . escapeshellarg($command),
        [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes
    );

    if (! is_resource($process)) {
        return false;
    }

    foreach ($pipes as $pipe) {
        fclose($pipe);
    }

    $status = proc_close($process);

    return $status === 0;
}

function runCommand(string $command, string $workingDirectory): void
{
    $descriptorSpec = [
        0 => STDIN,
        1 => STDOUT,
        2 => STDERR,
    ];

    $process = proc_open($command, $descriptorSpec, $pipes, $workingDirectory);
    if (! is_resource($process)) {
        throw new RuntimeException(sprintf('Unable to execute command: %s', $command));
    }

    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        throw new RuntimeException(sprintf('Command failed (%d): %s', $exitCode, $command));
    }
}
