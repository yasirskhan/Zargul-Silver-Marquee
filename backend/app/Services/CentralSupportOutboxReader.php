<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Company-scoped, local-only history of customer-authored HAFZATECH support
 * requests. Readable while delivery is disabled/offline. No venue or vendor
 * HTTP access, no plaintext support message body in list or audit.
 */
final class CentralSupportOutboxReader
{
    public function __construct(private readonly CentralInstallationCompanyScope $scope) {}

    public function list(User $actor,string $companyId,int $limit=20,?string $beforeTime=null,?string $beforeId=null):array
    {
        if($limit<1 || $limit>50)
            throw new InvalidArgumentException('Invalid support request history limit.');
        if(($beforeTime===null)!==($beforeId===null) ||
           ($beforeTime!==null && (!preg_match(
               '/^\d{4}-\d\d-\d\d[ T]\d\d:\d\d:\d\d(?:\.\d{1,6})?(?:Z|[+-]\d\d(?::?\d\d)?)?$/D',
               $beforeTime) ||
               !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',$beforeId)))) {
            throw new InvalidArgumentException('Invalid support request history cursor.');
        }
        $this->scope->assertCanRead($actor,$companyId);
        $binding=DB::table('central_installation_activation')
            ->where('singleton_id',1)->first();
        // Defense in depth even if a future migration allows company-bound
        // multiple installation histories.
        $rows=DB::table('central_support_outbox')
            ->where('company_id',$companyId)
            ->where('installation_id',$binding->installation_id)
            ->when($beforeTime!==null,function($q)use($beforeTime,$beforeId){
                $q->where(function($cursor)use($beforeTime,$beforeId){
                    $cursor->where('created_at','<',$beforeTime)
                        ->orWhere(function($same)use($beforeTime,$beforeId){
                            $same->where('created_at','=',$beforeTime)
                                ->where('id','<',$beforeId);
                        });
                });
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
        return $rows->map(static fn($row)=>[
            'id'=>$row->id,
            'subject'=>Crypt::decryptString($row->encrypted_subject),
            'state'=>match($row->delivery_state){
                'queued'=>'Queued locally (not sent)',
                'sent'=>'Delivered to HAFZATECH',
                default=>'Delivery needs attention',
            },
            'ticket_number'=>$row->central_ticket_number,
            'created_at'=>$row->created_at,
            'delivered_at'=>$row->delivered_at,
        ])->all();
    }
}
