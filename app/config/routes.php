<?php

declare(strict_types=1);

use App\Controllers\AdminController;
use App\Controllers\AdminAffiliateController;
use App\Controllers\AffiliateAuthController;
use App\Controllers\AffiliateController;
use App\Controllers\AsaasWebhookAdminController;
use App\Controllers\AuthController;
use App\Controllers\ChacaraController;
use App\Controllers\HomeController;
use App\Controllers\MensalidadeAnuncioController;
use App\Controllers\FinanceConfigController;
use App\Controllers\FinancialOnboardingController;
use App\Controllers\OwnerAvailabilityController;
use App\Controllers\OwnerBillingController;
use App\Controllers\OwnerChacaraController;
use App\Controllers\PanelController;
use App\Controllers\ReservaController;
use App\Controllers\ReservaLifecycleController;
use App\Core\Database;
use App\Core\Router;

return static function (Router $router, array $config): void {
    $router->get('/', [HomeController::class, 'index']);
    $router->get('/chacara/{id}', [ChacaraController::class, 'show']);
    $router->get('/chacara/{id}/reservar', [ChacaraController::class, 'reserve']);
    $router->post('/chacara/{id}/favorito', [ChacaraController::class, 'favorite']);
    $router->post('/chacara/{id}/avaliar', [ChacaraController::class, 'review']);
    $router->get('/reserva/criar/{chacara_id}', [ReservaController::class, 'create']);
    $router->post('/reserva/criar/{chacara_id}', [ReservaController::class, 'store']);
    $router->get('/reserva/confirmacao/{id}', [ReservaController::class, 'confirmation']);
    $router->post('/reserva/{id}/gerar-pagamento', [ReservaController::class, 'retryPayment']);

    $router->get('/login', [AuthController::class, 'showLogin']);
    $router->post('/login', [AuthController::class, 'login']);
    $router->get('/cadastro', [AuthController::class, 'showClientRegistration']);
    $router->post('/cadastro', [AuthController::class, 'registerClient']);
    $router->get('/cadastro-proprietario', [AuthController::class, 'showOwnerRegistration']);
    $router->post('/cadastro-proprietario', [AuthController::class, 'registerOwner']);
    $router->get('/esqueceu-senha', [AuthController::class, 'forgotPasswordForm']);
    $router->post('/esqueceu-senha', [AuthController::class, 'forgotPassword']);
    $router->get('/redefinir-senha', [AuthController::class, 'resetPasswordForm']);
    $router->post('/redefinir-senha', [AuthController::class, 'resetPassword']);
    $router->get('/logout', [AuthController::class, 'logout']);

    $router->get('/afiliado/login', [AffiliateAuthController::class, 'showLogin']);
    $router->post('/afiliado/login', [AffiliateAuthController::class, 'login']);
    $router->post('/afiliado/logout', [AffiliateAuthController::class, 'logout']);
    $router->get('/afiliado', [AffiliateController::class, 'index']);
    $router->get('/afiliado/indicados', [AffiliateController::class, 'referred']);
    $router->get('/afiliado/indicados/{id}', [AffiliateController::class, 'referredDetail']);
    $router->get('/afiliado/comissoes', [AffiliateController::class, 'commissions']);
    $router->get('/afiliado/perfil', [AffiliateController::class, 'profile']);
    $router->post('/afiliado/perfil', [AffiliateController::class, 'updateProfile']);

    $router->get('/cliente', [PanelController::class, 'cliente']);
    $router->get('/cliente/favoritos', [PanelController::class, 'favoritosCliente']);
    $router->get('/cliente/historico', [PanelController::class, 'historicoCliente']);
    $router->get('/cliente/reserva/{id}', [PanelController::class, 'reservaCliente']);
    $router->post('/cliente/reserva/{id}/cancelar', [ReservaLifecycleController::class, 'cancelarCliente']);
    $router->get('/cliente/meus-dados', [PanelController::class, 'meusDadosCliente']);
    $router->post('/cliente/meus-dados', [PanelController::class, 'atualizarMeusDadosCliente']);

    $router->get('/proprietario', [PanelController::class, 'proprietario']);
    $router->get('/proprietario/dashboard', [PanelController::class, 'proprietario']);
    $router->get('/proprietario/status', [PanelController::class, 'statusProprietario']);
    $router->get('/proprietario/dados-cadastrais', [PanelController::class, 'dadosCadastraisProprietario']);
    $router->post('/proprietario/dados-cadastrais', [PanelController::class, 'atualizarDadosCadastraisProprietario']);
    $router->get('/proprietario/chacaras', [OwnerChacaraController::class, 'index']);
    $router->get('/proprietario/chacaras/criar', [OwnerChacaraController::class, 'create']);
    $router->post('/proprietario/chacaras/criar', [OwnerChacaraController::class, 'store']);
    $router->get('/proprietario/chacaras/editar/{id}', [OwnerChacaraController::class, 'edit']);
    $router->post('/proprietario/chacaras/editar/{id}', [OwnerChacaraController::class, 'update']);
    $router->post('/proprietario/chacaras/status/{id}', [OwnerChacaraController::class, 'updateStatus']);
    $router->get('/proprietario/chacaras/excluir/{id}', [OwnerChacaraController::class, 'delete']);
    $router->post('/proprietario/chacaras/excluir/{id}', [OwnerChacaraController::class, 'destroy']);
    $router->get('/proprietario/chacaras/fotos/{id}', [OwnerChacaraController::class, 'photos']);
    $router->post('/proprietario/chacaras/fotos/{id}', [OwnerChacaraController::class, 'updatePhotos']);
    $router->get('/proprietario/disponibilidade', [OwnerAvailabilityController::class, 'index']);
    $router->get('/proprietario/disponibilidade/{chacara_id}', [OwnerAvailabilityController::class, 'show']);
    $router->post('/proprietario/disponibilidade/salvar', [OwnerAvailabilityController::class, 'save']);
    $router->get('/proprietario/faturamento', [OwnerBillingController::class, 'index']);
    $router->get('/proprietario/mensalidades', [MensalidadeAnuncioController::class, 'owner']);
    $router->get('/proprietario/mensalidades/{id}/pagar', [MensalidadeAnuncioController::class, 'pay']);
    $router->get('/proprietario/recebimentos', [FinancialOnboardingController::class, 'owner']);
    $router->post('/proprietario/recebimentos/dados', [FinancialOnboardingController::class, 'save']);
    $router->post('/proprietario/recebimentos/aceite', [FinancialOnboardingController::class, 'accept']);
    $router->get('/proprietario/reservas/{id}', [ReservaLifecycleController::class, 'proprietarioDetalhe']);
    $router->post('/proprietario/reservas/{id}/iniciar', [ReservaLifecycleController::class, 'iniciarProprietario']);
    $router->post('/proprietario/reservas/{id}/finalizar', [ReservaLifecycleController::class, 'finalizarProprietario']);

    $router->get('/admin', [PanelController::class, 'admin']);
    $router->get('/admin/dashboard', [PanelController::class, 'admin']);
    $router->get('/admin/minha-conta', [AdminController::class, 'minhaConta']);
    $router->post('/admin/minha-conta', [AdminController::class, 'atualizarMinhaConta']);
    $router->get('/admin/afiliados', [AdminAffiliateController::class, 'index']);
    $router->get('/admin/afiliados/criar', [AdminAffiliateController::class, 'create']);
    $router->post('/admin/afiliados/criar', [AdminAffiliateController::class, 'store']);
    $router->get('/admin/afiliados/configuracao', [AdminAffiliateController::class, 'commission']);
    $router->post('/admin/afiliados/configuracao', [AdminAffiliateController::class, 'updateCommission']);
    $router->get('/admin/afiliados/{id}/financeiro', [AdminAffiliateController::class, 'finance']);
    $router->post('/admin/afiliados/{id}/pagamentos', [AdminAffiliateController::class, 'registerPayment']);
    $router->get('/admin/afiliados/{id}/editar', [AdminAffiliateController::class, 'edit']);
    $router->post('/admin/afiliados/{id}/editar', [AdminAffiliateController::class, 'update']);
    $router->post('/admin/afiliados/{id}/status', [AdminAffiliateController::class, 'status']);
    $router->get('/admin/administradores', [AdminController::class, 'administradores']);
    $router->get('/admin/administradores/criar', [AdminController::class, 'criarAdministrador']);
    $router->post('/admin/administradores/criar', [AdminController::class, 'salvarAdministrador']);
    $router->get('/admin/administradores/{id}/editar', [AdminController::class, 'editarAdministrador']);
    $router->post('/admin/administradores/{id}/editar', [AdminController::class, 'atualizarAdministrador']);
    $router->get('/admin/proprietarios', [AdminController::class, 'proprietarios']);
    $router->get('/admin/proprietarios/{id}', [AdminController::class, 'proprietarioDetalhes']);
    $router->post('/admin/proprietarios/{id}/status', [AdminController::class, 'atualizarStatusProprietario']);
    $router->get('/admin/chacaras', [AdminController::class, 'chacaras']);
    $router->get('/admin/chacaras/{id}', [AdminController::class, 'chacaraDetalhes']);
    $router->post('/admin/chacaras/{id}/status', [AdminController::class, 'atualizarStatusChacara']);
    $router->get('/admin/mensalidades', [MensalidadeAnuncioController::class, 'admin']);
    $router->post('/admin/mensalidades/configuracao', [MensalidadeAnuncioController::class, 'adminConfigUpdate']);
    $router->get('/admin/usuarios', [AdminController::class, 'usuarios']);
    $router->get('/admin/usuarios/{id}', [AdminController::class, 'usuarioDetalhes']);
    $router->post('/admin/usuarios/{id}/status', [AdminController::class, 'atualizarStatusUsuario']);
    $router->get('/admin/reservas', [ReservaLifecycleController::class, 'adminIndex']);
    $router->get('/admin/reservas/{id}', [ReservaLifecycleController::class, 'adminDetalhe']);
    $router->post('/admin/reservas/{id}/transicao', [ReservaLifecycleController::class, 'adminTransicao']);
    $router->get('/admin/asaas-eventos', [AsaasWebhookAdminController::class, 'index']);
    $router->get('/admin/asaas-eventos/{id}', [AsaasWebhookAdminController::class, 'show']);
    $router->post('/admin/asaas-eventos/{id}/acao', [AsaasWebhookAdminController::class, 'action']);
    $router->get('/admin/configuracoes-financeiras', [FinanceConfigController::class, 'index']);
    $router->post('/admin/configuracoes-financeiras', [FinanceConfigController::class, 'store']);
    $router->get('/admin/onboarding-financeiro', [FinancialOnboardingController::class, 'admin']);
    $router->get('/admin/onboarding-financeiro/{id}', [FinancialOnboardingController::class, 'details']);
    $router->post('/admin/onboarding-financeiro/{id}/acao', [FinancialOnboardingController::class, 'action']);

    if ($config['app_env'] !== 'development') {
        return;
    }

    $router->get('/teste-conexao', static function (): void {
        header('Content-Type: text/plain; charset=UTF-8');

        try {
            $statement = Database::getConnection()->prepare('SELECT id FROM usuarios LIMIT 1');
            $statement->execute();

            echo 'Conexao com PostgreSQL realizada com sucesso.';
        } catch (Throwable $exception) {
            http_response_code(500);
            echo 'Falha ao testar a conexao com PostgreSQL: ' . $exception->getMessage();
        }
    });
};
