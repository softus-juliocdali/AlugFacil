<?php
declare(strict_types=1);
define('APP_ROOT', dirname(__DIR__));
require APP_ROOT.'/app/helpers/functions.php';
spl_autoload_register(static function(string $class):void{if(!str_starts_with($class,'App\\'))return;$p=explode('\\',substr($class,4));$p[0]=strtolower($p[0]);$f=APP_ROOT.'/app/'.implode('/',$p).'.php';if(is_file($f))require $f;});
$config=require APP_ROOT.'/app/config/config.php';
date_default_timezone_set($config['timezone']);
