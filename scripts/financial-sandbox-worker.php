<?php
declare(strict_types=1);
require __DIR__.'/financial-release/Runtime.php';
require __DIR__.'/financial-release/Catalog.php';
require __DIR__.'/financial-release/Backup.php';
require __DIR__.'/financial-release/Migrator.php';
use FinancialRelease\Runtime;
use FinancialRelease\Catalog;
use FinancialRelease\Migrator;
$allow=getenv('ASAAS_ALLOW_SANDBOX_MUTATIONS');Runtime::boot();
$o=getopt('',['profile:','manifest:','manifest-sha256:','certificate:','certificate-sha256:']);
try{
 if($allow!=='true')throw new RuntimeException('Sandbox mutations not explicitly enabled');
 putenv('ASAAS_ALLOW_SANDBOX_MUTATIONS=true');
 $p=Runtime::profile($o['profile']??'');$db=Runtime::connect($p);
 foreach(['DB_DRIVER'=>'pgsql','DB_HOST'=>$p['host'],'DB_PORT'=>(string)$p['port'],'DB_NAME'=>$p['database'],'DB_USER'=>$p['user'],'DB_PASS'=>getenv('FIN_RELEASE_PASSWORD'),'DB_CHARSET'=>'UTF8','APP_ENV'=>$p['app_env']] as $k=>$v)putenv($k.'='.$v);
 $m=Migrator::manifest($o['manifest']??'',$o['manifest-sha256']??'');$c=Runtime::pinned($o['certificate']??'',$o['certificate-sha256']??'');
 if(($c['kind']??'')!=='financial-rehearsal-v1'||$c['manifest_digest']!==Runtime::hash($m)||count($c['stages']??[])!==24||Catalog::versions($db,$m)!==23||Catalog::schema($db)!==$c['stages'][23])throw new RuntimeException('Worker certificate/schema mismatch');
 require APP_ROOT.'/app/helpers/functions.php';
 echo json_encode((new App\Services\SandboxFinancialWorker($db))->run(),JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable){fwrite(STDERR,"Sandbox worker refused operation; inspect private evidence.\n");exit(1);}
