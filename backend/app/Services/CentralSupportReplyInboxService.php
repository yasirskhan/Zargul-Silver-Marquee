<?php

namespace App\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Atomic durable inbox for a vendor-signed support reply page.
 * Must never persist an unsigned page or move cursor before authenticating
 * pinned HAFZATECH Ed25519 proof. No public endpoint or scheduler attached.
 */
final class CentralSupportReplyInboxService
{
    public function __construct(private readonly CentralSignedSupportReplyPageVerifier $verifier) {}

    /** @return array{inserted:int,next_cursor:int} */
    public function accept(array $envelope,string $requestNonce,int $requestedCursor):array
    {
        if(config('central_control.support_enabled')!==true ||
            config('central_control.support_reply_pull_enabled')!==true) {
            throw new AuthorizationException('Vendor reply synchronization not enabled.');
        }
        return DB::transaction(function () use($envelope,$requestNonce,$requestedCursor):array {
            DB::select('SELECT pg_advisory_xact_lock(708116, 1119)');
            $binding=DB::table('central_installation_activation')
                ->where('singleton_id',1)->lockForUpdate()->first();
            if(!$binding||!$binding->company_id ||
                $binding->status!=='registered_pending_license') {
                throw new AuthorizationException('Trusted installation company binding required.');
            }
            $previous=DB::table('central_support_reply_cursor')
                ->where('singleton_id',1)->lockForUpdate()->first();
            $cursor=(int)($previous?->last_event_id??0);
            if($previous && ($previous->company_id!==$binding->company_id ||
                $previous->installation_id!==$binding->installation_id)) {
                throw new AuthorizationException('Support cursor bound to another installation.');
            }
            if($requestedCursor!==$cursor)
                throw new InvalidArgumentException('Stale or foreign support reply cursor.');
            $verified=$this->verifier->verify($envelope,$binding->customer_id,
                $binding->installation_id,$requestNonce,$cursor);
            $added=0;
            foreach($verified['messages'] as $entry){
                if(!Str::isUuid($entry['client_ticket_id']) ||
                    !Str::isUuid($entry['message_id']) ||
                    !is_string($entry['ticket_number']??null) ||
                    !preg_match('/^HAF-[0-9]{8,}$/D',$entry['ticket_number']) ||
                    !is_string($entry['occurred_at']??null) ||
                    !preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/D',$entry['occurred_at'])) {
                    throw new InvalidArgumentException('Invalid signed support event metadata.');
                }
                $ticket=DB::table('central_support_outbox')
                    ->where('company_id',$binding->company_id)
                    ->where('id',$entry['client_ticket_id'])
                    ->where('installation_id',$binding->installation_id)->first();
                if(!$ticket || $ticket->delivery_state!=='sent') {
                    throw new AuthorizationException('Signed support reply targets an unknown ticket.');
                }
                DB::table('central_support_reply_inbox')->insert([
                    'id'=>(string)Str::uuid(), 'company_id'=>$binding->company_id,
                    'installation_id'=>$binding->installation_id,
                    'vendor_event_id'=>$entry['event_id'],
                    'client_ticket_id'=>$entry['client_ticket_id'],
                    'vendor_message_id'=>$entry['message_id'],
                    'vendor_ticket_number'=>$entry['ticket_number'],
                    'encrypted_body'=>Crypt::encryptString($entry['body']),
                    'vendor_occurred_at'=>$entry['occurred_at'],
                    'created_at'=>now(),'updated_at'=>now(),
                ]);
                ++$added;
            }
            $values=['last_event_id'=>$verified['next_cursor'],'updated_at'=>now()];
            if($previous)
                DB::table('central_support_reply_cursor')->where('singleton_id',1)->update($values);
            else DB::table('central_support_reply_cursor')->insert([
                'singleton_id'=>1,'company_id'=>$binding->company_id,
                'installation_id'=>$binding->installation_id,'created_at'=>now()
            ]+$values);
            return ['inserted'=>$added,'next_cursor'=>$verified['next_cursor']];
        });
    }
}
