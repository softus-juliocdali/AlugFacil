<?php
declare(strict_types=1);
namespace App\Api;

use App\Core\Database;
use App\Models\Chacara;

final class MobileFavoritesApi
{
    public function dispatch(string $method, string $path, array $query): array
    {
        $user = ApiAuth::user();
        PublicQuery::keys($query, []);
        $model = new Chacara();
        if ($path === '/api/v1/favoritos') {
            if ($method !== 'GET') throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Método não permitido.');
            $config = require APP_ROOT . '/app/config/config.php';
            $serializer = new PublicPropertySerializer($config['app_url'], $config['app_env']);
            $items = [];
            foreach ($model->listarFavoritosDoUsuario((int)$user['id']) as $row) {
                $property = $model->buscarPerfil((int)$row['id']);
                if ($property !== null) $items[] = $serializer->card($property);
            }
            return [['ids' => $model->favoritosDoUsuario((int)$user['id']), 'imoveis' => $items], []];
        }
        if (!preg_match('~^/api/v1/favoritos/([^/]+)/(salvar|remover)$~D', $path, $m)) throw new ApiException(404, 'NOT_FOUND', 'Rota não encontrada.');
        if ($method !== 'POST') throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Método não permitido.');
        $id = PublicQuery::id($m[1]);
        $saved = $m[2] === 'salvar';
        if ($saved && $model->buscarPerfil($id) === null) throw new ApiException(404, 'NOT_FOUND', 'Imóvel indisponível.');
        // Set the desired state: retries must never toggle a second time.
        $sql = $saved
            ? 'INSERT INTO favoritos_chacaras(usuario_id,chacara_id) VALUES(:u,:c) ON CONFLICT(usuario_id,chacara_id) DO NOTHING'
            : 'DELETE FROM favoritos_chacaras WHERE usuario_id=:u AND chacara_id=:c';
        Database::getConnection()->prepare($sql)->execute(['u' => $user['id'], 'c' => $id]);
        return [['id' => $id, 'favorito' => $saved], []];
    }
}
