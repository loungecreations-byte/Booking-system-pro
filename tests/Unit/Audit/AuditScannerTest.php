<?php

declare(strict_types=1);

namespace BSP\Tests\Unit\Audit;

use PHPUnit\Framework\TestCase;

final class AuditScannerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'audit-scanner-' . uniqid();
        if (!mkdir($concurrentDirectory = $this->tempDir) && !is_dir($concurrentDirectory)) {
            throw new \RuntimeException('Unable to create temp directory');
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testFilterInputArrayIsConsideredSanitized(): void
    {
        $sampleFile = $this->tempDir . DIRECTORY_SEPARATOR . 'sample.php';
        $code = <<<'PHP'
<?php

declare(strict_types=1);

function handle_request(): array {
    $input = filter_input_array(
        INPUT_POST,
        [
            'name' => [
                'filter' => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
                'flags' => FILTER_REQUIRE_SCALAR,
            ],
        ]
    ) ?: [];

    return $input;
}
PHP;
        file_put_contents($sampleFile, $code);

        if (!defined('SBDP_AUDIT_INCLUDE_ONLY')) {
            define('SBDP_AUDIT_INCLUDE_ONLY', true);
        }

        global $argv;
        if (!isset($argv)) {
            $argv = ['audit'];
        }

        require_once __DIR__ . '/../../../audit_booking_module.php';

        $result = new \AuditResult($this->tempDir, true, false);
        $scanner = new \PluginScanner($this->tempDir, $result);
        $scanner->scan();

        ob_start();
        $result->render();
        $output = (string) ob_get_clean();
        $data = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('ok', $data['status']);
        $this->assertIsArray($data['issues']);
        $this->assertCount(0, $data['issues'], 'Filter input usage should not trigger superglobal warnings.');
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = scandir($directory);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
