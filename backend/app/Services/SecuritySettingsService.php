<?php
namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class SecuritySettingsService
{
    public const COOKIE='zargul_trusted_device';

    public function state(User $actor,string $companyId):array
    {
        $this->authorize($actor,$companyId);
        $row=DB::table('tenant_security_settings')->where('company_id',$companyId)->first();
        return ['revision'=>$row?(int)$row->revision:0,'mfaRequired'=>$row?(bool)$row->mfa_required:true,
            'trustedDeviceDays'=>$row?(int)$row->trusted_device_days:30];
    }

    public function save(User $actor,string $companyId,bool $mfaRequired,int $days,int $expectedRevision):array
    {
        $this->authorize($actor,$companyId);
        if($days<1||$days>365)throw new InvalidArgumentException('Trusted-device expiry must be 1-365 days.');
        return DB::transaction(function()use($actor,$companyId,$mfaRequired,$days,$expectedRevision):array{
            $row=DB::table('tenant_security_settings')->where('company_id',$companyId)->lockForUpdate()->first();
            $live=$row?(int)$row->revision:0;
            if($live!==$expectedRevision)throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException('Security settings changed; reload.');
            $next=$live+1;
            if($row)DB::table('tenant_security_settings')->where('company_id',$companyId)->update(['mfa_required'=>$mfaRequired,'trusted_device_days'=>$days,'revision'=>$next,'updated_by'=>$actor->getKey(),'updated_at'=>now()]);
            else DB::table('tenant_security_settings')->insert(['id'=>(string)Str::uuid(),'company_id'=>$companyId,'mfa_required'=>$mfaRequired,'trusted_device_days'=>$days,'revision'=>$next,'updated_by'=>$actor->getKey(),'created_at'=>now(),'updated_at'=>now()]);
            $this->audit($actor,$companyId,'tenant.security_settings_updated','tenant_security_settings',$companyId,['mfaRequired'=>$mfaRequired,'trustedDeviceDays'=>$days,'revision'=>$next]);
            return ['revision'=>$next];
        },3);
    }

    public function requiresMfa(User $user,string $companyId):bool
    {
        if($user->mfa_required!==null)return (bool)$user->mfa_required;
        $row=DB::table('tenant_security_settings')->where('company_id',$companyId)->first(['mfa_required']);
        return $row?(bool)$row->mfa_required:true;
    }

    public function setUserMfa(User $owner,string $companyId,int $userId,?bool $required):void
    {
        $this->authorize($owner,$companyId);
        abort_unless(DB::table('company_memberships')->where('company_id',$companyId)->where('user_id',$userId)->where('is_active',true)->exists(),403);
        DB::table('users')->where('id',$userId)->update(['mfa_required'=>$required,'updated_at'=>now()]);
        if($required===false)DB::table('trusted_devices')->where('user_id',$userId)->whereNull('revoked_at')->update(['revoked_at'=>now(),'updated_at'=>now()]);
        $this->audit($owner,$companyId,'tenant.user_mfa_policy_updated','user',$userId,['userId'=>$userId,'required'=>$required]);
    }

    public function staff(User $owner,string $companyId):array
    {
        $this->authorize($owner,$companyId);
        return DB::table('company_memberships as m')->join('users as u','u.id','=','m.user_id')
            ->where('m.company_id',$companyId)->where('m.is_active',true)->orderBy('u.name')
            ->get(['u.id','u.name','u.email','u.mfa_required','u.app_authentication_secret'])
            ->map(fn($r)=>['id'=>(int)$r->id,'name'=>$r->name,'email'=>$r->email,
                'override'=>$r->mfa_required===null?null:(bool)$r->mfa_required,
                'enrolled'=>filled($r->app_authentication_secret)])->all();
    }

    public function trustCurrentDevice(User $user):void
    {
        $companyId=$this->companyFor($user);if(!$companyId)return;
        $days=max(1,min(365,(int)(DB::table('tenant_security_settings')->where('company_id',$companyId)->value('trusted_device_days')??30)));
        $token=bin2hex(random_bytes(32));$id=(string)Str::uuid();
        DB::table('trusted_devices')->insert(['id'=>$id,'user_id'=>$user->getKey(),'token_hash'=>hash('sha256',$token),
            'user_agent_hash'=>$this->uaHash(request()),'expires_at'=>now()->addDays($days),
            'last_used_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
        Cookie::queue(self::COOKIE,$id.':'.$token,$days*1440,'/',null,request()->isSecure(),true,false,'lax');
    }

    public function isTrusted(?User $user,?Request $request=null):bool
    {
        if(!$user)return false;$request=$request??request();
        $raw=$request->cookie(self::COOKIE);
        if(!is_string($raw)||!str_contains($raw,':'))return false;
        [$id,$token]=explode(':',$raw,2);
        if(!Str::isUuid($id)||strlen($token)<32)return false;
        $row=DB::table('trusted_devices')->where('id',$id)->where('user_id',$user->getKey())
            ->whereNull('revoked_at')->where('expires_at','>',now())->first();
        if(!$row||!hash_equals((string)$row->token_hash,hash('sha256',$token)))return false;
        if(!$row->last_used_at||now()->diffInMinutes($row->last_used_at)>=60){
            DB::table('trusted_devices')->where('id',$id)->update(['last_used_at'=>now(),'updated_at'=>now()]);
        }
        return true;
    }

    public function devices(User $actor,string $companyId):array
    {
        $this->authorize($actor,$companyId);
        $ids=DB::table('company_memberships')->where('company_id',$companyId)->where('is_active',true)->pluck('user_id');
        return DB::table('trusted_devices as d')->join('users as u','u.id','=','d.user_id')
            ->whereIn('d.user_id',$ids)->orderByDesc('d.created_at')->limit(100)
            ->get(['d.id','u.name','u.email','d.expires_at','d.last_used_at','d.revoked_at'])
            ->map(fn($r)=>['id'=>$r->id,'name'=>$r->name,'email'=>$r->email,'expiresAt'=>$r->expires_at,
                'lastUsedAt'=>$r->last_used_at,'revokedAt'=>$r->revoked_at])->all();
    }

    public function revoke(User $actor,string $companyId,string $deviceId):void
    {
        $this->authorize($actor,$companyId);
        $ids=DB::table('company_memberships')->where('company_id',$companyId)->where('is_active',true)->pluck('user_id');
        $changed=DB::table('trusted_devices')->where('id',$deviceId)->whereIn('user_id',$ids)->whereNull('revoked_at')
            ->update(['revoked_at'=>now(),'updated_at'=>now()]);
        if($changed!==1)throw new AuthorizationException('Trusted device is outside this company.');
        $this->audit($actor,$companyId,'tenant.trusted_device_revoked','trusted_device',$deviceId,['revoked'=>true]);
    }

    public function companyFor(User $user):?string
    {
        $id=DB::table('company_memberships')->where('user_id',$user->getKey())->where('is_active',true)
            ->orderByRaw("CASE WHEN role='owner' THEN 0 WHEN role='admin' THEN 1 ELSE 2 END")->value('company_id');
        return is_string($id)?$id:null;
    }

    private function uaHash(Request $r):string{return hash('sha256',substr((string)$r->userAgent(),0,500));}

    private function audit(User $actor,string $companyId,string $action,string $type,string $id,array $after):void
    {
        DB::table('audit_events')->insert(['id'=>(string)Str::uuid(),'company_id'=>$companyId,'actor_id'=>$actor->getKey(),
            'action'=>$action,'entity_type'=>$type,'entity_id'=>$id,'before_values'=>null,
            'after_values'=>json_encode($after,JSON_THROW_ON_ERROR),'context'=>json_encode([],JSON_THROW_ON_ERROR)]);
    }

    private function authorize(User $actor,string $companyId):void
    {
        if(!$actor->is_active||!$actor->hasVerifiedEmail()||!DB::table('company_memberships')->where('company_id',$companyId)
            ->where('user_id',$actor->getKey())->where('role','owner')->where('is_active',true)->exists())
            throw new AuthorizationException('Active verified owner required.');
    }
}
