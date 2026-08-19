<?php
declare(strict_types=1);
namespace App\Services;
interface AsaasTransferClientInterface{public function listarTransferencias(array$filtros):array;public function criarTransferencia(array$payload):array;public function consultarTransferencia(string$id):array;}
