<?php
declare(strict_types=1);

const SBDP_SKIP_DIRS=['.git','.build_tmp','vendor','node_modules','dist','backups','generated','logs','test-results'];
const SBDP_SAFE_CALLBACKS=['__return_true','__return_false','__return_null','__return_empty_array','__return_empty_string','__return_zero','absint','sanitize_text_field'];

if(PHP_SAPI!=='cli'){fwrite(STDERR,"This script must run via CLI.\n");exit(1);}

main($argv);

function main(array $argv):void{
    $opt=getopt('',['path::','json','format::','verbose','quiet','skip-phpstan','skip-phpcs','help']);
    if(isset($opt['help'])){usage();return;}
    $state=[
        'issues'=>[],
        'json'=>isset($opt['json'])||(isset($opt['format'])&&strtolower((string)$opt['format'])==='json'),
        'verbose'=>isset($opt['verbose']),
        'quiet'=>isset($opt['quiet']),
        'skip_phpstan'=>isset($opt['skip-phpstan']),
        'skip_phpcs'=>isset($opt['skip-phpcs']),
    ];
    $state['plugin']=resolve_plugin_path($opt);
    if(!is_dir($state['plugin'])){add_issue($state,'error','config','Plugin directory not found',$state['plugin']);render($state);exit(1);}    
    $files=collect_php_files($state['plugin']);
    $self=realpath(__FILE__);if($self&&!in_array($self,$files,true)){$files[]=$self;}
    sort($files);
    $state['files']=$files;
    lint_files($files,$state);
    [$functions,$methods,$hooks,$routes]=analyze_files($files,$state);
    $state['functions']=$functions;$state['methods']=$methods;$state['hooks']=$hooks;$state['routes']=$routes;
    check_hooks($state);check_rest_routes($state);
    if(!$state['skip_phpstan']){maybe_run_tool($state,'phpstan',['vendor/bin/phpstan','vendor/bin/phpstan.phar','vendor\\bin\\phpstan.bat','phpstan'],['analyse',$state['plugin'],'--memory-limit=512M'],true);}    
    if(!$state['skip_phpcs']){
        $args=['--report=summary',$state['plugin']];$standard=find_standard(['phpcs.xml','phpcs.xml.dist']);if($standard){$args[]='--standard='.$standard;}
        maybe_run_tool($state,'phpcs',['vendor/bin/phpcs','vendor/bin/phpcs.phar','vendor\\bin\\phpcs.bat','phpcs'],$args,false);
    }
    render($state);
    exit(has_errors($state['issues'])?1:0);
}

function usage():void{fwrite(STDOUT,"Usage: php ".basename(__FILE__)." [--path=<plugin_dir>] [--json|--format=json] [--verbose] [--skip-phpstan] [--skip-phpcs]\n");}

function add_issue(array &$state,string $severity,string $type,string $message,?string $file=null,?int $line=null,array $meta=[]):void{$state['issues'][]=['severity'=>$severity,'type'=>$type,'message'=>$message,'file'=>$file,'line'=>$line,'meta'=>$meta];}

function has_errors(array $issues):bool{foreach($issues as $issue){if($issue['severity']==='error')return true;}return false;}

function resolve_plugin_path(array $opt):string{
    $root=dirname(__FILE__);
    if(!empty($opt['path'])){$candidate=$opt['path'];if(!is_dir($candidate))$candidate=$root.DIRECTORY_SEPARATOR.$candidate;$real=realpath($candidate);if($real!==false)return $real;}
    $default=$root.DIRECTORY_SEPARATOR.'booking-pro-module';return is_dir($default)?(string)realpath($default):$root;
}

function collect_php_files(string $path):array{
    $files=[];$iter=new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS|FilesystemIterator::FOLLOW_SYMLINKS),function(SplFileInfo $current){return $current->isDir()? !in_array($current->getFilename(),SBDP_SKIP_DIRS,true):true;}));
    foreach($iter as $file){if($file instanceof SplFileInfo && $file->isFile() && strtolower($file->getExtension())==='php')$files[]=$file->getPathname();}
    return array_values(array_unique($files));
}

