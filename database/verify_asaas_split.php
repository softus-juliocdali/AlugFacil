<?php
declare(strict_types=1);
require dirname(__DIR__).'/scripts/bootstrap.php';
$db=App\Core\Database::getConnection();if($db->query('SELECT current_database()')->fetchColumn()!=='alugfacil_dev')throw new RuntimeException('Banco nao permitido.');
$checks=['tabelas'=>"SELECT count(*)=2 FROM information_schema.tables WHERE table_schema='public' AND table_name IN ('asaas_splits','historico_asaas_splits')",'snapshot'=>"SELECT count(*)=7 FROM information_schema.columns WHERE table_name='reservas' AND column_name LIKE 'split_%'",'sem_segredos'=>"SELECT NOT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_name IN ('asaas_splits','historico_asaas_splits') AND column_name ILIKE ANY(ARRAY['%api_key%','%token%','%senha%','%cartao%']))"];
foreach($checks as$n=>$sql){$ok=(bool)$db->query($sql)->fetchColumn();echo($ok?'[OK] ':'[FALHA] ').$n.PHP_EOL;if(!$ok)exit(1);}echo "Verificacao de split aprovada.\n";
