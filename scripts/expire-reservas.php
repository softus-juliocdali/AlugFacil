<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/bootstrap.php';
try{$n=(new App\Services\ReservaStatusService())->expirarVencidas(100);echo "Reservas expiradas: {$n}\n";exit(0);}catch(Throwable $e){app_log('Falha ao expirar reservas: '.$e->getMessage());fwrite(STDERR,"Falha ao processar expiracoes.\n");exit(1);}