function lint_files(array $files,array &$state):void{
    foreach($files as $file){$cmd=escapeshellarg(PHP_BINARY).' -d display_errors=1 -l '.escapeshellarg($file).' 2>&1';$out=[];$code=0;exec($cmd,$out,$code);if($code!==0)add_issue($state,'error','lint',trim(implode(' ',$out)),$file);elseif($state['verbose']&&!$state['quiet'])add_issue($state,'info','lint','Lint passed',$file);}
}

function analyze_files(array $files,array &$state):array{
    $functions=[];$methods=[];$hooks=[];$routes=[];
    foreach($files as $file){$data=analyze_php_file($file,$state);foreach($data['functions'] as $key=>$info){$functions[$key]=$info;}foreach($data['methods'] as $class=>$map){$methods[$class]=isset($methods[$class])?($methods[$class]+$map):$map;}$hooks=array_merge($hooks,$data['hooks']);$routes=array_merge($routes,$data['routes']);}
    return [$functions,$methods,$hooks,$routes];
}
function analyze_php_file(string $file,array &$state):array{
    $code=@file_get_contents($file);if($code===false){add_issue($state,'warning','io','Unable to read file',$file);return ['functions'=>[],'methods'=>[],'hooks'=>[],'routes'=>[]];}
    $tokens=token_get_all($code);$ns='';$classStack=[];$pending=null;$brace=0;$functions=[];$methods=[];$hooks=[];$routes=[];$count=count($tokens);
    for($i=0;$i<$count;$i++){$token=$tokens[$i];
        if(is_array($token))switch($token[0]){
            case T_NAMESPACE:$ns=read_namespace($tokens,$i);break;
            case T_CLASS:case T_INTERFACE:case T_TRAIT:
                if(!is_prev_double_colon($tokens,$i)){$name=read_next_string($tokens,$i);if($name!==null)$pending=['name'=>qualify_name($ns,$name),'level'=>null];}
                break;
            case T_FUNCTION:
                $name=read_next_string($tokens,$i);if($name===null)break;$context=end($classStack);
                if($context){$key=strtolower(ltrim($context['name'],'\\'));if(!isset($methods[$key]))$methods[$key]=[];$methods[$key][strtolower($name)]=['class'=>$context['name'],'method'=>$name,'file'=>$file,'line'=>$token[2]];}
                else{$fq=qualify_name($ns,$name);$functions[strtolower(ltrim($fq,'\\'))]=['name'=>$fq,'file'=>$file,'line'=>$token[2]];}
                break;
            case T_STRING:
                $lower=strtolower($token[1]);if($lower==='add_action'||$lower==='add_filter'||$lower==='register_rest_route'){$call=parse_call($tokens,$i);if($call){$context=end($classStack);$entry=['file'=>$file,'line'=>$token[2],'args'=>$call['args'],'class'=>$context['name']??null];if($lower==='register_rest_route')$routes[]=$entry;else{$entry['hook']=$lower;$hooks[]=$entry;}$i=$call['end'];}}
                break;
        }else{
            if($token==='{' ){ $brace++;if($pending){$pending['level']=$brace;$classStack[]=$pending;$pending=null;}}
            elseif($token==='}'){if($classStack && end($classStack)['level']===$brace)array_pop($classStack);$brace=max(0,$brace-1);} }
    }
    return ['functions'=>$functions,'methods'=>$methods,'hooks'=>$hooks,'routes'=>$routes];
}

function read_namespace(array $tokens,int $index):string{$name='';for($i=$index+1,$c=count($tokens);$i<$c;$i++){ $t=$tokens[$i]; if(is_array($t) && ($t[0]===T_STRING||$t[0]===T_NS_SEPARATOR))$name.=$t[1]; elseif($t===';'||$t==='{')break; }return $name; }

function qualify_name(string $ns,string $name):string{return $ns!==''?$ns.'\\'.$name:$name;}

function is_prev_double_colon(array $tokens,int $index):bool{for($i=$index-1;$i>=0;$i--){$t=$tokens[$i];if(is_array($t)&&$t[0]===T_WHITESPACE)continue;return $t===T_DOUBLE_COLON||$t==='::';}return false;}

