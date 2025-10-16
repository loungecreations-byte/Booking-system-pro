<?php
declare(strict_types=1);

if (!defined('SBDP_AUDIT_INCLUDE_ONLY')) {
    main($argv);
}

function main(array $argv): void
{
    $options = getopt('', ['path::', 'json', 'verbose', 'help']);

    if (isset($options['help'])) {
        echo "Usage: php audit_booking_module.php [--path=<dir>] [--json] [--verbose]\n";
        return;
    }

    $target = isset($options['path']) && $options['path'] !== '' ? $options['path'] : __DIR__;
    $target = realpath($target) ?: $target;
    $json = isset($options['json']);
    $verbose = isset($options['verbose']);

    $result = new AuditResult($target, $json, $verbose);

    if (!is_dir($target)) {
        $result->add('error', 'config', 'Target directory not found', $target, null);
        $result->render();
        exit(1);
    }

    $scanner = new PluginScanner($target, $result);
    $scanner->scan();

    $result->render();
    exit($result->hasErrors() ? 1 : 0);
}

class AuditIssue
{
    public string $severity;
    public string $type;
    public string $message;
    public ?string $file;
    public ?int $line;
    public array $meta;

    public function __construct(string $severity, string $type, string $message, ?string $file, ?int $line, array $meta = [])
    {
        $this->severity = $severity;
        $this->type = $type;
        $this->message = $message;
        $this->file = $file;
        $this->line = $line;
        $this->meta = $meta;
    }

    public function toArray(): array
    {
        return [
            'severity' => $this->severity,
            'type' => $this->type,
            'message' => $this->message,
            'file' => $this->file,
            'line' => $this->line,
            'meta' => $this->meta,
        ];
    }
}

class AuditResult
{
    private string $target;
    private bool $json;
    private bool $verbose;
    /** @var AuditIssue[] */
    private array $issues = [];

    public function __construct(string $target, bool $json, bool $verbose)
    {
        $this->target = $target;
        $this->json = $json;
        $this->verbose = $verbose;
    }

    public function add(string $severity, string $type, string $message, ?string $file, ?int $line, array $meta = []): void
    {
        $this->issues[] = new AuditIssue($severity, $type, $message, $file, $line, $meta);
    }

    public function hasErrors(): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue->severity === 'error') {
                return true;
            }
        }

        return false;
    }

    public function render(): void
    {
        if ($this->json) {
            $payload = [
                'target' => $this->target,
                'status' => $this->hasErrors() ? 'error' : 'ok',
                'counts' => $this->counts(),
                'issues' => array_map(fn (AuditIssue $issue) => $issue->toArray(), $this->issues),
            ];

            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            return;
        }

        printf("Scanned plugin at %s\n", $this->target);
        foreach ($this->issues as $issue) {
            $location = '';
            if ($issue->file !== null) {
                $location = $issue->file;
                if ($issue->line !== null) {
                    $location .= ':' . $issue->line;
                }
                $location .= ' - ';
            }

            printf('[%s] %s%s%s', strtoupper($issue->severity), $location, $issue->type . ': ', $issue->message);
            if (!empty($issue->meta) && $this->verbose) {
                printf(' %s', json_encode($issue->meta, JSON_UNESCAPED_SLASHES));
            }
            echo "\n";
        }

        $counts = $this->counts();
        printf(
            "Totals => Errors: %d, Warnings: %d, Infos: %d\n",
            $counts['error'] ?? 0,
            $counts['warning'] ?? 0,
            $counts['info'] ?? 0
        );
    }

    private function counts(): array
    {
        $totals = [];
        foreach ($this->issues as $issue) {
            $totals[$issue->severity] = ($totals[$issue->severity] ?? 0) + 1;
        }

        return $totals;
    }
}

class PluginScanner
{
    private const SKIP_DIRECTORIES = [
        '.git',
        '.build_tmp',
        'vendor',
        'node_modules',
        'dist',
        'logs',
        'test-results',
        'build',
        'generated',
        'plugin-3.0',
        'tests',
    ];

    private const KNOWN_FUNCTIONS = [
        'apply_filters',
        'apply_filters_ref_array',
        'do_action',
        'do_action_ref_array',
        '__return_true',
        '__return_false',
        'esc_html__',
        'esc_attr__',
    ];

