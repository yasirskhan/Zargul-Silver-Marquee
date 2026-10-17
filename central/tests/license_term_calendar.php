<?php
declare(strict_types=1);

use Hafzatech\Central\LicenseTermCalendar;

require dirname(__DIR__).'/src/LicenseTermCalendar.php';

function assertTerm(bool $ok, string $why): void {
    if(!$ok)throw new RuntimeException('FAIL license term: '.$why);
}
$now=new DateTimeImmutable('2026-10-17T20:00:00Z');
$prior=new DateTimeImmutable('2027-04-17T20:00:00Z');
$early=LicenseTermCalendar::paidTerm(12,$now,$prior);
assertTerm($early['effective_from']==='2027-04-17T20:00:00Z','early renewal starts existing expiry');
assertTerm($early['expires_at']==='2028-04-17T20:00:00Z','paid 12 months stacked');
$expired=LicenseTermCalendar::paidTerm(6,$now,
    new DateTimeImmutable('2026-01-01T00:00:00Z'));
assertTerm($expired['effective_from']==='2026-10-17T20:00:00Z','lapsed renewal never backdated');
assertTerm($expired['expires_at']==='2027-04-17T20:00:00Z','lapsed 6 month term');
$clamp=LicenseTermCalendar::paidTerm(12,
    new DateTimeImmutable('2024-02-29T01:02:03Z'),null);
assertTerm($clamp['expires_at']==='2025-02-28T01:02:03Z','leap day clamps at month end');
$summer=LicenseTermCalendar::paidTerm(6,
    new DateTimeImmutable('2026-08-31T00:00:00Z'),null);
assertTerm($summer['expires_at']==='2027-02-28T00:00:00Z','end of month clamp');
$otherTimezone=LicenseTermCalendar::paidTerm(6,
    new DateTimeImmutable('2026-10-17T16:00:00-04:00'),null);
assertTerm($otherTimezone['effective_from']==='2026-10-17T20:00:00Z',
    'always UTC never VPS/browser timezone');
try { LicenseTermCalendar::paidTerm(9,$now,$prior); throw new RuntimeException('FAIL invalid term approved'); }
catch (InvalidArgumentException $expected) {}
echo "PASS: early six/annual renewal time preservation, lapsed reactivation, leap-month clamp and UTC\n";
