<?php
declare(strict_types=1);
require dirname(__DIR__).'/scripts/bootstrap.php';
require __DIR__.'/support/FinancialFixture.php';

use App\Core\Database;
use App\Models\Chacara;
use App\Services\CommercialConfigurationService;
use App\Services\PropertyPaymentConfigurationService;

$db = Database::getConnection();
$fixture = new FinancialFixture($db); // Refuses any database other than local alugfacil_dev.
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException($message);
    $checks++;
};
$service = new PropertyPaymentConfigurationService($db);
try {
    $db->beginTransaction();
    $db->exec('UPDATE configuracoes_parcelamento SET entrada_minima_bps=2000, entrada_maxima_bps=5000 WHERE id=1');
    $id = (new Chacara())->criarParaProprietario($fixture->owner, [
        'nome'=>'Property payment test', 'descricao'=>'', 'tipo_imovel'=>'chacara', 'valor_diaria'=>'100.00',
        'cidade'=>'Sao Paulo', 'estado'=>'SP', 'regiao'=>'', 'endereco'=>'Test', 'latitude'=>null, 'longitude'=>null,
        'checkin_hora_inicial'=>'14:00', 'checkin_hora_final'=>'18:00', 'checkout_hora_inicial'=>'08:00', 'checkout_hora_final'=>'11:00',
    ]);
    $service->saveOwner($id, $fixture->users['proprietario'], true, 2500, 0);
    $check($db->inTransaction(), 'Property save must not commit the outer transaction');
    $row = (new CommercialConfigurationService($db))->property($id);
    $check((int)$row['entrada_bps'] === 2500 && $row['aceita_parcelamento'], 'Entry preference persisted');
    $conditions = (new CommercialConfigurationService($db))->ownerConditions($fixture->owner);
    $condition = array_values(array_filter($conditions, fn($r)=>(int)$r['id']===$id))[0];
    $check((int)$condition['entrada_bps'] === 2500, 'Monthly page reads the same preference');
    $check($service->effective($id)['aceita_parcelamento'], 'Valid installment preference effective');
    $version = (int)$row['versao'];
    foreach ([[true,1900,$version,$fixture->users['proprietario']], [true,5100,$version,$fixture->users['proprietario']], [true,2500,-1,$fixture->users['proprietario']], [true,2500,$version,$fixture->users['cliente']]] as [$enabled,$bps,$v,$user]) {
        $failed = false;
        try { $service->saveOwner($id,$user,$enabled,$bps,$v); } catch (RuntimeException) { $failed = true; }
        $check($failed, 'Invalid limit, stale version or unauthorized owner rejected');
        $check((new CommercialConfigurationService($db))->property($id) === $row, 'Rejected save leaves preference unchanged');
    }
    $service->saveOwner($id,$fixture->users['proprietario'],false,null,$version);
    $check(!$service->effective($id)['aceita_parcelamento'], 'Integral-only preference supported');
    $db->rollBack();
    $q=$db->prepare('SELECT count(*) FROM chacaras WHERE id=:id');$q->execute(['id'=>$id]);
    $check((int)$q->fetchColumn()===0, 'Rollback removes property as well as preference');
    $q=$db->prepare('SELECT count(*) FROM configuracoes_comerciais_imoveis WHERE chacara_id=:id');$q->execute(['id'=>$id]);
    $check((int)$q->fetchColumn()===0, 'No orphan preference after rollback');
    $failed=false;
    try { $service->saveOwner($fixture->property,$fixture->users['cliente'],false,null,1); } catch (RuntimeException) { $failed=true; }
    $check($failed && !$db->inTransaction(), 'Standalone save rolls back its own transaction');
    echo "PASS: {$checks} owner payment integration checks\n";
} finally { $fixture->close(); }