    private string $root;
    private AuditResult $result;
    /** @var string[] */
    private array $phpFiles = [];
    /** @var array<string,array{name:string,file:string,line:int}> */
    private array $functions = [];
    /** @var array<string,array{file:string,methods:array<string,int>}> */
    private array $classes = [];
    private array $hookCalls = [];
    private array $restRoutes = [];
    private array $deprecatedCalls = [];
    private array $superglobals = [];

    public function __construct(string $root, AuditResult $result)
    {
        $this->root = rtrim($root, DIRECTORY_SEPARATOR);
        $this->result = $result;
    }

    public function scan(): void
    {
        $this->collectPhpFiles();

        foreach ($this->phpFiles as $file) {
            $this->lintFile($file);

            $analyzer = new FileAnalyzer($file);
            $summary = $analyzer->analyze();

            $this->functions = array_merge($this->functions, $summary['functions']);
            $this->mergeClasses($summary['classes']);
            $this->hookCalls = array_merge($this->hookCalls, $summary['hooks']);
            $this->restRoutes = array_merge($this->restRoutes, $summary['routes']);
            $this->deprecatedCalls = array_merge($this->deprecatedCalls, $summary['deprecated']);
            $this->superglobals = array_merge($this->superglobals, $summary['superglobals']);
        }

        $this->evaluateDeprecated();
        $this->evaluateHooks();
        $this->evaluateRestRoutes();
        $this->evaluateSuperglobals();
    }

    private function collectPhpFiles(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            /** @var SplFileInfo $fileInfo */
            if (!$fileInfo->isFile()) {
                continue;
            }

            if (strtolower($fileInfo->getExtension()) !== 'php') {
                continue;
            }

            $relative = substr($fileInfo->getPathname(), strlen($this->root) + 1);
            $segments = explode(DIRECTORY_SEPARATOR, $relative);
            if (count(array_intersect(self::SKIP_DIRECTORIES, $segments)) > 0) {
                continue;
            }

            $this->phpFiles[] = $fileInfo->getPathname();
        }
    }

    private function lintFile(string $file): void
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -l ' . escapeshellarg($file);
        exec($cmd, $output, $code);

        if ($code !== 0) {
            $this->result->add('error', 'lint', implode(' ', $output), $file, null);
        }
    }

    private function mergeClasses(array $classes): void
    {
        foreach ($classes as $class => $info) {
            if (!isset($this->classes[$class])) {
                $this->classes[$class] = $info;
                continue;
            }

            $this->classes[$class]['methods'] = array_merge(
                $this->classes[$class]['methods'],
                $info['methods']
            );
        }
    }

    private function evaluateDeprecated(): void
    {
        foreach ($this->deprecatedCalls as $call) {
            $name = strtolower($call['name']);
            $this->result->add(
                'warning',
                'deprecated',
                "Deprecated function {$call['name']} used",
                $call['file'],
                $call['line']
            );
        }
    }

    private function evaluateHooks(): void
    {
        foreach ($this->hookCalls as $hook) {
            $callback = $hook['callback'];
            $resolved = CallbackResolver::resolve($callback, $hook['context_class']);

            if ($resolved['type'] === 'function') {
                if (!$this->hasFunction($resolved['name'])) {
                    $this->result->add('error', 'hook', "Callback function {$resolved['name']} not found", $hook['file'], $hook['line']);
                }
                continue;
            }

            if ($resolved['type'] === 'static') {
                if ($this->hasMethod($resolved['class'], $resolved['method'])) {
                    continue;
                }

                if (strpos($resolved['class'], '\\') !== false) {
                    continue;
                }

                $this->result->add('error', 'hook', "Callback {$resolved['class']}::{$resolved['method']} not found", $hook['file'], $hook['line']);
                continue;
            }

            if ($resolved['type'] === 'instance') {
                if (!$this->hasMethod($resolved['class'], $resolved['method'])) {
                    $this->result->add('warning', 'hook', "Instance callback {$resolved['class']}->{$resolved['method']} could not be verified", $hook['file'], $hook['line']);
                }
                continue;
            }

            if (in_array($resolved['type'], ['closure', 'variable'], true)) {
                continue;
            }

            $this->result->add('warning', 'hook', "Unresolved hook callback {$callback}", $hook['file'], $hook['line']);
        }
    }

    private function evaluateRestRoutes(): void
    {
        foreach ($this->restRoutes as $route) {
            if ($route['has_permission_callback']) {
                continue;
            }

            $this->result->add(
                'error',
                'rest',
                'REST route missing permission_callback',
                $route['file'],
                $route['line'],
                ['route' => $route['route']]
            );
        }
    }

    private function evaluateSuperglobals(): void
    {
        foreach ($this->superglobals as $entry) {
            if ($entry['sanitized']) {
                continue;
            }

            $this->result->add(
                'warning',
                'superglobal',
                sprintf('Potentially unsanitized %s usage', $entry['var']),
                $entry['file'],
                $entry['line'],
                ['code' => $entry['code']]
            );
        }
    }

    private function hasFunction(string $name): bool
    {
        $key = strtolower(ltrim($name, '\\'));

        if (isset($this->functions[$key])) {
            return true;
        }

        if (in_array($key, self::KNOWN_FUNCTIONS, true)) {
            return true;
        }

        return function_exists($name);
    }

    private function hasMethod(?string $class, string $method): bool
    {
        if ($class === null || $class === '') {
            return false;
        }

        $classKey = strtolower(ltrim($class, '\\'));

        if (isset($this->classes[$classKey]['methods'][strtolower($method)])) {
            return true;
        }

        if (isset($this->classes[$classKey]['file'])) {
            $file = $this->classes[$classKey]['file'];
            if (is_string($file) && is_file($file)) {
                $pattern = '/function\s+' . preg_quote($method, '/') . '\s*\(/i';
                if (preg_match($pattern, (string) file_get_contents($file))) {
                    return true;
                }
            }
        }

        if (!isset($this->classes[$classKey])) {
            $shortClass = strtolower($class);
            foreach ($this->classes as $candidate => $info) {
                if (!str_ends_with($candidate, $shortClass)) {
                    continue;
                }

                if (isset($info['methods'][strtolower($method)])) {
                    return true;
                }

                $file = $info['file'] ?? null;
                if (is_string($file) && is_file($file)) {
                    $pattern = '/function\s+' . preg_quote($method, '/') . '\s*\(/i';
                    if (preg_match($pattern, (string) file_get_contents($file))) {
                        return true;
                    }
                }
            }
        }

        return class_exists($class) && method_exists($class, $method);
    }
}

