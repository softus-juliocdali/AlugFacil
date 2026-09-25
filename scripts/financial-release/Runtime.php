<?php
declare(strict_types=1);
namespace FinancialRelease;
use PDO;
use RuntimeException;

/** Separate CLI: never loads the application .env or opens its default database. */
final class Runtime
{
    public static function boot(): void
    {
        if (PHP_SAPI !== 'cli') throw new RuntimeException('CLI required');
        if (!defined('APP_ROOT')) define('APP_ROOT', dirname(__DIR__, 2));
        spl_autoload_register(static function (string $class): void {
            if (!str_starts_with($class, 'App\\')) return;
            $parts=explode('\\',substr($class,4)); $parts[0]=strtolower($parts[0]);
            require APP_ROOT.'/app/'.implode('/',$parts).'.php';
        });
        date_default_timezone_set('America/Sao_Paulo');
        putenv('ASAAS_ALLOW_SANDBOX_MUTATIONS=false');
        putenv('ASAAS_ENVIRONMENT=sandbox');
        putenv('ASAAS_BASE_URL=https://api-sandbox.asaas.com/v3');
    }
    public static function json(string $file): array
    {
        $text=file_get_contents($file);
        if($text===false) throw new RuntimeException('Cannot read JSON');
        return json_decode(ltrim($text,"\xEF\xBB\xBF"),true,512,JSON_THROW_ON_ERROR);
    }
    public static function write(string $file,array $value): void
    {
        $stream=fopen($file,'xb');
        if(!$stream) throw new RuntimeException('Cannot create evidence (no overwrite)');
        chmod($file,0600);
        try {
            $text=json_encode($value,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
            if(fwrite($stream,$text)!==strlen($text)||!fflush($stream)) throw new RuntimeException('Evidence write failed');
        } finally {fclose($stream);}
    }
    public static function hash(mixed $value): string {return hash('sha256',json_encode($value,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));}
    public static function pinned(string $file,string $hash): array
    {
        if(!preg_match('/^[a-f0-9]{64}$/D',$hash)||!hash_equals($hash,hash_file('sha256',$file))) throw new RuntimeException('Evidence/manifest checksum mismatch');
        return self::json($file);
    }
    public static function identifier(string $name): string {return '"'.str_replace('"','""',$name).'"';}
    public static function profile(string $file): array
    {
        $p=self::json($file);
        foreach(['mode','app_env','host','port','database','user','system_identifier','server_major'] as $key) if(!isset($p[$key])||(string)$p[$key]==='') throw new RuntimeException('Missing profile '.$key);
        if(!preg_match('/^[a-zA-Z0-9_.:-]+$/D',$p['host'])||!preg_match('/^[a-zA-Z0-9_]+$/D',$p['user'])||!preg_match('/^[a-z0-9_]+$/D',$p['database'])||(int)$p['port']<1||(int)$p['port']>65535||!ctype_digit((string)$p['system_identifier'])) throw new RuntimeException('Invalid target profile');
        if($p['mode']==='production') {
            if($p['database']!=='alugfacil'||$p['app_env']!=='production') throw new RuntimeException('Production identity mismatch');
        } elseif($p['mode']==='rehearsal') {
            if(!preg_match('/^alugfacil_financial_[a-z0-9_]+$/D',$p['database'])||$p['app_env']!=='test'||!in_array($p['host'],['127.0.0.1','::1','localhost'],true)) throw new RuntimeException('Rehearsal must be an explicitly named loopback database');
        } else throw new RuntimeException('Unknown profile mode');
        return $p;
    }
    public static function connect(array $p): PDO
    {
        $ssl=$p['sslmode']??'prefer';
        if(!in_array($ssl,['disable','prefer','require','verify-full'],true)) throw new RuntimeException('Invalid sslmode');
        $db=new PDO("pgsql:host={$p['host']};port={$p['port']};dbname={$p['database']};sslmode=$ssl;connect_timeout=5",$p['user'],getenv('FIN_RELEASE_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_STATEMENT_CLASS=>[\App\Core\TypedStatement::class]]);
        $id=self::identity($db);
        if($id['database']!==$p['database']||$id['system_identifier']!==(string)$p['system_identifier']||$id['server_major']!==(int)$p['server_major']||$id['port']!==(int)$p['port']) throw new RuntimeException('Connected server identity mismatch');
        $db->exec("SET search_path=public,pg_catalog; SET TIME ZONE 'America/Sao_Paulo'; SET application_name='alugfacil-financial-release'; SET statement_timeout='120s'; SET lock_timeout='5s'");
        return $db;
    }
    public static function identity(PDO $db): array
    {
        $r=$db->query("SELECT current_database() AS database,system_identifier::text,current_setting('server_version_num')::int / 10000 AS server_major,inet_server_port() AS port FROM pg_control_system()")->fetch();
        $r['server_major']=(int)$r['server_major'];$r['port']=(int)$r['port'];return $r;
    }
    /** No shell interpolation or credential/data output. */
    public static function process(array $args,array $p): void
    {
        $env=getenv();$env['PGPASSWORD']=getenv('FIN_RELEASE_PASSWORD')?:'';$env['PGSSLMODE']=$p['sslmode']??'prefer';
        $errorFile=tempnam(sys_get_temp_dir(),'fin-pg-');chmod($errorFile,0600);
        try {
            $proc=proc_open($args,[0=>['pipe','r'],1=>['file',$errorFile,'a'],2=>['file',$errorFile,'a']],$pipes,null,$env);
            if(!is_resource($proc)) throw new RuntimeException('Cannot launch PostgreSQL tool');
            fclose($pipes[0]);if(proc_close($proc)!==0) throw new RuntimeException('PostgreSQL tool failed; no evidence issued');
        } finally {unlink($errorFile);}
    }
}
