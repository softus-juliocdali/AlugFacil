<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
if(getenv('DB_NAME')!=='alugfacil_dev'||!in_array(getenv('DB_HOST'),['localhost','127.0.0.1','::1'],true))throw new RuntimeException('Somente banco local.');
$files=glob(APP_ROOT.'/storage/financial-migration/alugfacil_dev-*.dump');rsort($files);$file=$files[0]??'';
if(!$file||!hash_equals(trim(file_get_contents($file.'.sha256')),hash_file('sha256',$file)))throw new RuntimeException('Backup invalido.');
$target='alugfacil_restore_'.gmdate('Ymd_His');$bin='C:/Program Files/PostgreSQL/18/bin/';$env=getenv();$env['PGPASSWORD']=(string)getenv('DB_PASS');
$run=static function(string $exe,array $args)use($bin,$env):void{
 $p=proc_open(array_merge([$bin.$exe.'.exe','--host='.getenv('DB_HOST'),'--port='.getenv('DB_PORT'),'--username='.getenv('DB_USER'),'--no-password'],$args),[1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
 if(!is_resource($p))throw new RuntimeException('Falha ao iniciar ferramenta PostgreSQL.');stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($p)!==0)throw new RuntimeException('Ferramenta PostgreSQL falhou: '.$exe.'; detalhes omitidos para proteger dados.');
};
$run('createdb',[$target]);
try{
 $run('pg_restore',['--exit-on-error','--dbname='.$target,$file]);
 $db=new PDO('pgsql:host='.getenv('DB_HOST').';port='.getenv('DB_PORT').';dbname='.$target,getenv('DB_USER'),getenv('DB_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $report=['backup'=>basename($file),'sha256'=>hash_file('sha256',$file),'banco_temporario'=>$target,'restore'=>'completo','tabelas'=>(int)$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='public'")->fetchColumn(),'migrations'=>(int)$db->query('SELECT COUNT(*) FROM financeiro_schema_versions')->fetchColumn(),'reservas'=>(int)$db->query('SELECT COUNT(*) FROM reservas')->fetchColumn(),'constraints_nao_validadas'=>(int)$db->query("SELECT COUNT(*) FROM pg_constraint WHERE connamespace='public'::regnamespace AND NOT convalidated")->fetchColumn()];
 $db=null;
}finally{$run('dropdb',[$target]);}
$report['banco_temporario_removido']=true;if(file_put_contents(APP_ROOT.'/storage/financial-migration/restore-20260916.json',json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR))===false)throw new RuntimeException('Restore concluido; falha ao gravar evidencia.');echo json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
