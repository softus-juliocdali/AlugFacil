<?php
declare(strict_types=1);
require __DIR__.'/financial-release/Runtime.php';
require __DIR__.'/financial-release/Catalog.php';
require __DIR__.'/financial-release/Backup.php';
require __DIR__.'/financial-release/Migrator.php';
use FinancialRelease\Runtime;
use FinancialRelease\Backup;
use FinancialRelease\Migrator;
Runtime::boot();
$options=getopt('',['command:','profile:','manifest:','manifest-sha256:','dump:','evidence:','evidence-sha256:','certificate:','certificate-sha256:','output:','pg-tool:','apply','confirm-production:','fail-at:']);
try {
    foreach(['command','profile','output'] as $key)if(!isset($options[$key]))throw new RuntimeException('Missing --'.$key);
    if(file_exists($options['output'])||!is_writable(dirname($options['output'])))throw new RuntimeException('Evidence output must be new and writable');
    $profile=Runtime::profile($options['profile']);
    $command=$options['command'];
    if(!in_array($command,['backup','restore','rehearse','migrate'],true))throw new RuntimeException('Unknown command');
    $apply=isset($options['apply']);
    if($profile['mode']==='production'&&($command==='restore'||$command==='rehearse'))throw new RuntimeException('Never restore/rehearse in production');
    if($profile['mode']==='production'&&$apply&&($options['confirm-production']??'')!==$profile['database'].'@'.$profile['system_identifier'])throw new RuntimeException('Explicit production confirmation required');
    if(!isset($options['dump']))throw new RuntimeException('Missing --dump');
    $db=Runtime::connect($profile);
    if($command==='backup'){
        if(!isset($options['pg-tool']))throw new RuntimeException('Missing --pg-tool');
        $result=Backup::create($db,$profile,$options['dump'],$options['pg-tool']);
    }else{
        $evidence=Runtime::pinned($options['evidence']??'', $options['evidence-sha256']??'');
        if($command==='restore'){
            if(!$apply||!isset($options['pg-tool']))throw new RuntimeException('Restore requires --apply and --pg-tool');
            $result=Backup::restore($db,$profile,$evidence,$options['dump'],$options['pg-tool']);
        }else{
            $manifest=Migrator::manifest($options['manifest']??'', $options['manifest-sha256']??'');
            $certificate=$command==='migrate'?Runtime::pinned($options['certificate']??'', $options['certificate-sha256']??''):null;
            $result=Migrator::execute($db,$profile,$manifest,$evidence,$options['dump'],$certificate,$apply,$options['fail-at']??null);
        }
    }
    Runtime::write($options['output'],$result);
    echo json_encode(['result'=>$result['kind'],'output'=>basename($options['output']),'sha256'=>hash_file('sha256',$options['output'])],JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable $e){
    // PDO errors may contain row contents; never print them or credentials.
    fwrite(STDERR,($e instanceof PDOException?'PostgreSQL refused operation; transaction rolled back (SQLSTATE '.$e->getCode().')':$e->getMessage()).PHP_EOL);
    exit(1);
}
