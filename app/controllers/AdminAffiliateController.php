<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\Affiliate;
use App\Models\AffiliateCommissionConfig;
use App\Services\AffiliateCommissionService;
use App\Services\PrecificacaoReservaService;
use App\Validators\AffiliateValidator;
use RuntimeException;
use Throwable;

final class AdminAffiliateController extends Controller
{
    public function index(): void
    {
        Auth::requireRole('admin');
        $filters = [
            'nome' => trim((string) ($_GET['nome'] ?? '')),
            'codigo' => strtoupper(trim((string) ($_GET['codigo'] ?? ''))),
            'cpf_cnpj' => AffiliateValidator::normalizeDocument((string) ($_GET['cpf_cnpj'] ?? '')),
            'email' => strtolower(trim((string) ($_GET['email'] ?? ''))),
            'status' => trim((string) ($_GET['status'] ?? '')),
        ];
        $affiliates=(new Affiliate())->listAdministrative($filters);
        $finance=new AffiliateCommissionService();
        foreach($affiliates as&$affiliate)$affiliate['financeiro']=$finance->summary((int)$affiliate['id']);unset($affiliate);
        $this->view('admin/afiliados/index', [
            'title' => 'Afiliados', 'panelRole' => 'admin', 'filters' => $filters,
            'affiliates' => $affiliates,
        ], 'panel');
    }

    public function create(): void
    {
        Auth::requireRole('admin');
        $this->form(null, '/admin/afiliados/criar', 'criar');
    }

    public function store(): void
    {
        Auth::requireRole('admin');
        verify_csrf();
        $data = $this->data();
        $this->keepOld($data);
        $error = $this->validate($data, true);
        if ($error !== null) {
            flash('error', $error);
            $this->redirect('/admin/afiliados/criar');
        }
        $model = new Affiliate();
        if ($model->identityExists($data['email'], $data['cpf_cnpj'])) {
            flash('error', 'Já existe um afiliado com este e-mail ou CPF/CNPJ.');
            $this->redirect('/admin/afiliados/criar');
        }
        try {
            $created = $model->create($data);
        } catch (Throwable) {
            flash('error', 'Não foi possível cadastrar o afiliado. Verifique os dados informados.');
            $this->redirect('/admin/afiliados/criar');
        }
        clear_old();
        flash('success', 'Afiliado ' . $created['codigo'] . ' cadastrado com sucesso.');
        $this->redirect('/admin/afiliados');
    }

    public function edit(string $id): void
    {
        Auth::requireRole('admin');
        $affiliateId = $this->validId($id);
        $affiliate = (new Affiliate())->findById($affiliateId);
        if ($affiliate === null) {
            $this->notFound();
        }
        $this->form($affiliate, '/admin/afiliados/' . $affiliateId . '/editar', 'editar');
    }

    public function update(string $id): void
    {
        Auth::requireRole('admin');
        verify_csrf();
        $affiliateId = $this->validId($id);
        $model = new Affiliate();
        if ($model->findById($affiliateId) === null) {
            $this->notFound();
        }
        $data = $this->data();
        $this->keepOld($data);
        $error = $this->validate($data, false);
        if ($error !== null) {
            flash('error', $error);
            $this->redirect('/admin/afiliados/' . $affiliateId . '/editar');
        }
        if ($model->identityExists($data['email'], $data['cpf_cnpj'], $affiliateId)) {
            flash('error', 'Já existe outro afiliado com este e-mail ou CPF/CNPJ.');
            $this->redirect('/admin/afiliados/' . $affiliateId . '/editar');
        }
        try {
            $model->update($affiliateId, $data);
        } catch (Throwable) {
            flash('error', 'Não foi possível atualizar o afiliado. Verifique os dados informados.');
            $this->redirect('/admin/afiliados/' . $affiliateId . '/editar');
        }
        clear_old();
        flash('success', 'Afiliado atualizado com sucesso.');
        $this->redirect('/admin/afiliados');
    }

    public function status(string $id): void
    {
        Auth::requireRole('admin');
        verify_csrf();
        $affiliateId = $this->validId($id);
        $status = (string) ($_POST['status'] ?? '');
        if (!in_array($status, ['ativo', 'bloqueado'], true)) {
            flash('error', 'Status de afiliado inválido.');
            $this->redirect('/admin/afiliados');
        }
        try {
            $updated = (new Affiliate())->updateStatus($affiliateId, $status);
            flash($updated ? 'success' : 'error', $updated
                ? ($status === 'ativo' ? 'Afiliado reativado.' : 'Afiliado bloqueado.')
                : 'Afiliado não encontrado ou já estava neste status.');
        } catch (Throwable) {
            flash('error', 'Não foi possível alterar o status do afiliado.');
        }
        $this->redirect('/admin/afiliados');
    }

    public function commission(): void
    {
        Auth::requireRole('admin');
        $this->view('admin/afiliados/configuracao', [
            'title' => 'Comissão dos afiliados', 'panelRole' => 'admin',
            'affiliates' => (new Affiliate())->listAdministrative([]),
        ], 'panel');
    }

    public function updateCommission(): void
    {
        Auth::requireRole('admin');
        verify_csrf();
        $value = trim((string) ($_POST['percentual'] ?? ''));
        $basisPoints = AffiliateValidator::commissionToBasisPoints($value);
        if ($basisPoints === null) {
            set_old(['percentual' => $value]);
            flash('error', 'Informe um percentual entre 0 e 100%, com até duas casas decimais.');
            $this->redirect('/admin/afiliados/configuracao');
        }
        try {
            (new \App\Services\CommercialConfigurationService())->configureAffiliate((int)($_POST['afiliado_id']??0),$basisPoints,(int)Auth::user()['id'],(string)($_POST['motivo']??''));
        } catch (Throwable) {
            flash('error', 'Não foi possível atualizar o percentual de comissão.');
            $this->redirect('/admin/afiliados/configuracao');
        }
        clear_old();
        flash('success', 'Percentual individual atualizado. Mensalidades já emitidas foram preservadas.');
        $this->redirect('/admin/afiliados/configuracao');
    }

