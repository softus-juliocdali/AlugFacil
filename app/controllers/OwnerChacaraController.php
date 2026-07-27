<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Helpers\GoogleMapsHelper;
use App\Models\Chacara;
use RuntimeException;
use Throwable;

final class OwnerChacaraController extends Controller
{
    private const STATUSES = ['disponivel', 'indisponivel'];
    private const TIPOS_IMOVEL = ['chacara', 'sitio', 'area_lazer'];
    private const MAX_UPLOAD_BYTES = 5242880;
    private const UPLOAD_RELATIVE_DIR = 'uploads/chacaras';

    public function index(): void
    {
        $proprietarioId = $this->proprietarioId();
        $chacaras = (new Chacara())->listarPorProprietario($proprietarioId);

        $this->view('proprietario/chacaras/index', [
            'title' => 'Minhas chacaras',
            'panelRole' => 'proprietario',
            'chacaras' => $chacaras,
            'statuses' => self::STATUSES,
        ], 'panel');
    }

    public function create(): void
    {
        $this->proprietarioId();
        $googleMaps = new GoogleMapsHelper();

        $this->view('proprietario/chacaras/form', [
            'title' => 'Cadastrar chacara',
            'panelRole' => 'proprietario',
            'chacara' => null,
            'statuses' => self::STATUSES,
            'tiposImovel' => self::TIPOS_IMOVEL,
            'action' => url('/proprietario/chacaras/criar'),
            'googleMapsApiKey' => $googleMaps->apiKey(),
            'googleMapsConfigurado' => $googleMaps->configurado(),
        ], 'panel');
    }

    public function store(): void
    {
        $proprietarioId = $this->proprietarioId();
        verify_csrf();

        $dados = $this->validarDados('/proprietario/chacaras/criar');

        try {
            $id = (new Chacara())->criarParaProprietario($proprietarioId, $dados);
            clear_old();
            flash('success', 'Chacara cadastrada e enviada para aprovacao. Agora voce pode enviar fotos.');
            $this->redirect('/proprietario/chacaras/fotos/' . $id);
        } catch (Throwable) {
            flash('error', 'Nao foi possivel cadastrar a chacara agora.');
            $this->redirect('/proprietario/chacaras/criar');
        }
    }

    public function edit(string $id): void
    {
        $proprietarioId = $this->proprietarioId();
        $chacara = $this->buscarChacaraAutorizada($id, $proprietarioId);
        $googleMaps = new GoogleMapsHelper();

        $this->view('proprietario/chacaras/form', [
            'title' => 'Editar chacara',
            'panelRole' => 'proprietario',
            'chacara' => $chacara,
            'statuses' => self::STATUSES,
            'tiposImovel' => self::TIPOS_IMOVEL,
            'action' => url('/proprietario/chacaras/editar/' . (int) $chacara['id']),
            'googleMapsApiKey' => $googleMaps->apiKey(),
            'googleMapsConfigurado' => $googleMaps->configurado(),
        ], 'panel');
    }

    public function update(string $id): void
    {
        $proprietarioId = $this->proprietarioId();
        verify_csrf();
        $chacara = $this->buscarChacaraAutorizada($id, $proprietarioId);
        $redirect = '/proprietario/chacaras/editar/' . (int) $chacara['id'];
        $dados = $this->validarDados($redirect);

        try {
            (new Chacara())->atualizarDoProprietario((int) $chacara['id'], $proprietarioId, $dados);
            clear_old();
            flash('success', 'Chacara atualizada com sucesso.');
            $this->redirect('/proprietario/chacaras');
        } catch (Throwable) {
            flash('error', 'Nao foi possivel atualizar a chacara agora.');
            $this->redirect($redirect);
        }
    }

    public function updateStatus(string $id): void
    {
        $proprietarioId = $this->proprietarioId();
        verify_csrf();
        $chacara = $this->buscarChacaraAutorizada($id, $proprietarioId);
        $status = (string) ($_POST['status'] ?? '');

        if (!in_array($status, self::STATUSES, true)) {
            flash('error', 'Status operacional invalido.');
            $this->redirect('/proprietario/chacaras');
        }
        if ($chacara['status_aprovacao'] !== 'aprovada') {
            flash('error', 'A disponibilidade so pode ser alterada depois da aprovacao administrativa.');
            $this->redirect('/proprietario/chacaras');
        }

        $atualizado = (new Chacara())->atualizarStatusDoProprietario((int) $chacara['id'], $proprietarioId, $status);
        flash($atualizado ? 'success' : 'error', $atualizado ? 'Disponibilidade atualizada.' : 'Nao foi possivel atualizar a disponibilidade.');
        $this->redirect('/proprietario/chacaras');
    }