class CallbackResolver
{
    public static function resolve(string $expression, ?string $contextClass): array
    {
        $expr = trim($expression);
        if ($expr === '' || $expr === 'null') {
            return ['type' => 'unknown'];
        }

        if (str_starts_with($expr, '$')) {
            return ['type' => 'variable', 'name' => $expr];
        }

        if ($expr === '\\Closure' || str_starts_with($expr, 'function(') || str_starts_with($expr, 'static function(')) {
            return ['type' => 'closure'];
        }

        if (preg_match('/^array\s*\((.*)\)$/is', $expr, $m)) {
            $expr = '[' . $m[1] . ']';
        }

        if (preg_match('/\[(.+)\]/', $expr, $m)) {
            $parts = array_map('trim', explode(',', $m[1]));
            if (count($parts) >= 2) {
                $target = str_replace('\\\\', '\\', trim($parts[0], "'\" "));
                $method = str_replace('\\\\', '\\', trim($parts[1], "'\" "));

                if ($target === '$this' && $contextClass) {
                    return ['type' => 'instance', 'class' => $contextClass, 'method' => $method];
                }

                if (in_array(strtolower($target), ['self', 'static', '__class__'], true) && $contextClass) {
                    return ['type' => 'static', 'class' => $contextClass, 'method' => $method];
                }

                if (str_ends_with($target, '::class')) {
                    $class = trim(str_replace('::class', '', $target));
                    return ['type' => 'static', 'class' => ltrim($class, '\\'), 'method' => $method];
                }

                return ['type' => 'static', 'class' => ltrim($target, '\\'), 'method' => $method];
            }
        }

        if (preg_match('/["\']([A-Za-z0-9_\\:]+)["\']/', $expr, $m)) {
            $call = str_replace('\\\\', '\\', $m[1]);
            if (strpos($call, '::') !== false) {
                [$class, $method] = explode('::', $call, 2);
                return ['type' => 'static', 'class' => ltrim($class, '\\'), 'method' => $method];
            }

            return ['type' => 'function', 'name' => ltrim($call, '\\')];
        }

        if (preg_match('/^["\']?([A-Za-z0-9_\\]+::[A-Za-z0-9_]+)["\']?$/', $expr, $m)) {
            $call = str_replace('\\\\', '\\', $m[1]);
            [$class, $method] = explode('::', $call, 2);

            return ['type' => 'static', 'class' => ltrim($class, '\\'), 'method' => $method];
        }

        if (strpos($expr, '::') !== false) {
            [$class, $method] = explode('::', $expr, 2);
            return ['type' => 'static', 'class' => ltrim($class, '\\'), 'method' => $method];
        }

        return ['type' => 'unknown'];
    }
}

