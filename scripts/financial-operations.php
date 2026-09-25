<?php
declare(strict_types=1);
require __DIR__.'/financial-release/Runtime.php';
require __DIR__.'/financial-release/Catalog.php';
require __DIR__.'/financial-release/Migrator.php';
require __DIR__.'/financial-release/Worker.php';
use FinancialRelease\Runtime;
use FinancialRelease\Migrator;
use FinancialRelease\Worker;
Runtime::boot();
$o=getopt('',['profile:','manifest:','manifest-sha256:','certificate:','certificate-sha256:']);
try {
    $p=Runtime::profile($o['profile']??'');
    $m=Migrator::manifest($o['manifest']??'',$o['manifest-sha256']??'');
    $c=Runtime::pinned($o['certificate']??'',$o['certificate-sha256']??'');
    echo json_encode(Worker::run(Runtime::connect($p),$m,$c),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,($e instanceof PDOException?'PostgreSQL worker error; no queue claimed':$e->getMessage()).PHP_EOL);exit(1);}
