<?php
declare(strict_types=1);
ini_set('display_errors','0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
require __DIR__.'/domain.php';
require __DIR__.'/storage.php';

function respond(array $data, int $status=200): never {
    http_response_code($status);echo json_data($data);exit;
}
function body_data(): array {
    if(!str_starts_with(strtolower($_SERVER['CONTENT_TYPE']??''),'application/json'))respond(['error'=>'Gunakan JSON.'],415);
    $raw=file_get_contents('php://input',false,null,0,8*1024*1024+1);
    if(strlen($raw)>8*1024*1024)respond(['error'=>'Data maksimal 8 MB.'],413);
    try{$data=json_decode($raw,true,64,JSON_THROW_ON_ERROR);}catch(JsonException){respond(['error'=>'JSON tidak valid.'],400);}
    if(!is_array($data))respond(['error'=>'Payload tidak valid.'],400);return $data;
}
function uuid(): string {
    $b=random_bytes(16);$b[6]=chr((ord($b[6])&0x0f)|0x40);$b[8]=chr((ord($b[8])&0x3f)|0x80);$h=bin2hex($b);
    return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
}
function check_csrf(): void {
    $token=$_SERVER['HTTP_X_CSRF_TOKEN']??'';
    if(!is_string($token)||!hash_equals($_SESSION['csrf'],$token))respond(['error'=>'Sesi berubah. Muat ulang halaman.'],403);
}
function signed_in(array $user): array {
    session_regenerate_id(true);$_SESSION['user_id']=$user['id'];$_SESSION['last_seen']=time();$_SESSION['csrf']=bin2hex(random_bytes(32));
    return ['authenticated'=>true,'user'=>['id'=>$user['id'],'name'=>$user['name'],'email'=>$user['email']],'csrf'=>$_SESSION['csrf']];
}

try {
    $configPath=getenv('SHIZUFI_CONFIG_PATH')?:dirname(__DIR__,2).'/shizufi-config.php';
    if(!is_file($configPath))respond(['error'=>'Backend belum dikonfigurasi. Ikuti HOSTINGER.md: impor schema.sql dan buat konfigurasi privat di luar public_html.'],503);
    $config=require $configPath;
    foreach(['db_host','db_name','db_user','db_password','setup_token'] as $key)if(!is_array($config)||!isset($config[$key])||!is_string($config[$key]))respond(['error'=>'Konfigurasi backend belum lengkap.'],503);
    $secure=(bool)($config['secure_cookies']??true);
    if(!$secure&&!in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true))respond(['error'=>'Cookie HTTPS wajib untuk server publik.'],503);
    ini_set('session.use_strict_mode','1');ini_set('session.use_only_cookies','1');ini_set('session.gc_maxlifetime','3600');
    session_name('shizufi_session');session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Strict']);session_start();
    if(isset($_SESSION['last_seen'])&&time()-$_SESSION['last_seen']>3600){$_SESSION=[];session_regenerate_id(true);}
    $_SESSION['csrf']??=bin2hex(random_bytes(32));
    $db=new PDO('mysql:host='.$config['db_host'].';port='.(int)($config['db_port']??3306).';dbname='.$config['db_name'].';charset=utf8mb4',$config['db_user'],$config['db_password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $settings=$db->query('SELECT owner_id FROM app_settings WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    if(!$settings)respond(['error'=>'Skema database belum siap. Impor database/schema.sql.'],503);
    $action=$_GET['action']??'session';$method=$_SERVER['REQUEST_METHOD']??'GET';
    $expected=['session'=>'GET','setup'=>'POST','login'=>'POST','logout'=>'POST','state'=>$method==='GET'?'GET':'PUT'];
    if(!isset($expected[$action]))respond(['error'=>'Endpoint tidak ditemukan.'],404);
    if($expected[$action]!==$method)respond(['error'=>'Metode tidak didukung.'],405);
    if($method!=='GET')check_csrf();
    if($action==='session') {
        $user=null;if(isset($_SESSION['user_id'])){$q=$db->prepare('SELECT id,email,name FROM users WHERE id=? AND status=?');$q->execute([$_SESSION['user_id'],'active']);$user=$q->fetch(PDO::FETCH_ASSOC)?:null;}
        respond(['authenticated'=>$user!==null,'setup_required'=>$settings['owner_id']===null,'user'=>$user,'csrf'=>$_SESSION['csrf']]);
    }
    if($action==='setup'||$action==='login') {
        $data=body_data();$email=strtolower(trim((string)($data['email']??'')));$password=$data['password']??null;
        if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>190||!is_string($password)||strlen($password)>72)respond(['error'=>'Email / password tidak valid.'],422);
        $keys=[hash('sha256','ip:'.($_SERVER['REMOTE_ADDR']??'')),hash('sha256','email:'.$email)];
        $db->prepare('DELETE FROM login_attempts WHERE expires_at<?')->execute([time()]);
        foreach($keys as $key){$q=$db->prepare('SELECT attempts FROM login_attempts WHERE attempt_key=?');$q->execute([$key]);if((int)$q->fetchColumn()>=10)respond(['error'=>'Terlalu banyak percobaan. Coba lagi dalam 15 menit.'],429);}
        foreach($keys as $key)$db->prepare('INSERT INTO login_attempts(attempt_key,attempts,expires_at) VALUES(?,1,?) ON DUPLICATE KEY UPDATE attempts=attempts+1')->execute([$key,time()+900]);
        if($action==='setup') {
            if($settings['owner_id']!==null)respond(['error'=>'Pemilik sudah terdaftar. Gunakan login.'],409);
            $token=$data['setup_token']??'';
            if(strlen($config['setup_token'])<32||str_starts_with($config['setup_token'],'REPLACE')||!is_string($token)||!hash_equals($config['setup_token'],$token))respond(['error'=>'Kunci instalasi tidak sesuai.'],403);
            $name=trim((string)($data['name']??''));if(!valid_text($name,80)||!$name||strlen($password)<12)respond(['error'=>'Nama wajib diisi; password login minimal 12 karakter dan maksimal 72 byte.'],422);
            $user=['id'=>uuid(),'email'=>$email,'name'=>$name];$wid=uuid();$hash=password_hash($password,PASSWORD_DEFAULT);
            $db->beginTransaction();$lock=$db->query('SELECT owner_id FROM app_settings WHERE id=1 FOR UPDATE')->fetchColumn();
            if($lock!==null&&$lock!==false){$db->rollBack();respond(['error'=>'Pemilik sudah terdaftar.'],409);}
            $db->prepare('INSERT INTO users(id,email,name,password_hash) VALUES(?,?,?,?)')->execute([$user['id'],$email,$name,$hash]);
            $db->prepare('INSERT INTO workspaces(id,owner_id,name) VALUES(?,?,?)')->execute([$wid,$user['id'],'Keuangan pribadi']);
            $db->prepare('UPDATE app_settings SET owner_id=? WHERE id=1')->execute([$user['id']]);$db->commit();
        } else {
            $q=$db->prepare('SELECT id,email,name,password_hash FROM users WHERE email=? AND status=?');$q->execute([$email,'active']);$user=$q->fetch(PDO::FETCH_ASSOC);
            // Fixed dummy hash keeps missing-account verification comparable to a failed password.
            $dummy='$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi';
            $verified=password_verify($password,$user['password_hash']??$dummy);
            if(!$user||!$verified)respond(['error'=>'Email atau password salah.'],401);
        }
        foreach($keys as $key)$db->prepare('DELETE FROM login_attempts WHERE attempt_key=?')->execute([$key]);
        respond(signed_in($user));
    }
    if($action==='logout') {$_SESSION=[];session_destroy();setcookie('shizufi_session','',['expires'=>time()-3600,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Strict']);respond(['authenticated'=>false]);}
    if(!isset($_SESSION['user_id']))respond(['error'=>'Silakan login.'],401);
    $q=$db->prepare('SELECT id,email,name FROM users WHERE id=? AND status=?');$q->execute([$_SESSION['user_id'],'active']);$user=$q->fetch(PDO::FETCH_ASSOC);
    if(!$user||$user['id']!==$settings['owner_id'])respond(['error'=>'Akses ditolak.'],403);
    $_SESSION['last_seen']=time();
    if($method==='GET') {
        $q=$db->prepare('SELECT revision,state_json FROM workspaces WHERE owner_id=?');$q->execute([$user['id']]);$row=$q->fetch(PDO::FETCH_ASSOC);
        respond(['revision'=>(int)$row['revision'],'state'=>$row['state_json']===null?null:json_decode($row['state_json'],false,64,JSON_THROW_ON_ERROR)]);
    }
    $data=body_data();if(!is_int($data['revision']??null)||!is_array($data['state']??null))respond(['error'=>'Data atau revisi tidak valid.'],422);
    // Validate independently of browser JavaScript; never trust submitted position caches.
    $validated=validate_state($data['state']);
    $db->beginTransaction();$q=$db->prepare('SELECT id,revision FROM workspaces WHERE owner_id=? FOR UPDATE');$q->execute([$user['id']]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if((int)$row['revision']!==$data['revision']){$db->rollBack();respond(['error'=>'Data berubah dari perangkat lain. Ekspor backup lalu muat ulang halaman sebelum menyimpan lagi.'],409);}
    $s=canonical_state($validated,$user,$row['id']);$revision=(int)$row['revision']+1;
    sync_tables($db,$row['id'],$s);
    $db->prepare('UPDATE workspaces SET name=?,revision=?,state_json=? WHERE id=?')->execute([$s['workspaces'][0]['name'],$revision,json_data($s),$row['id']]);$db->commit();
    respond(['revision'=>$revision,'state'=>$s]);
} catch(InvalidArgumentException $e) {
    if(isset($db)&&$db->inTransaction())$db->rollBack();respond(['error'=>$e->getMessage()],422);
} catch(Throwable $e) {
    if(isset($db)&&$db->inTransaction())$db->rollBack();
    // Do not put SQL, DSNs, passwords, or financial request bodies into the response/log.
    error_log('ShizuFi backend failure: '.get_class($e));respond(['error'=>'Backend belum siap atau database gagal diakses. Periksa konfigurasi privat, skema SQL dan versi PHP.'],503);
}
