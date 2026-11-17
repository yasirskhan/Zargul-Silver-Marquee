<?php
namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class DiscountLimitService
{
    public const ROLES=['owner','admin','manager'];

    public function state(User $actor,string $companyId):array
    {
        $this->authorize($actor,$companyId);
        $rows=DB::table('tenant_discount_limits')->where('company_id',$companyId)->pluck('max_percent_bps','role');
        $limits=[];foreach(self::ROLES as $role)$limits[$role]=(int)($rows[$role]??0);
        return ['limits'=>$limits];
    }

    public function save(User $actor,string $companyId,array $limits):void
    {
        $this->authorize($actor,$companyId);
        $clean=[];
        foreach(self::ROLES as $role){
            $bps=filter_var($limits[$role]??0,FILTER_VALIDATE_INT);
            if($bps===false||$bps<0||$bps>10000)throw new InvalidArgumentException('Discount limits must be 0-100%.');
            $clean[$role]=(int)$bps;
        }
        DB::transaction(function()use($actor,$companyId,$clean):void{
            foreach($clean as $role=>$bps){
                DB::table('tenant_discount_limits')->updateOrInsert(
                    ['company_id'=>$companyId,'role'=>$role],
                    ['id'=>(string)Str::uuid(),'max_percent_bps'=>$bps,'updated_by'=>$actor->getKey(),'created_at'=>now(),'updated_at'=>now()]
                );
            }
            DB::table('audit_events')->insert(['id'=>(string)Str::uuid(),'company_id'=>$companyId,'actor_id'=>$actor->getKey(),
                'action'=>'tenant.discount_limits_updated','entity_type'=>'tenant_discount_limits','entity_id'=>$companyId,
                'before_values'=>null,'after_values'=>json_encode($clean,JSON_THROW_ON_ERROR),'context'=>json_encode([],JSON_THROW_ON_ERROR)]);
        },3);
    }

    public function limitBps(string $companyId,string $role):int
    {
        return (int)(DB::table('tenant_discount_limits')->where('company_id',$companyId)->where('role',$role)->value('max_percent_bps')??0);
    }

    public function requiresApproval(string $companyId,string $role,int $discountMinor,int $subtotalMinor):bool
    {
        if($discountMinor<=0||$subtotalMinor<=0)return true;
        $bps=(int)ceil($discountMinor*10000/$subtotalMinor);
        return $bps>$this->limitBps($companyId,$role);
    }

    private function authorize(User $actor,string $companyId):void
    {
        if(!$actor->is_active||!$actor->hasVerifiedEmail()||!DB::table('company_memberships')->where('company_id',$companyId)
            ->where('user_id',$actor->getKey())->where('role','owner')->where('is_active',true)->exists())
            throw new AuthorizationException('Active verified owner required.');
    }
}
