<?php
declare(strict_types=1);

namespace Hafzatech\Central;

/** Escaped read-only support metadata; never decrypt or display messages. */
final class CentralStaffSupportQueueHtmlRenderer
{
    /** @param array<int,array<string,mixed>> $rows */
    public static function render(array $rows):string
    {
        $e=static fn(mixed $v):string=>htmlspecialchars(
            is_scalar($v)?(string)$v:'',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $body='';
        foreach(array_slice($rows,0,25) as $row) {
            $number=$row['ticket_number']??null;
            $label=is_scalar($number)
                ?'HAF-'.str_pad((string)$number,8,'0',STR_PAD_LEFT):'';
            $body.='<tr><td>'.$e($label).'</td><td>'.
                $e($row['status']??'').'</td><td>'.
                $e($row['created_at']??'').'</td></tr>';
        }
        if($body==='')$body='<tr><td colspan="3">No support tickets</td></tr>';
        return '<section aria-label="Private HAFZATECH support queue">'.
            '<h2>Customer support cases</h2>'.
            '<p>Read-only metadata. Ticket subjects and messages remain encrypted.</p>'.
            '<table><thead><tr><th>Ticket</th><th>Status</th><th>Created</th>'.
            '</tr></thead><tbody>'.$body.'</tbody></table></section>';
    }
}