    public function delete(string $id): void
    {
        $proprietarioId = $this->proprietarioId();
        $chacara = $this->buscarChacaraAutorizada($id, $proprietarioId);

        $this->view('proprietario/chacaras/excluir', [
            'title' => 'Desativar chacara',
            'panelRole' => 'proprietario',
            'chacara' => $chacara,
        ], 'panel');
    }

    public function destroy(string $id): void
    {
        $proprietarioId = $this->proprietarioId();
        verify_csrf();
        $chacara = $this->buscarChacaraAutorizada($id, $proprietarioId);

        if ((new Chacara())->desativarDoProprietario((int) $chacara['id'], $proprietarioId)) {
            flash('success', 'Chacara desativada com sucesso.');
        } else {
            flash('error', 'Nao foi possivel desativar a chacara.');
        }

        $this->redirect('/proprietario/chacaras');
    }

    public function photos(string $id): void
    {
        $proprietarioId = $this->proprietarioId();
        $chacara = $this->buscarChacaraAutorizada($id, $proprietarioId);
        $model = new Chacara();
        $postMaxBytes = $this->bytesIni((string) ini_get('post_max_size'));
        $maxUploadBytes = $this->limiteArquivoEfetivo();

        $this->view('proprietario/chacaras/fotos', [
            'title' => 'Fotos da chacara',
            'panelRole' => 'proprietario',
            'chacara' => $chacara,
            'fotos' => $model->buscarFotosGerenciamento((int) $chacara['id']),
            'maxUploadBytes' => $maxUploadBytes,
            'postMaxBytes' => $postMaxBytes,
            'maxUploadLabel' => $this->formatarBytes($maxUploadBytes),
            'postMaxLabel' => $this->formatarBytes($postMaxBytes),
        ], 'panel');
    }

    public function updatePhotos(string $id): void
    {
        $proprietarioId = $this->proprietarioId();
        $chacara = $this->buscarChacaraAutorizada($id, $proprietarioId);
        $chacaraId = (int) $chacara['id'];

        if ($this->postExcedeuLimite()) {
            flash('error', 'O envio ultrapassou o limite do servidor. Envie menos fotos por vez ou arquivos menores.');
            $this->redirect('/proprietario/chacaras/fotos/' . $chacaraId);
        }

        verify_csrf();
        $acao = (string) ($_POST['acao'] ?? 'upload');
        $model = new Chacara();

        try {
            if ($acao === 'principal') {
                $fotoId = $this->validarId((string) ($_POST['foto_id'] ?? ''));
                $model->definirFotoPrincipal($chacaraId, $fotoId);
                flash('success', 'Foto principal atualizada.');
            } elseif ($acao === 'remover') {
                $fotoId = $this->validarId((string) ($_POST['foto_id'] ?? ''));
                $caminho = $model->removerFoto($chacaraId, $fotoId);
                $this->removerArquivoUpload($caminho);
                flash('success', 'Foto removida com sucesso.');
            } else {
                $total = $this->processarUploads($model, $chacaraId);
                flash($total > 0 ? 'success' : 'error', $total > 0 ? "{$total} foto(s) enviada(s)." : 'Selecione ao menos uma foto valida.');
            }
        } catch (RuntimeException $exception) {
            flash('error', $exception->getMessage());
        } catch (Throwable) {
            flash('error', 'Nao foi possivel atualizar as fotos agora.');
        }

        $this->redirect('/proprietario/chacaras/fotos/' . $chacaraId);
    }

    private function proprietarioId(): int
    {
        $proprietario = Auth::requireProprietarioOperacional();
        return (int) $proprietario['id'];
    }

    private function buscarChacaraAutorizada(string $id, int $proprietarioId): array
    {
        $chacaraId = $this->validarId($id);
        $chacara = (new Chacara())->buscarDoProprietario($chacaraId, $proprietarioId);

        if ($chacara === null) {
            http_response_code(404);
            $this->view('public/404', ['title' => 'Chacara nao encontrada']);
            exit;
        }

        return $chacara;
    }

