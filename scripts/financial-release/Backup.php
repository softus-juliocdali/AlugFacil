<?php
declare(strict_types=1);
namespace FinancialRelease;
use PDO;
use RuntimeException;

final class Backup
{
    public static function create(PDO $db,array $profile,string $dump,string $pgDump): array
    {
        if(file_exists($dump))throw new RuntimeException('Backup path already exists');
        $db->exec('BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY');
        try {
            $snapshot=$db->query('SELECT pg_export_snapshot()')->fetchColumn();
            $schema=Catalog::schema($db);$data=Catalog::data($db);$sequences=Catalog::sequences($db);
            Runtime::process([$pgDump,'--host='.$profile['host'],'--port='.$profile['port'],'--username='.$profile['user'],'--dbname='.$profile['database'],'--no-password','--format=custom','--snapshot='.$snapshot,'--file='.$dump],$profile);
            chmod($dump,0600);
            if($sequences!==Catalog::sequences($db))throw new RuntimeException('Sequences changed during backup; quiesce writers');
            $result=['kind'=>'financial-backup-v1','created_at'=>time(),'source'=>Runtime::identity($db),'schema'=>$schema,'data'=>$data,'sequences'=>$sequences,'dump_sha256'=>hash_file('sha256',$dump),'dump_bytes'=>filesize($dump)];
            $db->rollBack();return $result;
        } catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function check(array $e,string $dump): void
    {
        if(($e['kind']??'')!=='financial-backup-v1'||!is_file($dump)||!hash_equals($e['dump_sha256'],hash_file('sha256',$dump)))throw new RuntimeException('Backup checksum mismatch');
        $age=time()-(int)$e['created_at'];
        if($age<0||$age>86400)throw new RuntimeException('Backup older than 24 hours or from future');
    }
    public static function restore(PDO $db,array $profile,array $backup,string $dump,string $pgRestore): array
    {
        self::check($backup,$dump);
        if($profile['mode']!=='rehearsal')throw new RuntimeException('Restore allowed only in rehearsal');
        if($backup['source']===Runtime::identity($db))throw new RuntimeException('Cannot restore over source');
        if($backup['source']['server_major']!==Runtime::identity($db)['server_major'])throw new RuntimeException('Restore major mismatch');
        if($db->query("SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public'")->fetchColumn()>0)throw new RuntimeException('Restore target must be empty');
        Runtime::process([$pgRestore,'--host='.$profile['host'],'--port='.$profile['port'],'--username='.$profile['user'],'--dbname='.$profile['database'],'--no-password','--exit-on-error','--single-transaction','--no-owner','--no-privileges',$dump],$profile);
        $db->exec('BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY');
        try {
            if(!hash_equals($backup['schema'],Catalog::schema($db))||Runtime::hash($backup['data'])!==Runtime::hash(Catalog::data($db))||($backup['sequences']??null)!==Catalog::sequences($db))throw new RuntimeException('Restored schema/data/sequences differ from source snapshot');
            $result=['kind'=>'financial-restore-v1','verified_at'=>time(),'backup'=>$backup,'restored'=>Runtime::identity($db),'backup_digest'=>Runtime::hash($backup)];
            $db->rollBack();return $result;
        } catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
}
