<?php
declare(strict_types=1);
require __DIR__.'/Runtime.php';
use FinancialRelease\Runtime;
Runtime::boot();
$source=APP_ROOT.'/deliverables/financial-release-20260924/migrations.json';
$migrations=Runtime::json($source);$previous=null;
foreach($migrations as &$row){$row['dependency']=$previous;$previous=$row['version'];$row['sha256']=hash_file('sha256',APP_ROOT.'/'.$row['path']);}unset($row);
$files=[];
foreach(['app','public','scripts'] as $dir){
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APP_ROOT.'/'.$dir,FilesystemIterator::SKIP_DOTS)) as $f){
        if($f->isFile()&&$f->getExtension()==='php'){
            $path=str_replace('\\','/',substr($f->getPathname(),strlen(APP_ROOT)+1));
            $files[$path]=['path'=>$path,'sha256'=>hash_file('sha256',$f->getPathname())];
        }
    }
}
ksort($files);
Runtime::write($argv[1]??throw new RuntimeException('Output path required'),['kind'=>'financial-release-v1','base'=>'01a0967f78318ce19223c515a25fa1abab1c582c','financial_head'=>'5edff980e1d3ba0282daf8f40835488966f09642','migrations'=>$migrations,'code'=>array_values($files)]);