class FileAnalyzer
{
    private string $file;
    private string $code;
    private array $tokens;

    public function __construct(string $file)
    {
        $this->file = $file;
        $this->code = (string) file_get_contents($file);
        $this->tokens = token_get_all($this->code);
    }

    public function analyze(): array
    {
        $functions = [];
        $classes = [];
        $hooks = [];
        $routes = [];
        $deprecated = [];
        $superglobals = [];

        $namespace = '';
        $classStack = [];
        $pendingClass = null;
        $braceDepth = 0;
        $count = count($this->tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $this->tokens[$i];

            if (is_array($token)) {
                switch ($token[0]) {
                    case T_NAMESPACE:
                        $namespace = $this->collectNamespace($i);
                        break;
                    case T_CLASS:
                    case T_INTERFACE:
                    case T_TRAIT:
                        $name = $this->readNextString($i);
                        if ($name !== null) {
                            $pendingClass = [
                                'name' => $this->qualifyName($namespace, $name),
                                'line' => $token[2],
                                'depth' => $braceDepth + 1,
                            ];
                        }
                        break;
                    case T_FUNCTION:
                        $name = $this->readNextString($i);
                        if ($name === null) {
                            break;
                        }

                        $lower = strtolower($name);
                        if (!empty($classStack)) {
                            $classInfo = end($classStack);
                            $classKey = strtolower(ltrim($classInfo['name'], '\\'));
                            $classes[$classKey]['file'] = $this->file;
                            $classes[$classKey]['methods'][$lower] = $token[2];
                        } else {
                            $functions[strtolower($this->qualifyName($namespace, $name))] = [
                                'name' => $this->qualifyName($namespace, $name),
                                'file' => $this->file,
                                'line' => $token[2],
                            ];
                        }
                        break;
                    case T_STRING:
                        $lower = strtolower($token[1]);
                        if (($lower === 'add_action' || $lower === 'add_filter') && ($call = $this->parseFunctionCall($i)) !== null) {
                            $hooks[] = [
                                'file' => $this->file,
                                'line' => $token[2],
                                'callback' => $call['args'][1] ?? '',
                                'context_class' => $this->currentClassName($classStack),
                            ];
                        }

                        if ($lower === 'register_rest_route' && ($call = $this->parseFunctionCall($i)) !== null) {
                            $routes[] = [
                                'file' => $this->file,
                                'line' => $token[2],
                                'route' => $call['args'][0] ?? '',
                                'has_permission_callback' => $this->restArgsHavePermission($call['args'][2] ?? ''),
                            ];
                        }
                        break;
                    case T_VARIABLE:
                        if (in_array($token[1], ['$_GET', '$_POST', '$_REQUEST'], true)) {
                            $superglobals[] = [
                                'file' => $this->file,
                                'line' => $token[2],
                                'var' => $token[1],
                                'code' => $this->lineFromNumber($token[2]),
                                'sanitized' => $this->isSanitizedSuperglobal($i),
                            ];
                        }
                        break;
                }
            } else {
                if ($token === '{') {
                    $braceDepth++;
                    if ($pendingClass && $pendingClass['depth'] === $braceDepth) {
                        $classStack[] = [
                            'name' => $pendingClass['name'],
                            'line' => $pendingClass['line'],
                            'depth' => $pendingClass['depth'],
                        ];
                        $classes[strtolower(ltrim($pendingClass['name'], '\\'))] = ['file' => $this->file, 'methods' => []];
                        $pendingClass = null;
                    }
                } elseif ($token === '}') {
                    if (!empty($classStack) && end($classStack)['depth'] === $braceDepth) {
                        array_pop($classStack);
                    }
                    $braceDepth = max(0, $braceDepth - 1);
                }
            }
        }

        return [
            'functions' => $functions,
            'classes' => $classes,
            'hooks' => $hooks,
            'routes' => $routes,
            'deprecated' => $deprecated,
            'superglobals' => $superglobals,
        ];
    }

