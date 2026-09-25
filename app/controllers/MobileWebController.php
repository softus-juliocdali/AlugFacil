<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Api\MobileWebSession;
use App\Core\Auth;

final class MobileWebController
{
    public function enter(): void
    {
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        try {
            $record=MobileWebSession::consume(is_string($_POST['ticket']??null)?$_POST['ticket']:'');
            $_SESSION=[];
            Auth::login($record['account']);
            $_SESSION['_mobile_session_id']=$record['session'];
            $_SESSION['_mobile_role']=$record['role'];
            header('Location: '.url($record['path']),true,303);
        } catch (\Throwable) {
            http_response_code(401);
            echo 'Sessão indisponível. Volte ao aplicativo e abra a tela novamente.';
        }
    }
}
