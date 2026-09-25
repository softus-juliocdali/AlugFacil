<?php
declare(strict_types=1);

// Deliberately avoids the application/gateway bootstrap and never prints credentials.
$load = require dirname(__DIR__).'/app/config/env.php';
$load(dirname(__DIR__).'/.env');
$host = (string)getenv('DB_HOST');
$name = (string)getenv('DB_NAME');
if (!in_array($host, ['localhost','127.0.0.1','::1'], true) || $name !== 'alugfacil_dev') {
    throw new RuntimeException('Somente o banco local alugfacil_dev e permitido.');
}
$db = new PDO('pgsql:host='.$host.';port='.getenv('DB_PORT').';dbname='.$name,
    getenv('DB_USER'), getenv('DB_PASS'), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$dir = dirname(__DIR__).'/storage/financial-migration';
if (!is_dir($dir) && !mkdir($dir,0700,true)) throw new RuntimeException('Sem permissao para gravar o inventario/backup local.');
$db->exec('BEGIN READ ONLY');
$queries = [
    'database'=>"SELECT current_database(), version(), current_setting('TimeZone') timezone",
    'columns'=>"SELECT table_name,column_name,data_type,character_maximum_length,is_nullable,column_default FROM information_schema.columns WHERE table_schema='public' ORDER BY table_name,ordinal_position",
    'constraints'=>"SELECT c.relname,n.conname,pg_get_constraintdef(n.oid) definition FROM pg_constraint n JOIN pg_class c ON c.oid=n.conrelid JOIN pg_namespace s ON s.oid=c.relnamespace WHERE s.nspname='public' ORDER BY c.relname,n.conname",
    'indexes'=>"SELECT tablename,indexname,indexdef FROM pg_indexes WHERE schemaname='public' ORDER BY tablename,indexname",
    'triggers'=>"SELECT event_object_table,trigger_name,action_statement FROM information_schema.triggers WHERE trigger_schema='public' ORDER BY event_object_table,trigger_name",
];
$inventory=[];
foreach($queries as $key=>$sql) $inventory[$key]=$db->query($sql)->fetchAll();
$db->rollBack();
foreach(glob(dirname(__DIR__).'/database/*.sql') as $file) $inventory['migration_files'][]=['file'=>basename($file),'sha256'=>hash_file('sha256',$file)];
$stamp=gmdate('Ymd-His');
if (file_put_contents($dir.'/schema-'.$stamp.'.json',json_encode($inventory,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)) === false) throw new RuntimeException('Falha ao gravar inventario.');
echo 'Inventario local gravado: schema-'.$stamp.'.json'.PHP_EOL;
if (!in_array('--backup',$argv,true)) exit;
$dump='C:/Program Files/PostgreSQL/18/bin/pg_dump.exe';
if (!is_file($dump)) throw new RuntimeException('pg_dump 18 nao encontrado.');
$target=$dir.'/alugfacil_dev-'.$stamp.'.dump';
$environment=getenv();
$environment['PGPASSWORD']=(string)getenv('DB_PASS');
$process=proc_open([$dump,'--host='.$host,'--port='.getenv('DB_PORT'),'--username='.getenv('DB_USER'),'--dbname='.$name,'--no-password','--format=custom','--file='.$target], [1=>['pipe','w'],2=>['pipe','w']], $pipes,null,$environment);
if(!is_resource($process)) throw new RuntimeException('Falha ao iniciar backup.');
$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
if(proc_close($process)!==0) throw new RuntimeException('Backup falhou; nenhuma migration autorizada pelo preflight.');
file_put_contents($target.'.sha256',hash_file('sha256',$target).PHP_EOL);
echo 'Backup logico concluido: '.basename($target).' ('.filesize($target).' bytes)'.PHP_EOL;
