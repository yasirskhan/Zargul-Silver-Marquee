<?php
declare(strict_types=1);
namespace Hafzatech\Central;

/** Escaped support metadata with an optional protected reply form. */
final class CentralStaffSupportQueueHtmlRenderer
{
    /** @param array<int,array<string,mixed>> $rows */
    public static function render(array $rows,string $customerId='',?string $csrf=null):string
    {
        $e=static fn(mixed $v):string=>htmlspecialchars(
            is_scalar($v)?(string)$v:'',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $proof=is_string($csrf)&&preg_match('/^[a-f0-9]{64}$/D',$csrf)===1?$csrf:null;
        $canReply=$proof!==null&&preg_match(
            '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',
            $customerId)===1;
        $body='';
        foreach(array_slice($rows,0,25) as $row) {
            $number=$row['ticket_number']??null;
            $label=is_scalar($number)
                ?'HAF-'.str_pad((string)$number,8,'0',STR_PAD_LEFT):'';
            $ticketId=(string)($row['id']??'');
            $action='';
            if($canReply && preg_match(
                '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',
                $ticketId)===1) {
                $messageId=self::uuidV4();
                $action='<form method="post" action="/staff/support/reply">'
                    .'<input type="hidden" name="staff_csrf" value="'.$e($proof).'">'
                    .'<input type="hidden" name="customer_id" value="'.$e($customerId).'">'
                    .'<input type="hidden" name="ticket_id" value="'.$e($ticketId).'">'
                    .'<input type="hidden" name="message_id" value="'.$e($messageId).'">'
                    .'<label>Reply body <textarea name="body" required minlength="3" '
                    .'maxlength="4000" rows="3"></textarea></label>'
                    .'<button type="submit">Send encrypted reply</button></form>';
            } else {
                $action='<span>Reply unavailable</span>';
            }
            $body.='<tr><td>'.$e($label).'</td><td>'.$e($row['status']??'').'</td><td>'
                .$e($row['created_at']??'').'</td><td>'.$action.'</td></tr>';
        }
        if($body==='')$body='<tr><td colspan="4">No support tickets</td></tr>';
        return '<section aria-label="Private HAFZATECH support queue">'
            .'<h2>Customer support cases</h2>'
            .'<p>Ticket subjects and messages remain encrypted. Replies are written through '
            .'the protected support service and immutable audit.</p>'
            .'<table><thead><tr><th>Ticket</th><th>Status</th><th>Created</th><th>Reply</th>'
            .'</tr></thead><tbody>'.$body.'</tbody></table></section>';
    }

    private static function uuidV4():string
    {
        $b=random_bytes(16);
        $b[6]=chr((ord($b[6])&15)|64);
        $b[8]=chr((ord($b[8])&63)|128);
        $h=bin2hex($b);

        return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'
            .substr($h,16,4).'-'.substr($h,20);
    }
}