    private function collectNamespace(int $index): string
    {
        $name = '';
        for ($i = $index + 1, $count = count($this->tokens); $i < $count; $i++) {
            $token = $this->tokens[$i];
            if (is_array($token) && in_array($token[0], [T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $name .= $token[1];
                continue;
            }

            if ($token === ';' || $token === '{') {
                break;
            }
        }

        return $name;
    }

    private function readNextString(int $index): ?string
    {
        for ($i = $index + 1, $count = count($this->tokens); $i < $count; $i++) {
            $token = $this->tokens[$i];
            if (is_array($token)) {
                if ($token[0] === T_STRING) {
                    return $token[1];
                }

                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                break;
            }

            if ($token === '(') {
                break;
            }
        }

        return null;
    }

    private function qualifyName(string $namespace, string $name): string
    {
        return $namespace !== '' ? $namespace . '\\' . $name : $name;
    }

    private function currentClassName(array $stack): ?string
    {
        return empty($stack) ? null : end($stack)['name'];
    }

    private function parseFunctionCall(int $index): ?array
    {
        for ($i = $index + 1, $count = count($this->tokens); $i < $count; $i++) {
            $token = $this->tokens[$i];
            if ($token === '(') {
                $depth = 1;
                $arg = '';
                $args = [];
                $stringDelimiter = null;

                for ($j = $i + 1; $j < $count; $j++) {
                    $current = $this->tokens[$j];
                    $char = is_array($current) ? $current[1] : $current;

                    if (is_array($current) && in_array($current[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
                        $arg .= $char;
                        continue;
                    }

                    if ($stringDelimiter !== null) {
                        $arg .= $char;
                        if ($char === $stringDelimiter && substr($arg, -2, 1) !== '\\') {
                            $stringDelimiter = null;
                        }
                        continue;
                    }

                    if ($char === '\'' || $char === '"') {
                        $stringDelimiter = $char;
                        $arg .= $char;
                        continue;
                    }

                    if ($char === '(' || $char === '[' || $char === '{') {
                        $depth++;
                        $arg .= $char;
                        continue;
                    }

                    if ($char === ')' || $char === ']' || $char === '}') {
                        $depth--;
                        if ($depth === 0) {
                            $args[] = trim($arg);
                            return ['args' => array_filter($args, fn ($value) => $value !== ''), 'end' => $j];
                        }

                        $arg .= $char;
                        continue;
                    }

                    if ($char === ',' && $depth === 1) {
                        $args[] = trim($arg);
                        $arg = '';
                        continue;
                    }

                    $arg .= $char;
                }

                break;
            }

            if (is_array($token) && $token[0] === T_WHITESPACE) {
                continue;
            }

            break;
        }

        return null;
    }

    private function restArgsHavePermission(string $argument): bool
    {
        if ($argument === '') {
            return false;
        }

        if (preg_match('/^\$[A-Za-z_][A-Za-z0-9_]*$/', trim($argument))) {
            return true;
        }

        $tokens = token_get_all('<?php ' . $argument . ';');
        $key = '';

        foreach ($tokens as $token) {
            if (is_array($token)) {
                $id = $token[0];

                if (in_array($id, [T_WHITESPACE, T_OPEN_TAG, T_CLOSE_TAG], true)) {
                    continue;
                }

                if (in_array($id, [T_CONSTANT_ENCAPSED_STRING, T_STRING], true)) {
                    if ($key === '') {
                        $key = trim($token[1], "'\" ");
                    }
                    continue;
                }

                if ($id === T_DOUBLE_ARROW) {
                    if (strcasecmp($key, 'permission_callback') === 0) {
                        return true;
                    }
                    $key = '';
                }

                continue;
            }

            if ($token === '=>') {
                if (strcasecmp($key, 'permission_callback') === 0) {
                    return true;
                }
                $key = '';
                continue;
            }

            if (in_array($token, ['[', '(', '{', ']', ')', '}'], true)) {
                continue;
            }

            $key = '';
        }

        return false;
    }

    private function lineFromNumber(int $line): string
    {
        $lines = explode("\n", $this->code);
        return $lines[$line - 1] ?? '';
    }

    private function isSanitizedSuperglobal(int $index): bool
    {
        for ($i = $index - 1; $i >= max(0, $index - 10); $i--) {
            $token = $this->tokens[$i];
            if (!is_array($token)) {
                if ($token === ';' || $token === '(') {
                    break;
                }
                continue;
            }

            if ($token[0] === T_STRING) {
                $name = strtolower($token[1]);
                if (str_starts_with($name, 'sanitize_') || str_starts_with($name, 'esc_') || in_array($name, ['absint', 'intval', 'floatval', 'boolval', 'wc_clean', 'filter_input', 'filter_input_array', 'filter_var', 'filter_var_array'], true)) {
                    return true;
                }
            }
        }

        return false;
    }
}