function read_next_string(array $tokens,int $index):?string{for($i=$index+1,$c=count($tokens);$i<$c;$i++){ $t=$tokens[$i]; if(is_array($t)){ if($t[0]===T_STRING)return $t[1]; if($t[0]===T_WHITESPACE||$t[0]===T_COMMENT||$t[0]===T_DOC_COMMENT)continue; }else if($t==='(') return null; }return null;}

function parse_call(array $tokens,int $index):?array{
    for($i=$index+1,$c=count($tokens);$i<$c;$i++){ $t=$tokens[$i]; if($t==='('){$depth=1;$buffer='';$args=[];$in=null;for($j=$i+1;$j<$c;$j++){ $cur=$tokens[$j];$ch=is_array($cur)?$cur[1]:$cur; if(is_array($cur)&&( $cur[0]===T_COMMENT||$cur[0]===T_DOC_COMMENT))continue; if($in!==null){$buffer.=$ch;if($ch===$in && substr($buffer,-2,1)!=='\\')$in=null;continue;} if($ch==='\''||$ch==='"'){$in=$ch;$buffer.=$ch;continue;} if($ch==='('||$ch==='['||$ch==='{'){ $depth++;$buffer.=$ch;continue;} if($ch===')'||$ch===']'||$ch==='}'){ $depth--; if($depth===0){$args[]=trim($buffer);return ['args'=>array_values(array_filter(array_map('trim',$args),fn($v)=>$v!=='')),'end'=>$j];}$buffer.=$ch;continue;} if($ch===','&&$depth===1){$args[]=trim($buffer);$buffer='';continue;} $buffer.=$ch; }
        break; }
        if(is_array($t)&&$t[0]===T_WHITESPACE)continue; break; }
    return null;
}
function interpret_callback(string $expr,?string $context):array{
    $expr=trim($expr);if($expr==='')return ['type'=>'unknown','raw'=>$expr];
    if((($expr[0]==="'"||$expr[0]=='"')&&($expr[-1]==="'"||$expr[-1]=='"'))){return ['type'=>'function','name'=>trim($expr,"'\""),'raw'=>$expr];}
    if(strpos($expr,'::')!==false){[$class,$method]=explode('::',$expr,2);$class=trim($class,"'\" ");$method=trim($method,"'\" ");if(strtolower($class)==='self'||strtolower($class)==='static'||strtolower($class)==='__class__')$class=$context;return ['type'=>'static','class'=>$class,'method'=>$method,'raw'=>$expr];}
    if($expr[0]=='['||stripos($expr,'array(')===0){$parts=split_array_elements($expr);if(count($parts)>=2){$target=trim($parts[0]);$method=extract_string_literal($parts[1]);if($method!==null){if($target==='$this')return ['type'=>'instance','class'=>$context,'method'=>$method,'raw'=>$expr];$class=resolve_callback_class($target,$context);return $class?['type'=>'static','class'=>$class,'method'=>$method,'raw'=>$expr]:['type'=>'unknown','raw'=>$expr];}}
        return ['type'=>'unknown','raw'=>$expr];}
    return ['type'=>'unknown','raw'=>$expr];
}

function resolve_callback_class(string $target,?string $context):?string{
    $target=trim($target,"'\" ");if($target==='$this'||strtolower($target)==='self'||strtolower($target)==='static'||strtolower($target)==='__class__')return $context;return $target!==''?$target:$context;
}

function split_array_elements(string $expr):array{
    $expr=trim($expr);if($expr==='')return [];
    if(stripos($expr,'array(')===0){$start=strpos($expr,'(');$expr=substr($expr,$start+1,-1);}elseif($expr[0]=='['){$expr=substr($expr,1,-1);}    
    $out=[];$buf='';$depth=0;$string=null;$len=strlen($expr);
    for($i=0;$i<$len;$i++){ $ch=$expr[$i]; if($string!==null){$buf.=$ch;if($ch===$string && $expr[$i-1] !== '\\')$string=null;continue;} if($ch==='\''||$ch==='"'){ $string=$ch;$buf.=$ch;continue;} if($ch==='('||$ch==='['||$ch==='{'){ $depth++;$buf.=$ch;continue;} if($ch===')'||$ch===']'||$ch==='}'){ $depth--; $buf.=$ch;continue;} if($ch===','&&$depth===0){$out[]=trim($buf);$buf='';continue;} $buf.=$ch; }
    if(trim($buf)!=='')$out[]=trim($buf);
    return $out;
}

