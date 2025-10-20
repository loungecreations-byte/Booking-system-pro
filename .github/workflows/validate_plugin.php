<?php                                                                                                                           
  declare(strict_types=1);                                                                                                        
                                                                                                                                  
  /**                                                                                                                             
   * Static audit tool for booking-pro-module.                                                                                    
   *                                                                                                                              
   * Usage:                                                                                                                       
   *   php validate_plugin.php                                                                                                    
   */                                                                                                                             
                                                                                                                                  
  const PLUGIN_DIR = __DIR__ . '/booking-pro-module';                                                                             
                                                                                                                                  
  final class PluginValidator                                                                                                     
  {                                                                                                                               
      private array $errors = [];                                                                                                 
      private array $warnings = [];                                                                                               
      private array $info = [];                                                                                                   
      private array $phpFiles = [];                                                                                               
                                                                                                                                  
      public function run(): void                                                                                                 
      {                                                                                                                           
          $this->loadPhpFiles();                                                                                                  
                                                                                                                                  
          $this->checkPhpLint();                                                                                                  
          $this->checkComposerAutoload();                                                                                         
          $this->scanTokens();                                                                                                    
          $this->scanRestRoutes();                                                                                                
          $this->scanHooks();                                                                                                     
          $this->report();                                                                                                        
      }                                                                                                                           
                                                                                                                                  
      private function loadPhpFiles(): void                                                                                       
      {                                                                                                                           
          if (!is_dir(PLUGIN_DIR)) {                                                                                              
              $this->errors[] = [                                                                                                 
                  'type' => 'fatal',                                                                                              
                  'message' => sprintf('Plugin directory not found: %s', PLUGIN_DIR),                                             
              ];                                                                                                                  
              $this->report();                                                                                                    
              exit(1);                                                                                                            
          }                                                                                                                       
                                                                                                                                  
          $iterator = new RecursiveIteratorIterator(                                                                              
              new RecursiveDirectoryIterator(                                                                                     
                  PLUGIN_DIR,                                                                                                     
                  FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS                                             
              )                                                                                                                   
          );                                                                                                                      
                                                                                                                                  
          /** @var SplFileInfo $file */                                                                                           
          foreach ($iterator as $file) {                                                                                          
              if ($file->isFile() && $file->getExtension() === 'php') {                                                           
                  $this->phpFiles[] = $file->getPathname();                                                                       
              }                                                                                                                   
          }                                                                                                                       
                                                                                                                                  
          $this->info[] = [                                                                                                       
              'type' => 'info',                                                                                                   
              'message' => sprintf('Collected %d PHP files', count($this->phpFiles)),                                             
          ];                                                                                                                      
      }                                                                                                                           
                                                                                                                                  
      private function checkPhpLint(): void                                                                                       
      {                                                                                                                           
          $lintErrors = [];                                                                                                       
          foreach ($this->phpFiles as $path) {                                                                                    
              $cmd = sprintf('php -l %s 2>&1', escapeshellarg($path));                                                            
              $output = shell_exec($cmd);                                                                                         
                                                                                                                                  
              if ($output === null) {                                                                                             
                  continue;                                                                                                       
              }                                                                                                                   
                                                                                                                                  
              if (strpos($output, 'No syntax errors detected') === false) {                                                       
                  $lintErrors[] = trim($output);                                                                                  
              }                                                                                                                   
          }                                                                                                                       
                                                                                                                                  
          foreach ($lintErrors as $err) {                                                                                         
              $this->errors[] = [                                                                                                 
                  'type' => 'syntax',                                                                                             
                  'message' => $err,                                                                                              
              ];                                                                                                                  
          }                                                                                                                       
      }                                                                                                                           
                                                                                                                                  
      private function checkComposerAutoload(): void                                                                              
      {                                                                                                                           
          $composerPath = PLUGIN_DIR . '/composer.json';                                                                          
          if (!is_readable($composerPath)) {                                                                                      
              $this->warnings[] = [                                                                                               
                  'type' => 'composer',                                                                                           
                  'message' => 'composer.json missing or unreadable',                                                             
              ];                                                                                                                  
              return;                                                                                                             
          }                                                                                                                       
                                                                                                                                  
          $json = json_decode(file_get_contents($composerPath), true);                                                            
          if (!is_array($json) || !isset($json['autoload']['psr-4'])) {                                                           
              $this->warnings[] = [                                                                                               
                  'type' => 'composer',                                                                                           
                  'message' => 'PSR-4 autoload section missing in composer.json',                                                 
              ];                                                                                                                  
              return;                                                                                                             
          }                                                                                                                       
                                                                                                                                  
          $autoload = $json['autoload']['psr-4'];                                                                                 
          foreach ($autoload as $namespace => $directory) {                                                                       
              $path = PLUGIN_DIR . '/' . trim($directory, '/');                                                                   
              if (!is_dir($path)) {                                                                                               
                  $this->warnings[] = [                                                                                           
                      'type' => 'autoload',                                                                                       
                      'message' => sprintf('Autoload path %s for namespace %s not found', $directory, $namespace),                
                  ];                                                                                                              
              }                                                                                                                   
          }                                                                                                                       
      }                                                                                                                           
                                                                                                                                  
      private function scanTokens(): void                                                                                         
      {                                                                                                                           
          foreach ($this->phpFiles as $path) {                                                                                    
              $code = file_get_contents($path);                                                                                   
              if ($code === false) {                                                                                              
                  continue;                                                                                                       
              }                                                                                                                   
                                                                                                                                  
              $tokens = token_get_all($code);                                                                                     
              $deprecatedCalls = [                                                                                                
                  'create_function',                                                                                              
                  'mysql_query',                                                                                                  
                  'wp_reset_query',                                                                                               
              ];                                                                                                                  
                                                                                                                                  
              foreach ($tokens as $index => $token) {                                                                             
                  if (!is_array($token)) {                                                                                        
                      continue;                                                                                                   
                  }                                                                                                               
                                                                                                                                  
                  [$id, $text, $line] = $token;                                                                                   
                                                                                                                                  
                  if ($id === T_STRING && in_array(strtolower($text), $deprecatedCalls, true)) {                                  
                      $this->warnings[] = [                                                                                       
                          'type' => 'deprecated_function',                                                                        
                          'file' => $this->relative($path),                                                                       
                          'line' => $line,                                                                                        
                          'message' => sprintf('Deprecated function call: %s', $text),                                            
                      ];                                                                                                          
                  }                                                                                                               
              }                                                                                                                   
          }                                                                                                                       
      }                                                                                                                           
                                                                                                                                  
      private function scanRestRoutes(): void                                                                                     
      {                                                                                                                           
          foreach ($this->phpFiles as $path) {                                                                                    
              $code = file_get_contents($path);                                                                                   
              if ($code === false || stripos($code, 'register_rest_route') === false) {                                           
                  continue;                                                                                                       
              }                                                                                                                   
                                                                                                                                  
              $matches = [];                                                                                                      
              preg_match_all('/register_rest_route\s*\((.*?)\);/s', $code, $matches);                                             
                                                                                                                                  
              foreach ($matches[0] as $definition) {                                                                              
                  $hasPermissionCallback = strpos($definition, 'permission_callback') !== false;                                  
                  $hasValidate = strpos($definition, 'args') !== false || strpos($definition, 'validate_callback') !== false;     
                                                                                                                                  
                  if (!$hasPermissionCallback) {                                                                                  
                      $this->errors[] = [                                                                                         
                          'type' => 'rest_route',                                                                                 
                          'file' => $this->relative($path),                                                                       
                          'message' => 'REST route without permission_callback',                                                  
                          'snippet' => trim($definition),                                                                         
                      ];                                                                                                          
                  }                                                                                                               
                                                                                                                                  
                  if (!$hasValidate) {                                                                                            
                      $this->warnings[] = [                                                                                       
                          'type' => 'rest_route',                                                                                 
                          'file' => $this->relative($path),                                                                       
                          'message' => 'REST route missing validation or args definition',                                        
                          'snippet' => trim($definition, " \n\r\t"),                                                              
                      ];                                                                                                          
                  }                                                                                                               
              }                                                                                                                   
          }                                                                                                                       
      }                                                                                                                           
                                                                                                                                  
      private function scanHooks(): void                                                                                          
      {                                                                                                                           
          foreach ($this->phpFiles as $path) {                                                                                    
              $code = file_get_contents($path);                                                                                   
              if ($code === false) {                                                                                              
                  continue;                                                                                                       
              }                                                                                                                   
                                                                                                                                  
              $pattern = '/add_(?:action|filter)\s*\(([^;]+)\);/m';                                                               
              if (!preg_match_all($pattern, $code, $matches, PREG_SET_ORDER)) {                                                   
                  continue;                                                                                                       
              }                                                                                                                   
                                                                                                                                  
              foreach ($matches as $match) {                                                                                      
                  $hookCall = $match[0];                                                                                          
                  $arguments = $match[1];                                                                                         
                                                                                                                                  
                  $params = $this->extractHookParams($arguments);                                                                 
                  if (count($params) < 2) {                                                                                       
                      $this->warnings[] = [                                                                                       
                          'type' => 'hook',                                                                                       
                          'file' => $this->relative($path),                                                                       
                          'message' => 'Hook with missing callback',                                                              
                          'snippet' => trim($hookCall),                                                                           
                      ];                                                                                                          
                      continue;                                                                                                   
                  }                                                                                                               
                                                                                                                                  
                  $callback = $params[1];                                                                                         
                  if (strpos($callback, '::class') !== false) {                                                                   
                      continue;                                                                                                   
                  }                                                                                                               
                                                                                                                                  
                  if (strpos($callback, 'array') !== false && strpos($callback, '[') === false) {                                 
                      $this->warnings[] = [                                                                                       
                          'type' => 'hook',                                                                                       
                          'file' => $this->relative($path),                                                                       
                          'message' => 'Possibly invalid callback definition',                                                    
                          'snippet' => trim($hookCall),                                                                           
                      ];                                                                                                          
                  }                                                                                                               
              }                                                                                                                   
          }                                                                                                                       
      }                                                                                                                           
                                                                                                                                  
      private function extractHookParams(string $arguments): array                                                                
      {                                                                                                                           
          $buffer = '';                                                                                                           
          $depth = 0;                                                                                                             
          $params = [];                                                                                                           
          $length = strlen($arguments);                                                                                           
                                                                                                                                  
          for ($i = 0; $i < $length; $i++) {                                                                                      
              $char = $arguments[$i];                                                                                             
                                                                                                                                  
              if ($char === '(') {                                                                                                
                  $depth++;                                                                                                       
              } elseif ($char === ')') {                                                                                          
                  if ($depth === 0) {                                                                                             
                      $buffer .= $char;                                                                                           
                      break;                                                                                                      
                  }                                                                                                               
                  $depth--;                                                                                                       
              } elseif ($char === ',' && $depth === 0) {                                                                          
                  $params[] = trim($buffer);                                                                                      
                  $buffer = '';                                                                                                   
                  continue;                                                                                                       
              }                                                                                                                   
                                                                                                                                  
              $buffer .= $char;                                                                                                   
          }                                                                                                                       
                                                                                                                                  
          if ($buffer !== '') {                                                                                                   
              $params[] = trim($buffer);                                                                                          
          }                                                                                                                       
                                                                                                                                  
          return $params;                                                                                                         
      }                                                                                                                           
                                                                                                                                  
      private function relative(string $path): string                                                                             
      {                                                                                                                           
          return ltrim(str_replace(PLUGIN_DIR, '', $path), '/\\');                                                                
      }                                                                                                                           
                                                                                                                                  
      private function report(): void                                                                                             
      {                                                                                                                           
          $report = [                                                                                                             
              'summary' => [                                                                                                      
                  'errors' => count($this->errors),                                                                               
                  'warnings' => count($this->warnings),                                                                           
                  'info' => count($this->info),                                                                                   
              ],                                                                                                                  
              'info' => $this->info,                                                                                              
              'warnings' => $this->warnings,                                                                                      
              'errors' => $this->errors,                                                                                          
          ];                                                                                                                      
                                                                                                                                  
          echo json_encode($report, JSON_PRETTY_PRINT) . PHP_EOL;                                                                 
                                                                                                                                  
          if (!empty($this->errors)) {                                                                                            
              exit(1);                                                                                                            
          }                                                                                                                       
      }                                                                                                                           
  }                                                                                                                               
                                                                                                                                  
  $validator = new PluginValidator();                                                                                             
  $validator->run();