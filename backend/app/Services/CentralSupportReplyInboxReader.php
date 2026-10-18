<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Authorized company-scoped support inbox and explicit read receipt.
 * No support body is exposed to another venue/company and no vendor network
 * or paid license verification is needed to read previously received help.
 */
final class CentralSupportReplyInboxReader
{
    public function __construct(private readonly CentralInstallationCompanyScope $scope) {}

    public function unreadCount(User $actor,string $companyId):int
    {
        $this->scope->assertCanRead($actor,$companyId);
        return DB::table('central_support_reply_inbox')
            ->where('company_id',$companyId)->whereNull('read_at')->count();
    }

    public function list(User $actor,string $companyId,int $limit=25,?int $beforeEventId=null,bool $unreadOnly=false):array
    {
        if($limit<1||$limit>50||($beforeEventId!==null && $beforeEventId<1))
            throw new InvalidArgumentException('Invalid support inbox page limit or cursor.');
        $this->scope->assertCanRead($actor,$companyId);
        $rows=DB::table('central_support_reply_inbox as inbox')
            ->leftJoin('central_support_reply_read_events as receipt',
                function($join) {
                    $join->on('receipt.message_id','=','inbox.id')
                        ->on('receipt.company_id','=','inbox.company_id');
                })
            ->leftJoin('users as read_actor','read_actor.id','=','receipt.actor_user_id')
            ->where('inbox.company_id',$companyId)
            ->when($unreadOnly,fn($q)=>$q->whereNull('inbox.read_at'))
            ->when($beforeEventId!==null,fn($q)=>$q->where('inbox.vendor_event_id','<',$beforeEventId))
            ->orderByDesc('inbox.vendor_event_id')->limit($limit)
            ->select('inbox.*','read_actor.name as read_by')->get();
        return $rows->map(static fn($r)=>[
            'id'=>$r->id, 'vendor_event_id'=>(int)$r->vendor_event_id,
            'client_ticket_id'=>$r->client_ticket_id,
            'ticket_number'=>$r->vendor_ticket_number,
            'body'=>Crypt::decryptString($r->encrypted_body),
            'received_at'=>$r->created_at,
            'read_at'=>$r->read_at, 'read_by'=>$r->read_by
        ])->all();
    }

    public function markRead(User $actor,string $companyId,string $messageId):bool
    {
        $this->scope->assertCanRead($actor,$companyId);
        if(!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',$messageId))
            throw new InvalidArgumentException('Invalid support message ID.');
        return DB::transaction(function()use($actor,$companyId,$messageId):bool{
            $this->scope->assertCanRead($actor,$companyId);
            $row=DB::table('central_support_reply_inbox')
                ->where('company_id',$companyId)->where('id',$messageId)
                ->lockForUpdate()->first();
            if(!$row)throw new AuthorizationException('Support reply is outside your company.');
            if($row->read_at!==null)return false;
            $readAt=now();
            DB::table('central_support_reply_inbox')->where('id',$messageId)
                ->where('company_id',$companyId)
                ->update(['read_at'=>$readAt,'updated_at'=>$readAt]);
            // Write one durable actor/timestamp audit event atomically with
            // the state transition; a failed insert rolls back both changes.
            DB::table('central_support_reply_read_events')->insert([
                'id'=>(string)\Illuminate\Support\Str::uuid(),
                'company_id'=>$companyId,
                'message_id'=>$messageId,
                'actor_user_id'=>$actor->getKey(),
                'read_at'=>$readAt,
            ]);
            return true;
        });
    }
}
