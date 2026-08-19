<?php
declare(strict_types=1);
define('APP_ROOT',dirname(__DIR__));
$load=require APP_ROOT.'/app/config/env.php';$load(APP_ROOT.'/.env');
spl_autoload_register(static function(string$c):void{$p='App\\';if(!str_starts_with($c,$p))return;$x=explode('\\',substr($c,4));$x[0]=strtolower($x[0]);require APP_ROOT.'/app/'.implode('/',$x).'.php';});
$db=App\Core\Database::getConnection();$sql=file_get_contents(__DIR__.'/asaas_sandbox_homologation_migration.sql');if($sql===false)throw new RuntimeException('Migration nao encontrada.');$db->exec($sql);echo "Migration de homologacao Asaas aplicada em alugfacil_dev.\n";