function extract_string_literal(string $expr):?string{$expr=trim($expr);if(($expr[0]==="'"||$expr[0]=='"')&&($expr[-1]==="'"||$expr[-1]=='"'))return trim($expr,"'\"");return null;}

function check_hooks(array &$state):void{
    foreach($state['hooks'] as $hook){$callback=$hook['args'][1]??'';if($callback===''){add_issue($state,'error','hook','Hook without callback argument',$hook['file'],$hook['line']);continue;}
        $info=interpret_callback($callback,$hook['class']);
        if($info['type']==='function'){
            $key=strtolower(ltrim($info['name'],'\\'));
            if(!isset($state['functions'][$key])){add_issue($state,looks_like_core_callback($info['name'])?'warning':'error','hook',"Callback function '{$info['name']}' not found",$hook['file'],$hook['line']);}
        }elseif($info['type']==='static'||$info['type']==='instance'){
            $class=strtolower(ltrim((string)$info['class'],'\\'));$method=strtolower($info['method']??'');
            if($class===''||!isset($state['methods'][$class][$method])){ $label=$info['class']? $info['class'].($info['type']==='instance'?'->':'::').$info['method'] : $info['raw']; add_issue($state,'error','hook','Callback '.$label.' not found',$hook['file'],$hook['line']); }
        }elseif($info['type']==='unknown'){add_issue($state,'warning','hook',"Unrecognized hook callback '{$info['raw']}'",$hook['file'],$hook['line']);}
    }
}

function check_rest_routes(array &$state):void{
    foreach($state['routes'] as $route){$args=$route['args'];if(count($args)<3){add_issue($state,'error','rest','register_rest_route missing third argument (options array)',$route['file'],$route['line']);continue;}
        $options=trim($args[2]);if($options===''){add_issue($state,'error','rest','register_rest_route options array empty',$route['file'],$route['line']);continue;}
        if(!looks_like_array($options)){add_issue($state,'warning','rest','register_rest_route options not detected as array; unable to verify',$route['file'],$route['line']);continue;}
        if(!has_key($options,'permission_callback'))add_issue($state,'error','rest','REST route missing permission_callback',$route['file'],$route['line']);
        else{$perm=interpret_callback(fetch_value($options,'permission_callback'),$route['class']);if($perm['type']==='function'&&strtolower($perm['name'])==='__return_true')add_issue($state,'warning','rest','permission_callback uses __return_true',$route['file'],$route['line']);}
        if(!has_key($options,'args'))add_issue($state,'warning','rest','REST route args not defined; request validation skipped',$route['file'],$route['line']);
        elseif(!args_have_validation(fetch_value($options,'args')))add_issue($state,'warning','rest','REST route args missing validate_callback or sanitize_callback',$route['file'],$route['line']);
    }
}

function looks_like_array(string $expr):bool{$trim=ltrim($expr);return $trim!==''&&($trim[0]=='['||stripos($trim,'array(')===0);}

function has_key(string $expr,string $key):bool{return (bool)preg_match('/[\'"`"]'.preg_quote($key,'/').'[\'"`"]\s*=>/i',$expr);}

function fetch_value(string $expr,string $key):string{
    if(!preg_match('/[\'"`"]'.preg_quote($key,'/').'[\'"`"]\s*=>\s*(.+)/is',$expr,$m))return '';$value=$m[1];$depth=0;$in=null;$buffer='';$len=strlen($value);
    for($i=0;$i<$len;$i++){ $ch=$value[$i]; if($in!==null){$buffer.=$ch;if($ch===$in && $value[$i-1] !== '\\')$in=null;continue;} if($ch==='\''||$ch==='"'){ $in=$ch;$buffer.=$ch;continue;} if($ch==='('||$ch==='['||$ch==='{'){ $depth++;$buffer.=$ch;continue;} if($ch===')'||$ch===']'||$ch==='}'){ $depth--; $buffer.=$ch;continue;} if($ch===','&&$depth<=0)break; $buffer.=$ch; }
    return trim($buffer," \t\r\n,");
}

