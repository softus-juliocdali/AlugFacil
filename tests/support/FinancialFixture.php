<?php
declare(strict_types=1);
use App\Services\CheckoutQuoteService;use App\Services\PayerDocumentService;
final class FinancialFixture
{
 public array $users=[];public int $owner=0,$property=0;public string $tag;
 public function __construct(public PDO $db)
 {
  if(getenv('DB_NAME')!=='alugfacil_dev'||!in_array(getenv('DB_HOST'),['localhost','127.0.0.1','::1'],true))throw new RuntimeException('Somente banco local.');
  $this->tag='fixture_'.bin2hex(random_bytes(5));
  foreach(['proprietario','cliente','admin'] as $role){$q=$db->prepare("INSERT INTO usuarios(nome,email,senha_hash,tipo_usuario,status) VALUES('Financial fixture',:e,'unused',:r,'ativo') RETURNING id");$q->execute(['e'=>$role.$this->tag.'@example.test','r'=>$role]);$this->users[$role]=(int)$q->fetchColumn();}
  $q=$db->prepare("INSERT INTO proprietarios(usuario_id,nome,email,status) VALUES(:u,'Financial fixture',:e,'ativo') RETURNING id");$q->execute(['u'=>$this->users['proprietario'],'e'=>$this->tag.'@example.test']);$this->owner=(int)$q->fetchColumn();
  $db->prepare("INSERT INTO proprietario_cadastro(proprietario_id,tipo_pessoa,cpf_cnpj,nome_razao_social,celular,email_financeiro,cep,endereco,numero,bairro,cidade,estado,renda_faturamento_mensal_centavos,dados_completos,situacao) VALUES(:p,'PF',:d,'Financial fixture','11999999999',:e,'01001000','Teste','1','Centro','Sao Paulo','SP',100000,TRUE,'validado')")->execute(['p'=>$this->owner,'d'=>self::cpf(),'e'=>$this->tag.'@example.test']);
  $db->prepare("INSERT INTO asaas_subcontas(proprietario_id,ambiente,email_cadastro,status_local,status_operacional_asaas,asaas_wallet_id,request_hash,solicitacao_id) VALUES(:p,'sandbox',:e,'aprovada','ENABLED',:w,:h,:s)")->execute(['p'=>$this->owner,'e'=>$this->tag.'@example.test','w'=>'wallet_'.$this->tag,'h'=>hash('sha256',$this->tag),'s'=>$this->tag]);
  $db->prepare("INSERT INTO aceites_financeiros_proprietarios(proprietario_id,tipo_aceite,versao_documento,texto_hash) VALUES(:p,'ONBOARDING_ASAAS_NON_BAAS','fixture',:h)")->execute(['p'=>$this->owner,'h'=>hash('sha256',$this->tag)]);
  $q=$db->prepare("INSERT INTO chacaras(proprietario_id,nome,cidade,endereco,valor_diaria,status,status_aprovacao,status_operacional,checkin_hora_inicial,checkin_hora_final) VALUES(:p,'Financial fixture','Sao Paulo','Teste',100,'disponivel','aprovada','disponivel','00:00','01:00') RETURNING id");$q->execute(['p'=>$this->owner]);$this->property=(int)$q->fetchColumn();
  $db->prepare('INSERT INTO configuracoes_comerciais_imoveis(chacara_id,sem_mensalidade,comissao_bps) VALUES(:c,TRUE,1000)')->execute(['c'=>$this->property]);
  (new PayerDocumentService($db))->save($this->users['cliente'],'PF',self::cpf());
 }
 public static function cpf():string{$v=(string)random_int(100000000,999999999);for($n=9;$n<11;$n++){$sum=0;for($i=0;$i<$n;$i++)$sum+=(int)$v[$i]*($n+1-$i);$v.=(string)((10*$sum%11)%10);}return $v;}
 public function quote(int $offset=90,string $mode='integral',string $method='PIX'):array{$s=(new DateTimeImmutable('today'))->modify('+'.$offset.' days');return (new CheckoutQuoteService($this->db))->create($this->users['cliente'],$this->property,$s->format('Y-m-d'),$s->modify('+2 days')->format('Y-m-d'),$mode,$method);}
 public function consume(array $q):int{return (int)(new CheckoutQuoteService($this->db))->consume($q['id'],$this->users['cliente'],$this->property,$q['data_inicio'],$q['data_fim'],true)['id'];}
 public function shortQuote(int $offset):array
 {$q=$this->quote($offset);$this->db->prepare('UPDATE cotacoes_reserva SET cancelada_em=clock_timestamp() WHERE id=:id')->execute(['id'=>$q['id']]);$s=$this->db->prepare("WITH t AS (SELECT clock_timestamp() AS now) INSERT INTO cotacoes_reserva(id,usuario_id,chacara_id,configuracao_financeira_id,versao_configuracao,data_inicio,data_fim,forma_pagamento,quantidade_parcelas,detalhes,criado_em,expira_em,versao_snapshot,modalidade) SELECT gen_random_uuid(),q.usuario_id,q.chacara_id,q.configuracao_financeira_id,q.versao_configuracao,q.data_inicio,q.data_fim,q.forma_pagamento,q.quantidade_parcelas,q.detalhes,t.now-INTERVAL '895 seconds',t.now+INTERVAL '5 seconds',2,q.modalidade FROM cotacoes_reserva q CROSS JOIN t WHERE q.id=:id RETURNING *");$s->execute(['id'=>$q['id']]);return $s->fetch();}
 public function close():void
 {
  $db=$this->db;if($db->inTransaction())$db->rollBack();
  $db->prepare('DELETE FROM itens_estorno_asaas WHERE asaas_payment_id IN (SELECT asaas_payment_id FROM obrigacoes_reserva WHERE reserva_id IN (SELECT id FROM reservas WHERE chacara_id=:c))')->execute(['c'=>$this->property]);
  $db->prepare('DELETE FROM repasses_obrigacoes WHERE obrigacao_id IN (SELECT id FROM obrigacoes_reserva WHERE reserva_id IN (SELECT id FROM reservas WHERE chacara_id=:c))')->execute(['c'=>$this->property]);
  foreach(['historico_inadimplencia_reserva','cancelamentos_inadimplencia_reserva','cronogramas_reserva','autorizacoes_pagamento_reserva','cancelamentos_administrativos_reserva','tarefas_financeiras_reserva','historico_reembolsos_reservas','reembolsos_reservas','historico_repasses_reservas','repasses_reservas','historico_status_reservas','pagamentos','obrigacoes_reserva'] as $table)$db->prepare('DELETE FROM '.$table.' WHERE reserva_id IN (SELECT id FROM reservas WHERE chacara_id=:c)')->execute(['c'=>$this->property]);
  foreach(['reservas','cotacoes_reserva','disponibilidades','configuracoes_comerciais_imoveis'] as $table)$db->prepare('DELETE FROM '.$table.' WHERE chacara_id=:c')->execute(['c'=>$this->property]);$db->prepare('DELETE FROM chacaras WHERE id=:c')->execute(['c'=>$this->property]);
  foreach(['onboarding_fila','asaas_subcontas','aceites_financeiros_proprietarios','proprietario_cadastro'] as $table)$db->prepare('DELETE FROM '.$table.' WHERE proprietario_id=:p')->execute(['p'=>$this->owner]);$db->prepare('DELETE FROM proprietarios WHERE id=:p')->execute(['p'=>$this->owner]);
  foreach($this->users as $u){foreach(['asaas_customers','historico_documentos_pagador','usuario_documentos'] as $table)$db->prepare('DELETE FROM '.$table.' WHERE usuario_id=:u')->execute(['u'=>$u]);$db->prepare('DELETE FROM usuarios WHERE id=:u')->execute(['u'=>$u]);}
  $db->prepare('DELETE FROM tentativas_operacoes_financeiras WHERE operacao_id IN (SELECT id FROM operacoes_financeiras WHERE conta_gateway=:s)')->execute(['s'=>$this->tag]);$db->prepare('DELETE FROM operacoes_financeiras WHERE conta_gateway=:s')->execute(['s'=>$this->tag]);
 }
}
