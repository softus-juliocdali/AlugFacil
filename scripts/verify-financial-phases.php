<?php
declare(strict_types=1);
// Local test suites have their own synthetic fixture guards. No external homologation here.
$root=dirname(__DIR__);$tests=glob($root.'/tests/phase*_test.php');
foreach(['monthly_refund_hold','affiliate_commission_finance','affiliate_attribution','affiliate_portal_complete','financial_architecture','financial_error_message','financial_onboarding','asaas_http_client_user_agent','old_input_flash','owner_access_approval_regression','reservation_calendar','location_flow','public_eligibility_regression','owner_chacara_default_hours'] as $name)$tests[]=$root.'/tests/'.$name.'_test.php';
$run=static function(array $args)use($root):array{$p=proc_open(array_merge([PHP_BINARY],$args),[1=>['pipe','w'],2=>['pipe','w']],$pipes,$root);if(!is_resource($p))throw new RuntimeException('Processo indisponivel.');$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return ['exit'=>proc_close($p),'out'=>$out,'err'=>$err];};
$report=['tests'=>[],'lint'=>['passed'=>0,'failed'=>[]]];$failures=0;
foreach($tests as $file){$r=$run([$file]);$item=['file'=>basename($file),'exit'=>$r['exit'],'checks'=>substr_count($r['out'],'[OK]')];$report['tests'][]=$item;echo json_encode($item).PHP_EOL;if($r['exit']!==0){$failures++;echo 'Falha: '.basename($file).'; executar separadamente para diagnostico local.'.PHP_EOL;}}
foreach(['app','scripts','tests'] as $dir){$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir,FilesystemIterator::SKIP_DOTS));foreach($it as $file){if($file->getExtension()!=='php')continue;$r=$run(['-l',$file->getPathname()]);if($r['exit']===0)$report['lint']['passed']++;else{$report['lint']['failed'][]=str_replace($root.'/','',$file->getPathname());$failures++;}}}
echo json_encode(['lint'=>$report['lint']]).PHP_EOL;
if(file_put_contents($root.'/storage/financial-migration/regression-'.date('Ymd-His').'.json',json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR))===false)throw new RuntimeException('Sem permissao para gravar evidencia.');exit($failures?1:0);
