<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * UTC term arithmetic for paid grants. Does not issue licenses or authorize
 * payments. An early renewal keeps remaining paid time; a lapsed renewal
 * starts from verified issuance time, never backdates service by accident.
 *
 * Month arithmetic clamps the day to target month end (Jan 31 -> Jul 31 for
 * 6m and Feb 29 -> Feb 28 for 12m) instead of PHP's date rollover.
 */
final class LicenseTermCalendar
{
    /**
     * @return array{effective_from:string,expires_at:string,term_months:int}
     */
    public static function paidTerm(
        int $termMonths, DateTimeImmutable $paidIssuanceTime,
        ?DateTimeImmutable $existingPaidExpiry
    ): array {
        if (!in_array($termMonths, [6, 12], true)) {
            throw new InvalidArgumentException('Only six- or twelve-month paid terms are available.');
        }
        $utc=new DateTimeZone('UTC');
        $now=$paidIssuanceTime->setTimezone($utc);
        $old=$existingPaidExpiry?->setTimezone($utc);
        $start=$old !== null && $old > $now ? $old : $now;

        $year=(int)$start->format('Y');
        $month=(int)$start->format('n');
        $day=(int)$start->format('j');
        $total=($year*12)+($month-1)+$termMonths;
        $targetYear=intdiv($total,12);
        $targetMonth=($total%12)+1;
        if($targetYear<2000 || $targetYear>9998)throw new InvalidArgumentException('Unsupported renewal year.');
        $first=$start->setDate($targetYear,$targetMonth,1);
        $max=(int)$first->format('t');
        $expiry=$start->setDate($targetYear,$targetMonth,min($day,$max));
        return [
            'effective_from'=>$start->format('Y-m-d\TH:i:s\Z'),
            'expires_at'=>$expiry->format('Y-m-d\TH:i:s\Z'),
            'term_months'=>$termMonths,
        ];
    }
}
