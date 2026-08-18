<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit(1);}
require dirname(__DIR__).'/scripts/bootstrap.php';
use App\Core\Database;
$db=Database::getConnection();
if(config('app_env')==='development'&&$db->query('SELECT current_database()')->fetchColumn()!=='alugfacil_dev'){fwrite(STDERR,"Banco local nao permitido.\n");exit(2);}
$sql=(string)file_get_contents(__DIR__.'/mensalidade_anuncio_migration.sql');
$db->exec($sql);
echo "Migration de mensalidade do anuncio aplicada.\n";