    private function validarDados(string $redirect): array
    {
        $dados = [
            'nome' => trim((string) ($_POST['nome'] ?? '')),
            'descricao' => trim((string) ($_POST['descricao'] ?? '')),
            'tipo_imovel' => trim((string) ($_POST['tipo_imovel'] ?? 'chacara')),
            'valor_diaria' => str_replace(',', '.', trim((string) ($_POST['valor_diaria'] ?? ''))),
            'cidade' => trim((string) ($_POST['cidade'] ?? '')),
            'regiao' => trim((string) ($_POST['regiao'] ?? '')),
            'endereco' => trim((string) ($_POST['endereco'] ?? '')),
            'latitude' => trim((string) ($_POST['latitude'] ?? '')),
            'longitude' => trim((string) ($_POST['longitude'] ?? '')),
            'checkin_hora_inicial' => trim((string) ($_POST['checkin_hora_inicial'] ?? '')),
            'checkin_hora_final' => trim((string) ($_POST['checkin_hora_final'] ?? '')),
            'checkout_hora_inicial' => trim((string) ($_POST['checkout_hora_inicial'] ?? '')),
            'checkout_hora_final' => trim((string) ($_POST['checkout_hora_final'] ?? '')),
        ];

        set_old($dados);

        if (mb_strlen($dados['nome']) < 3) {
            flash('error', 'Informe o nome da chacara com pelo menos 3 caracteres.');
            $this->redirect($redirect);
        }

        if (!is_numeric($dados['valor_diaria']) || (float) $dados['valor_diaria'] <= 0) {
            flash('error', 'Informe um valor de diaria valido.');
            $this->redirect($redirect);
        }

        if (!in_array($dados['tipo_imovel'], self::TIPOS_IMOVEL, true)) {
            flash('error', 'Tipo de imovel invalido.');
            $this->redirect($redirect);
        }

        if ($dados['cidade'] === '' || $dados['endereco'] === '') {
            flash('error', 'Informe cidade e endereco.');
            $this->redirect($redirect);
        }
        foreach(['checkin_hora_inicial','checkin_hora_final','checkout_hora_inicial','checkout_hora_final'] as $campo)if(!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$dados[$campo])){flash('error','Informe todos os horarios da hospedagem.');$this->redirect($redirect);}
        if($dados['checkin_hora_inicial'] >= $dados['checkin_hora_final']||$dados['checkout_hora_inicial'] >= $dados['checkout_hora_final']){flash('error','O horario inicial deve ser anterior ao horario final.');$this->redirect($redirect);}

        $latitude = $this->normalizarCoordenada($dados['latitude'], -90, 90, 'latitude', $redirect);
        $longitude = $this->normalizarCoordenada($dados['longitude'], -180, 180, 'longitude', $redirect);

