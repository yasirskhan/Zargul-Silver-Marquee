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

    public function list(User $actor,string $companyId,int $limit=25):array
    {
        if($limit<1||$limit>50)throw new InvalidArgumentException('Invalid support inbox page limit.');
        $this->scope->assertCanRead($actor,$companyId);
        $rows=DB::table('central_support_reply_inbox')
            ->where('company_id',$companyId)
            ->orderByDesc('vendor_event_id')->limit($limit)->get();
        return $rows->map(static fn($r)=>[
            'id'=>$r->id, 'client_ticket_id'=>$r->client_ticket_id,
            'ticket_number'=>$r->vendor_ticket_number,
            'body'=>Crypt::decryptString($r->encrypted_body),
            'received_at'=>$r->created_at,
            'read_at'=>$r->read_at
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
