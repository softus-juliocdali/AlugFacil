<?php
declare(strict_types=1);
namespace FinancialRelease;
use PDO;
use RuntimeException;

final class Migrator
{
    public static function manifest(string $path,string $pin): array
    {
        $m=Runtime::pinned($path,$pin);
        if(($m['kind']??'')!=='financial-release-v1'||count($m['migrations']??[])!==23)throw new RuntimeException('Expected 23 migrations');
        $previous=null;$versions=[];
        foreach($m['migrations'] as $row){
            if($row['dependency']!==$previous||isset($versions[$row['version']]))throw new RuntimeException('Manifest dependency mismatch');
            $versions[$row['version']]=true;$previous=$row['version'];
        }
        foreach(array_merge($m['migrations'],$m['code']) as $row){
            if(!preg_match('~^(database|app|scripts|public)/[A-Za-z0-9_./-]+$~D',$row['path'])||str_contains($row['path'],'..')||!hash_equals($row['sha256'],hash_file('sha256',APP_ROOT.'/'.$row['path'])))throw new RuntimeException('Release file checksum mismatch: '.$row['path']);
        }
        return $m;
    }
    public static function execute(PDO $db,array $profile,array $manifest,array $receipt,string $dump,?array $certificate,bool $apply,?string $fail=null): array
    {
        if(($receipt['kind']??'')!=='financial-restore-v1'||$receipt['backup_digest']!==Runtime::hash($receipt['backup']))throw new RuntimeException('Verified restore receipt required');
        Backup::check($receipt['backup'],$dump);
        $id=Runtime::identity($db);$rehearsing=$certificate===null;
        if($rehearsing&&($profile['mode']!=='rehearsal'||$receipt['restored']!==$id))throw new RuntimeException('Rehearsal target differs from restored database');
        if($fail!==null&&($profile['mode']!=='rehearsal'||!preg_match('/^(?:[1-9]|1[0-9]|2[0-3]):(?:after-sql|after-backfill|after-version)$/D',$fail)))throw new RuntimeException('Failure injection permitted only in rehearsal');
        if(!$rehearsing){
            if(($certificate['kind']??'')!=='financial-rehearsal-v1'||$certificate['manifest_digest']!==Runtime::hash($manifest)||$certificate['receipt_digest']!==Runtime::hash($receipt)||count($certificate['stages']??[])!==24)throw new RuntimeException('Completed rehearsal certificate mismatch');
            if($profile['mode']==='production'&&$receipt['backup']['source']!==$id)throw new RuntimeException('Backup source is not production target');
            if($id['server_major']!==$receipt['backup']['source']['server_major'])throw new RuntimeException('Rehearsal major differs');
        }
        $db->beginTransaction();
        try {
            Catalog::lock($db);
            $n=Catalog::versions($db,$manifest);$schema=Catalog::schema($db);
            // This runner commits all 23 phases atomically; foreign partial histories are never resumed.
            if($n!==0&&$n!==23)throw new RuntimeException('Partial version history: manual review required');
            if($n===23){
                if(!$certificate||$schema!==$certificate['stages'][23])throw new RuntimeException('Final schema drift');
                $db->rollBack();return ['kind'=>'financial-noop-v1','versions'=>23,'schema'=>$schema];
            }
            Catalog::baseline($db,$manifest);
            if($schema!==$receipt['backup']['schema']||Runtime::hash(Catalog::data($db))!==Runtime::hash($receipt['backup']['data'])||($receipt['backup']['sequences']??null)!==Catalog::sequences($db))throw new RuntimeException('Baseline schema/data changed since verified backup');
            if($certificate&&$schema!==$certificate['stages'][0])throw new RuntimeException('Baseline schema differs from rehearsal');
            if(!$apply){$db->rollBack();return ['kind'=>'financial-plan-v1','pending'=>23,'schema'=>$schema];}
            $stages=[$schema];$timings=[];$report=[];
            foreach($manifest['migrations'] as $i=>$migration){
                $start=microtime(true);$phase=$i+1;
                // Recheck content under lock and use the checked bytes, not a second file read.
                $sql=file_get_contents(APP_ROOT.'/'.$migration['path']);
                if(!hash_equals($migration['sha256'],hash('sha256',$sql)))throw new RuntimeException('Migration checksum changed');
                $db->exec($sql);self::fail($fail,$phase.':after-sql');
                if($i===0){
                    $report=(new \App\Services\CadastroBackfillService())->executar($db);
                    if((int)$db->query('SELECT count(*) FROM proprietarios')->fetchColumn()!==(int)$db->query('SELECT count(*) FROM proprietario_cadastro')->fetchColumn())throw new RuntimeException('Backfill owner count mismatch');
                }
                self::fail($fail,$phase.':after-backfill');
                $db->prepare('INSERT INTO financeiro_schema_versions(versao,sha256) VALUES(:v,:h)')->execute(['v'=>$migration['version'],'h'=>$migration['sha256']]);
                self::fail($fail,$phase.':after-version');
                $stages[]=Catalog::schema($db);
                if($certificate&&$stages[$phase]!==$certificate['stages'][$phase])throw new RuntimeException('Schema differs from rehearsal at phase '.$phase);
                $timings[]=['version'=>$migration['version'],'milliseconds'=>(int)((microtime(true)-$start)*1000)];
            }
            if(Catalog::versions($db,$manifest)!==23)throw new RuntimeException('Incomplete chain');
            $result=['kind'=>$rehearsing?'financial-rehearsal-v1':'financial-applied-v1','completed_at'=>time(),'target'=>$id,'manifest_digest'=>Runtime::hash($manifest),'receipt_digest'=>Runtime::hash($receipt),'stages'=>$stages,'timings'=>$timings,'conflicts'=>$report,'after'=>Catalog::data($db)];
            $db->commit();return $result;
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    private static function fail(?string $requested,string $at): void
    {
        if($requested===$at)throw new RuntimeException('Injected failure '.$at);
    }
}
