<?php
declare(strict_types=1);
namespace FinancialRelease;
use PDO;
use RuntimeException;

final class Catalog
{
    public static function tables(PDO $db): array
    {
        return $db->query("SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND c.relkind='r' ORDER BY c.relname")->fetchAll(PDO::FETCH_COLUMN);
    }
    public static function schema(PDO $db): string
    {
        $queries=[
            "SELECT c.relname,c.relkind,c.relrowsecurity,c.relforcerowsecurity,c.reloptions FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND c.relkind IN ('r','p','v','m','S') ORDER BY c.relname",
            "SELECT c.relname,a.attname,format_type(a.atttypid,a.atttypmod) AS type,a.attnotnull,a.attidentity,a.attgenerated,pg_get_expr(d.adbin,d.adrelid) AS default_expr FROM pg_attribute a JOIN pg_class c ON c.oid=a.attrelid JOIN pg_namespace n ON n.oid=c.relnamespace LEFT JOIN pg_attrdef d ON d.adrelid=c.oid AND d.adnum=a.attnum WHERE n.nspname='public' AND c.relkind IN ('r','p','v','m') AND a.attnum>0 AND NOT a.attisdropped ORDER BY c.relname,a.attnum",
            "SELECT c.relname,k.conname,pg_get_constraintdef(k.oid) AS def,k.convalidated,k.condeferrable,k.condeferred FROM pg_constraint k JOIN pg_class c ON c.oid=k.conrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' ORDER BY c.relname,k.conname",
            "SELECT tablename,indexname,indexdef FROM pg_indexes WHERE schemaname='public' ORDER BY tablename,indexname",
            "SELECT c.relname,t.tgname,t.tgenabled,pg_get_triggerdef(t.oid) AS def FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND NOT t.tgisinternal ORDER BY c.relname,t.tgname",
            "SELECT p.proname,pg_get_function_identity_arguments(p.oid) AS args,pg_get_functiondef(p.oid) AS def FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname='public' AND p.prokind IN ('f','p') ORDER BY p.proname,args",
            "SELECT sequencename,data_type,start_value,min_value,max_value,increment_by,cycle,cache_size FROM pg_sequences WHERE schemaname='public' ORDER BY sequencename",
            "SELECT viewname,definition FROM pg_views WHERE schemaname='public' ORDER BY viewname",
            "SELECT tablename,policyname,permissive,roles,cmd,qual,with_check FROM pg_policies WHERE schemaname='public' ORDER BY tablename,policyname",
        ];
        $data=[];foreach($queries as $sql)$data[]=$db->query($sql)->fetchAll();return Runtime::hash($data);
    }
    /** Full deterministic row digests. Evidence never contains row contents. */
    public static function data(PDO $db): array
    {
        $data=[];
        foreach(self::tables($db) as $table){
            $q=Runtime::identifier($table);$h=hash_init('sha256');$count=0;
            $s=$db->query("SELECT row_to_json(t)::jsonb::text AS row FROM public.$q t ORDER BY row_to_json(t)::jsonb::text COLLATE \"C\"");
            while(($row=$s->fetchColumn())!==false){hash_update($h,strlen($row).':'.$row);$count++;}
            $data[$table]=['count'=>$count,'sha256'=>hash_final($h)];
        }
        return $data;
    }
    public static function sequences(PDO $db): array
    {
        $result=[];
        foreach($db->query("SELECT sequencename FROM pg_sequences WHERE schemaname='public' ORDER BY sequencename")->fetchAll(PDO::FETCH_COLUMN) as $name){
            $result[$name]=$db->query('SELECT last_value,is_called FROM public.'.Runtime::identifier($name))->fetch();
        }
        return $result;
    }
    public static function lock(PDO $db): void
    {
        if(!$db->query('SELECT pg_try_advisory_xact_lock(741075,23)')->fetchColumn())throw new RuntimeException('Financial release/worker already running');
        $tables=self::tables($db);
        if($tables)$db->exec('LOCK TABLE '.implode(',',array_map(static fn($t)=>'public.'.Runtime::identifier($t),$tables)).' IN ACCESS EXCLUSIVE MODE');
    }
    public static function versions(PDO $db,array $manifest): int
    {
        if(!$db->query("SELECT to_regclass('public.financeiro_schema_versions')")->fetchColumn())return 0;
        $rows=$db->query('SELECT versao,sha256 FROM financeiro_schema_versions')->fetchAll(PDO::FETCH_KEY_PAIR);
        if(!$rows)throw new RuntimeException('Partial schema: empty version table');
        $count=count($rows);if($count>count($manifest['migrations']))throw new RuntimeException('Unknown versions');
        foreach($manifest['migrations'] as $i=>$m){
            if($i<$count&&(!isset($rows[$m['version']])||!hash_equals($m['sha256'],$rows[$m['version']])))throw new RuntimeException('Version dependency/checksum mismatch');
            if($i>=$count&&isset($rows[$m['version']]))throw new RuntimeException('Version gap');
        }
        return $count;
    }
    public static function baseline(PDO $db,array $manifest): void
    {
        if(self::versions($db,$manifest)!==0)throw new RuntimeException('Rehearsal requires baseline before phase 1');
        foreach($manifest['migrations'] as $m){
            $sql=file_get_contents(APP_ROOT.'/'.$m['path']);
            preg_match_all('/CREATE TABLE\s+([a-z_][a-z0-9_]*)/i',$sql,$tables);
            foreach($tables[1] as $name){$s=$db->prepare('SELECT to_regclass(:name)');$s->execute(['name'=>'public.'.$name]);if($s->fetchColumn())throw new RuntimeException('Partial schema: '.$name);}
            preg_match_all('/ALTER TABLE\s+([a-z_][a-z0-9_]*)\s+([^;]+);/i',$sql,$alters,PREG_SET_ORDER);
            foreach($alters as $alter){
                preg_match_all('/ADD COLUMN\s+([a-z_][a-z0-9_]*)/i',$alter[2],$columns);
                foreach($columns[1] as $column){
                    $s=$db->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name=:t AND column_name=:c");
                    $s->execute(['t'=>$alter[1],'c'=>$column]);if($s->fetchColumn())throw new RuntimeException('Partial schema column: '.$alter[1].'.'.$column);
                }
            }
        }
        $type=$db->query("SELECT data_type FROM information_schema.columns WHERE table_schema='public' AND table_name='reservas' AND column_name='expira_em'")->fetchColumn();
        if($type!=='timestamp without time zone')throw new RuntimeException('Baseline expiration type mismatch');
    }
}
