<?php
declare(strict_types=1);

use Hafzatech\Central\CentralStaffLoginRateLimiter;
require dirname(__DIR__).'/src/CentralStaffLoginRateLimiter.php';

function rateCheck(bool $yes,string $why):void {
    if(!$yes)throw new RuntimeException('Vendor login throttle: '.$why);
}
$dsn=getenv('CENTRAL_DB_DSN');
rateCheck(is_string($dsn)&&str_contains($dsn,'hafzatech_central_test'),
    'dedicated PostgreSQL database required');
$db=new PDO($dsn,getenv('CENTRAL_DB_USERNAME'),getenv('CENTRAL_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
try {
    new CentralStaffLoginRateLimiter($db,'short');
    throw new RuntimeException('Weak HMAC key accepted');
} catch(InvalidArgumentException $expected) {
    // A protected 256-bit key is mandatory; never use a literal in production.
}
$limit=new CentralStaffLoginRateLimiter($db,str_repeat('synthetic-testing-',3));
$email='rate-limit-fixture@example.test';
for($n=0;$n<5;$n++)
    rateCheck($limit->reserve($email,'192.0.2.19'),'initial account attempt '.($n+1));
rateCheck(!$limit->reserve($email,'192.0.2.19'),'sixth account attempt blocked');
rateCheck(!$limit->reserve($email,'198.51.100.11'),'changing IP cannot bypass account lockout');
rateCheck($limit->reserve('different-account@example.test','192.0.2.19'),
    'different account permitted below independent remote-IP cap');
rateCheck(!$limit->reserve($email,'not-an-ip'),'untrusted origin refused');
$limit->clearSuccessfulAccount($email);
rateCheck($limit->reserve($email,'192.0.2.19'),
    'only a caller asserting completed authentication can clear account failures');
for($i=1;$i<=30;$i++)
    rateCheck($limit->reserve('ip-spray-'.$i.'@example.test','203.0.113.57'),
        'IP-level threshold should allow exactly 30 attempts');
rateCheck(!$limit->reserve('ip-spray-over@example.test','203.0.113.57'),
    'new email cannot bypass shared IP limit');
$db->exec("UPDATE central_staff_login_rate_limits
    SET window_started=now()-interval '16 minutes',
        blocked_until=now()-interval '1 second'");
rateCheck($limit->reserve('ip-spray-over@example.test','203.0.113.57'),
    'expired 15-minute window permits a new attempt');
$q=$db->query("SELECT octet_length(key_hash) AS n FROM central_staff_login_rate_limits");
foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)
    rateCheck((int)$row['n']===32,'only keyed digest persisted');
echo "PASS: serialized HMAC-only vendor login throttle, account and network limits, lockout, recovery, malformed origin denial\n";