function args_have_validation(string $expr):bool{
    if($expr==='')return false;
    if(preg_match('/validate_callback|sanitize_callback|\'schema\'/i',$expr))return true;
    return false;
}

function looks_like_core_callback(string $name):bool{$lower=strtolower($name);if(in_array($lower,SBDP_SAFE_CALLBACKS,true))return true;return (bool)preg_match('/^(wp_|woocommerce_|wc_|rest_)/',$lower);}
function maybe_run_tool(array &$state,string $label,array $candidates,array $arguments,bool $failAsError):void{
    $executable=find_executable($candidates);if(!$executable){if($state['verbose']&&!$state['quiet'])add_issue($state,'info',$label,strtoupper($label).' not found; skipping');return;}
    $parts=build_command($executable,$arguments);$command=implode(' ',array_map('escapeshellarg',$parts)).' 2>&1';$out=[];$code=0;exec($command,$out,$code);
    if($code!==0){add_issue($state,$failAsError?'error':'warning',$label,strtoupper($label)." exited with code {$code}",null,null,['output'=>truncate_output($out)]);}elseif($state['verbose']&&!$state['quiet'])add_issue($state,'info',$label,strtoupper($label).' completed',null,null,['output'=>truncate_output($out)]);
}

function find_standard(array $files):?string{$root=dirname(__FILE__);foreach($files as $file){$path=$root.DIRECTORY_SEPARATOR.$file;if(is_file($path))return $path;}return null;}

function truncate_output(array $lines,int $max=20):array{if(count($lines)<= $max)return $lines;$half=(int)($max/2);return array_merge(array_slice($lines,0,$half),['...'],array_slice($lines,-$half));}

function find_executable(array $candidates):?string{
    $root=dirname(__FILE__);foreach($candidates as $candidate){$candidate=str_replace(['/', '\\'],DIRECTORY_SEPARATOR,$candidate);$path=$root.DIRECTORY_SEPARATOR.$candidate;if(is_file($path))return $path;}
    $paths=explode(PATH_SEPARATOR,(string)getenv('PATH'));$ext= DIRECTORY_SEPARATOR==='\\'?['.bat','.cmd','.exe','']:[''];
    foreach($paths as $dir){foreach($candidates as $candidate){$base=basename($candidate);foreach($ext as $suffix){$path=$dir.DIRECTORY_SEPARATOR.$base.$suffix;if(is_file($path)&&is_readable($path))return $path;}}}
    return null;
}

function build_command(string $executable,array $args):array{
    $parts=[];$ext=strtolower((string)pathinfo($executable,PATHINFO_EXTENSION));if(in_array($ext,['bat','cmd','exe'],true))$parts[]=$executable;else{$parts[]=PHP_BINARY;$parts[]=$executable;}foreach($args as $arg)$parts[]=$arg;return $parts;
}

function render(array $state):void{
    $counts=count_by_severity($state['issues']);
    if($state['json']){fwrite(STDOUT,json_encode(['status'=>has_errors($state['issues'])?'error':'ok','pluginPath'=>$state['plugin'],'counts'=>$counts,'issues'=>$state['issues']],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");return;}
    if(!$state['quiet'])fwrite(STDOUT,sprintf("Scanned %d PHP files in %s\n",count($state['files']),$state['plugin']));
    foreach($state['issues'] as $issue){$loc=$issue['file']??'(n/a)';if($issue['line']!==null)$loc.=':'.$issue['line'];fwrite(STDOUT,sprintf('[%s] %s: %s (%s)\n',strtoupper($issue['severity']),$issue['type'],$issue['message'],$loc));if(!empty($issue['meta']['output']))foreach($issue['meta']['output'] as $line)fwrite(STDOUT,'    '.$line."\n");}
    if(!$state['quiet'])fwrite(STDOUT,sprintf('Errors: %d, Warnings: %d, Info: %d%s',$counts['error']??0,$counts['warning']??0,$counts['info']??0,PHP_EOL));
}

function count_by_severity(array $issues):array{$counts=[];foreach($issues as $issue){$counts[$issue['severity']] = ($counts[$issue['severity']]??0)+1;}return $counts;}
