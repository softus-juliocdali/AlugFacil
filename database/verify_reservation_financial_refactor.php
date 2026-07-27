<?php
declare(strict_types=1);
require dirname(__DIR__).'/scripts/bootstrap.php';
use App\Core\Database;
$db=Database::getConnection();
$required=['reembolsos_reservas','historico_reembolsos_reservas','repasses_reservas','historico_repasses_reservas'];
foreach($required as$table){$s=$db->prepare("SELECT to_regclass('public.'||:t) IS NOT NULL");$s->execute(['t'=>$table]);if(!$s->fetchColumn())throw new RuntimeException("Tabela ausente: $table");}
$types=$db->query("SELECT column_name,data_type FROM information_schema.columns WHERE table_name='chacaras' AND column_name LIKE '%hora_%' ORDER BY column_name")->fetchAll();
if(count($types)!==4||array_filter($types,fn($x)=>$x['data_type']!=='time without time zone'))throw new RuntimeException('Tipos TIME invalidos.');
echo "Verificacao financeira 07-R aprovada.\n";