    public function finance(string $id): void
    {
        Auth::requireRole('admin');$affiliateId=$this->validId($id);$affiliate=(new Affiliate())->findById($affiliateId);if($affiliate===null)$this->notFound();
        $service=new AffiliateCommissionService();
        $this->view('admin/afiliados/financeiro',['title'=>'Financeiro do afiliado','panelRole'=>'admin','affiliate'=>$affiliate,'summary'=>$service->summary($affiliateId),'commissions'=>$service->commissions($affiliateId),'payments'=>$service->payments($affiliateId),'allocations'=>$service->allocations($affiliateId),'adjustments'=>$service->adjustments($affiliateId)],'panel');
    }

    public function registerPayment(string $id): void
    {
        Auth::requireRole('admin');verify_csrf();$affiliateId=$this->validId($id);
        $value=trim((string)($_POST['valor']??''));$date=trim((string)($_POST['data_pagamento']??''));$reference=trim((string)($_POST['referencia']??''));$observation=trim((string)($_POST['observacao']??''));
        set_old(['valor'=>$value,'data_pagamento'=>$date,'referencia'=>$reference,'observacao'=>$observation]);
        try{
            if(mb_strlen($reference)>180||mb_strlen($observation)>1000)throw new RuntimeException('Referência ou observação excede o limite permitido.');
            $cents=PrecificacaoReservaService::decimalParaCentavos(str_replace(',','.',$value));
            (new AffiliateCommissionService())->registerManualPayment($affiliateId,$cents,$date,$reference,$observation,(int)Auth::user()['id']);
            clear_old();flash('success','Pagamento ao afiliado registrado e alocado pelo critério FIFO.');
        }catch(Throwable$exception){flash('error',\App\Services\FinancialErrorMessage::publicMessage($exception,'Não foi possível registrar o pagamento ao afiliado.'));}
        $this->redirect('/admin/afiliados/'.$affiliateId.'/financeiro');
    }

    private function form(?array $affiliate, string $action, string $mode): void
    {
        $this->view('admin/afiliados/form', [
            'title' => $mode === 'criar' ? 'Novo afiliado' : 'Editar afiliado',
            'panelRole' => 'admin', 'affiliate' => $affiliate, 'action' => url($action), 'mode' => $mode,
            'pixTypes' => AffiliateValidator::PIX_TYPES,
        ], 'panel');
    }

    private function data(): array
    {
        return [
            'nome' => trim((string) ($_POST['nome'] ?? '')),
            'cpf_cnpj' => AffiliateValidator::normalizeDocument((string) ($_POST['cpf_cnpj'] ?? '')),
            'telefone' => trim((string) ($_POST['telefone'] ?? '')),
            'email' => strtolower(trim((string) ($_POST['email'] ?? ''))),
            'senha' => (string) ($_POST['senha'] ?? ''),
            'senha_confirmacao' => (string) ($_POST['senha_confirmacao'] ?? ''),
            'chave_pix' => trim((string) ($_POST['chave_pix'] ?? '')),
            'tipo_chave_pix' => trim((string) ($_POST['tipo_chave_pix'] ?? '')),
            'banco' => trim((string) ($_POST['banco'] ?? '')),
            'observacoes' => trim((string) ($_POST['observacoes'] ?? '')),
            'status' => trim((string) ($_POST['status'] ?? 'ativo')),
        ];
    }

    private function validate(array $data, bool $passwordRequired): ?string
    {
        if (mb_strlen($data['nome']) < 2 || mb_strlen($data['nome']) > 150) return 'Informe um nome válido.';
        if (!AffiliateValidator::documentIsValid($data['cpf_cnpj'])) return 'Informe um CPF ou CNPJ válido.';
        if (mb_strlen(preg_replace('/\D+/', '', $data['telefone']) ?? '') < 10) return 'Informe um telefone válido.';
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) return 'Informe um e-mail válido.';
        if ($passwordRequired || $data['senha'] !== '' || $data['senha_confirmacao'] !== '') {
            if (strlen($data['senha']) < 8) return 'A senha deve ter ao menos 8 caracteres.';
            if ($data['senha'] !== $data['senha_confirmacao']) return 'A confirmação da senha não confere.';
        }
        if (!in_array($data['tipo_chave_pix'], AffiliateValidator::PIX_TYPES, true)) return 'Selecione um tipo de chave PIX válido.';
        if (mb_strlen($data['chave_pix']) > 150 || !AffiliateValidator::pixKeyIsValid($data['tipo_chave_pix'], $data['chave_pix'])) return 'Informe uma chave PIX válida para o tipo selecionado.';
        if (mb_strlen($data['banco']) > 120 || mb_strlen($data['observacoes']) > 2000) return 'Banco ou observações excedem o limite permitido.';
        if (!in_array($data['status'], ['ativo', 'bloqueado'], true)) return 'Status de afiliado inválido.';
        return null;
    }

    private function keepOld(array $data): void
    {
        unset($data['senha'], $data['senha_confirmacao']);
        set_old($data);
    }

    private function validId(string $id): int
    {
        $value = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($value === false) $this->notFound();
        return (int) $value;
    }

    private function notFound(): never
    {
        http_response_code(404);
        $this->view('public/404', ['title' => 'Afiliado não encontrado']);
        exit;
    }
}