        return [
            'nome' => $dados['nome'],
            'descricao' => $dados['descricao'],
            'tipo_imovel' => $dados['tipo_imovel'],
            'valor_diaria' => number_format((float) $dados['valor_diaria'], 2, '.', ''),
            'cidade' => $dados['cidade'],
            'regiao' => $dados['regiao'],
            'endereco' => $dados['endereco'],
            'latitude' => $latitude,
            'longitude' => $longitude,
            'checkin_hora_inicial'=>$dados['checkin_hora_inicial'],'checkin_hora_final'=>$dados['checkin_hora_final'],
            'checkout_hora_inicial'=>$dados['checkout_hora_inicial'],'checkout_hora_final'=>$dados['checkout_hora_final'],
        ];
    }

    private function normalizarCoordenada(string $valor, float $min, float $max, string $campo, string $redirect): ?string
    {
        if ($valor === '') {
            return null;
        }

        $normalizado = str_replace(',', '.', $valor);

        if (!is_numeric($normalizado) || (float) $normalizado < $min || (float) $normalizado > $max) {
            flash('error', 'Informe uma ' . $campo . ' valida.');
            $this->redirect($redirect);
        }

        return $normalizado;
    }

    private function processarUploads(Chacara $model, int $chacaraId): int
    {
        if (empty($_FILES['fotos']) || !is_array($_FILES['fotos']['name'])) {
            return 0;
        }

        $total = 0;
        $names = $_FILES['fotos']['name'];
        $uploadDir = APP_ROOT . '/public/assets/' . self::UPLOAD_RELATIVE_DIR;

        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            throw new RuntimeException('Nao foi possivel criar a pasta de upload.');
        }

        foreach ($names as $index => $originalName) {
            if (($_FILES['fotos']['error'][$index] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $tmpName = (string) ($_FILES['fotos']['tmp_name'][$index] ?? '');
            $this->validarUpload($index, $tmpName);
            $extension = $this->extensaoSegura($tmpName, (string) $originalName);
            $fileName = bin2hex(random_bytes(18)) . '.' . $extension;
            $destino = $uploadDir . '/' . $fileName;

            if (!move_uploaded_file($tmpName, $destino)) {
                throw new RuntimeException('Falha ao salvar uma das fotos enviadas.');
            }

            $model->adicionarFoto($chacaraId, self::UPLOAD_RELATIVE_DIR . '/' . $fileName);
            $total++;
        }

        return $total;
    }

    private function validarUpload(int $index, string $tmpName): void
    {
        $error = (int) ($_FILES['fotos']['error'][$index] ?? UPLOAD_ERR_NO_FILE);
        $size = (int) ($_FILES['fotos']['size'][$index] ?? 0);

        if ($error !== UPLOAD_ERR_OK) {
            if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                throw new RuntimeException('Cada foto deve ter no maximo ' . $this->formatarBytes($this->limiteArquivoEfetivo()) . '.');
            }

            throw new RuntimeException('Uma das fotos nao foi enviada corretamente.');
        }

        if ($size <= 0 || $size > self::MAX_UPLOAD_BYTES) {
            throw new RuntimeException('Cada foto deve ter no maximo ' . $this->formatarBytes($this->limiteArquivoEfetivo()) . '.');
        }

        if (!is_uploaded_file($tmpName)) {
            throw new RuntimeException('Upload invalido.');
        }

        if (@getimagesize($tmpName) === false) {
            throw new RuntimeException('Envie apenas imagens reais.');
        }
    }

    private function extensaoSegura(string $tmpName, string $originalName): string
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $permitidas = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
        ];

        if (!isset($permitidas[$extension])) {
            throw new RuntimeException('Formatos aceitos: JPG, JPEG, PNG e WEBP.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($tmpName);

        if ($mime !== $permitidas[$extension]) {
            throw new RuntimeException('O tipo do arquivo nao corresponde a extensao enviada.');
        }

        return $extension === 'jpeg' ? 'jpg' : $extension;
    }

    private function postExcedeuLimite(): bool
    {
        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

        if ($contentLength <= 0) {
            return false;
        }

        $postMaxBytes = $this->bytesIni((string) ini_get('post_max_size'));

        return $postMaxBytes > 0 && $contentLength > $postMaxBytes && empty($_POST);
    }

    private function bytesIni(string $valor): int
    {
        $valor = trim($valor);

        if ($valor === '') {
            return 0;
        }

        $unidade = strtolower($valor[strlen($valor) - 1]);
        $numero = (float) $valor;

        return match ($unidade) {
            'g' => (int) ($numero * 1024 * 1024 * 1024),
            'm' => (int) ($numero * 1024 * 1024),
            'k' => (int) ($numero * 1024),
            default => (int) $numero,
        };
    }

    private function limiteArquivoEfetivo(): int
    {
        $phpUploadMax = $this->bytesIni((string) ini_get('upload_max_filesize'));

        if ($phpUploadMax <= 0) {
            return self::MAX_UPLOAD_BYTES;
        }

        return min(self::MAX_UPLOAD_BYTES, $phpUploadMax);
    }

    private function formatarBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return rtrim(rtrim(number_format($bytes / 1048576, 1, ',', ''), '0'), ',') . 'MB';
        }

        if ($bytes >= 1024) {
            return rtrim(rtrim(number_format($bytes / 1024, 1, ',', ''), '0'), ',') . 'KB';
        }

        return $bytes . ' bytes';
    }

    private function removerArquivoUpload(?string $caminho): void
    {
        if ($caminho === null || !str_starts_with($caminho, self::UPLOAD_RELATIVE_DIR . '/')) {
            return;
        }

        $base = realpath(APP_ROOT . '/public/assets/' . self::UPLOAD_RELATIVE_DIR);
        $arquivo = realpath(APP_ROOT . '/public/assets/' . $caminho);

        if ($base !== false && $arquivo !== false && str_starts_with($arquivo, $base) && is_file($arquivo)) {
            @unlink($arquivo);
        }
    }

    private function validarId(string $id): int
    {
        $validado = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($validado === false) {
            http_response_code(404);
            $this->view('public/404', ['title' => 'Chacara nao encontrada']);
            exit;
        }

        return (int) $validado;
    }
}
