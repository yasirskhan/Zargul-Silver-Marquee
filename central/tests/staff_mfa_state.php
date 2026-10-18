<?php
declare(strict_types=1);

use Hafzatech\Central\CentralStaffTotpVerifier;
use Hafzatech\Central\CentralStaffMfaChallenge;

require dirname(__DIR__).'/src/CentralStaffTotpVerifier.php';
require dirname(__DIR__).'/src/CentralStaffMfaChallenge.php';

function mfaRequire(bool $ok,string $description):void {
    if(!$ok)throw new RuntimeException('FAIL staff MFA state: '.$description);
}
function mfaUuid():string {
    $b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);$b[8]=chr((ord($b[8])&63)|128);
    $h=bin2hex($b);
    return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
}
$dsn=getenv('CENTRAL_DB_DSN');
if(!$dsn || !str_contains($dsn,'hafzatech_central_test'))
    throw new RuntimeException('Dedicated central test database required');
$db=new PDO($dsn,getenv('CENTRAL_DB_USERNAME'),getenv('CENTRAL_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$seed='12345678901234567890'; // Public RFC 6238 seed, NEVER a real staff secret.
$create=static function(string $email,string $status='active')use($db,$seed):string{
    $staff=mfaUuid();
    $stmt=$db->prepare("INSERT INTO central_staff_users
        (id,email,display_name,password_hash,mfa_secret_ciphertext,mfa_enrolled_at,status)
        VALUES(:id,:email,'Synthetic MFA Test',:hash,decode(:cipher,'hex'),now(),:status)");
    $stmt->execute(['id'=>$staff,'email'=>$email,
        'hash'=>password_hash('synthetic-protected-password',PASSWORD_DEFAULT),
        'cipher'=>bin2hex($seed),'status'=>$status]);
    return $staff;
};
$first=$create('mfa-counter-first@example.test');
$second=$create('mfa-counter-second@example.test');
$suspended=$create('mfa-counter-suspended@example.test','suspended');
$auth=new CentralStaffMfaChallenge($db,static fn(string $cipher):string=>$cipher);
mfaRequire(!$auth->verify($first,'287082',false,59),
    'untrusted password authentication assertion denied');
mfaRequire($auth->verify($first,'287082',true,59),
    'trusted password step and RFC TOTP accepted');
mfaRequire(!$auth->verify($first,'287082',true,59),
    'same TOTP counter cannot be replayed');
$m=$db->prepare('SELECT last_accepted_counter FROM central_staff_mfa_replay_state WHERE staff_id=:id');
$m->execute(['id'=>$first]);
mfaRequire((int)$m->fetchColumn()===1,'accepted counter durably retained');
mfaRequire(!$auth->verify($suspended,'287082',true,59),
    'suspended vendor staff cannot establish second factor');
for($n=1;$n<=5;$n++){
    mfaRequire(!$auth->verify($second,'999999',true,59),
        'invalid MFA proof denied at attempt '.$n);
}
$q=$db->prepare('SELECT failed_attempts,locked_until FROM central_staff_mfa_replay_state WHERE staff_id=:id');
$q->execute(['id'=>$second]);
$state=$q->fetch(PDO::FETCH_ASSOC);
mfaRequire((int)$state['failed_attempts']===5 && $state['locked_until']!==null,
    'five failed attempts persist a 15-minute lockout');
mfaRequire(!$auth->verify($second,'287082',true,59),
    'correct code cannot bypass active lockout');
mfaRequire($auth->verify($second,'081804',true,1111111109),
    'fresh valid code accepted after lockout expiration');
$q->execute(['id'=>$second]);
$reset=$q->fetch(PDO::FETCH_ASSOC);
mfaRequire((int)$reset['failed_attempts']===0 && $reset['locked_until']===null,
    'success clears failed attempts and lockout');
$m->execute(['id'=>$second]);
mfaRequire((int)$m->fetchColumn()===intdiv(1111111109,30),
    'new accepted counter advanced under database row lock');
$audit=$db->prepare(
    'SELECT outcome,accepted_counter FROM central_staff_mfa_attempt_audit
     WHERE staff_id=:staff ORDER BY id'
);
$audit->execute(['staff'=>$first]);
$firstEvents=$audit->fetchAll(PDO::FETCH_ASSOC);
mfaRequire(array_column($firstEvents,'outcome')===['accepted','rejected'],
    'successful MFA and replay recorded in order without exposing submitted code');
mfaRequire((int)$firstEvents[0]['accepted_counter']===1 &&
    $firstEvents[1]['accepted_counter']===null,
    'only accepted MFA logs a counter');
$audit->execute(['staff'=>$second]);
mfaRequire(array_column($audit->fetchAll(PDO::FETCH_ASSOC),'outcome')===
    ['rejected','rejected','rejected','rejected','locked_out','locked','accepted'],
    'five failures, active lockout and recovery fully auditable');
$testRow=$db->query('SELECT id FROM central_staff_mfa_attempt_audit LIMIT 1')->fetchColumn();
try{
    $db->prepare('DELETE FROM central_staff_mfa_attempt_audit WHERE id=:id')
        ->execute(['id'=>$testRow]);
    throw new RuntimeException('FAIL MFA audit was deletable');
}catch(PDOException $e){
    mfaRequire($e->getCode()==='23514','audit deletion blocked by database trigger');
}
echo "PASS: durable staff TOTP replay, disabled staff, trusted password and failed-attempt lockout\n";
