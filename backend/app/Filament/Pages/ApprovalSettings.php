<?php
namespace App\Filament\Pages;

use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\ApprovalWorkflowService;
use App\Services\DiscountLimitService;
use App\Services\TelegramApprovalAlertService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

final class ApprovalSettings extends Page
{
    protected string $view='filament.pages.approval-settings';
    protected static string|BackedEnum|null $navigationIcon=Heroicon::OutlinedAdjustmentsHorizontal;
    protected static ?string $navigationLabel='Approval settings';
    protected static ?string $slug='approval-settings';
    protected static string|\UnitEnum|null $navigationGroup='Configuration';

    #[Url(as:'company')] public ?string $companyId=null;
    public array $limits=['owner'=>0,'admin'=>0,'manager'=>0];
    public string $grantAdminId='';public string $grantCeiling='';public string $grantStarts='';public string $grantEnds='';
    public string $grantReason='';public string $revokeReason='';public ?string $telegramUrl=null;

    public static function canAccess():bool
    {
        $actor=Filament::auth()->user();
        return $actor instanceof User&&$actor->is_active&&$actor->hasVerifiedEmail()
            &&CompanyMembership::query()->where('user_id',$actor->id)->where('role','owner')->where('is_active',true)->exists();
    }
    public function mount():void{abort_unless(static::canAccess(),403);$this->companyId=$this->companyId??array_key_first($this->companyOptions());$this->load();}
    public function companyOptions():array
    {
        $actor=Filament::auth()->user();if(!$actor instanceof User)return [];
        return CompanyMembership::query()->where('user_id',$actor->id)->where('role','owner')->where('is_active',true)
            ->with('company')->get()->mapWithKeys(fn($m)=>[$m->company_id=>$m->company->name])->all();
    }
    public function updatedCompanyId():void{$this->load();}
    private function actor():User{$u=Filament::auth()->user();abort_unless($u instanceof User&&$this->companyId,403);return $u;}
    private function load():void{$raw=app(DiscountLimitService::class)->state($this->actor(),$this->companyId)['limits'];
        $this->limits=array_map(fn($v)=>(float)$v/100,$raw);}
    public function saveLimits():void
    {
        app(DiscountLimitService::class)->save($this->actor(),$this->companyId,array_map(fn($v)=>(int)round((float)$v*100),$this->limits));
        $this->load();Notification::make()->title('Discount limits saved')->success()->send();
    }
    public function admins():array
    {
        if(!$this->companyId)return [];
        return CompanyMembership::query()->where('company_id',$this->companyId)->where('role','admin')->where('is_active',true)
            ->with('user')->get()->map(fn($m)=>['id'=>$m->user_id,'name'=>$m->user->name])->all();
    }
    public function delegations():array
    {
        try{return app(ApprovalWorkflowService::class)->activeDelegations($this->actor(),$this->companyId);}catch(\Throwable){return [];}
    }
    public function grant():void
    {
        foreach(['grantAdminId','grantCeiling','grantStarts','grantEnds','grantReason'] as $f)if(trim((string)$this->{$f})===''){$this->addError($f,'Required.');return;}
        try{
            app(ApprovalWorkflowService::class)->grant($this->actor(),$this->companyId,(string)Str::uuid(),(int)$this->grantAdminId,
                ['discount','refund'],(int)round((float)$this->grantCeiling*100),
                (string)$this->grantStarts,(string)$this->grantEnds,$this->grantReason);
        }catch(\Throwable $e){Notification::make()->title('Delegation not granted')->body($e->getMessage())->danger()->send();return;}
        $this->grantAdminId='';$this->grantCeiling='';$this->grantStarts='';$this->grantEnds='';$this->grantReason='';
        Notification::make()->title('Delegation granted')->success()->send();
    }
    public function revokeDelegation(string $id):void
    {
        try{app(ApprovalWorkflowService::class)->revoke($this->actor(),$this->companyId,$id,$this->revokeReason!==''?$this->revokeReason:'Owner revoked delegation');}
        catch(\Throwable $e){Notification::make()->title('Delegation not revoked')->body($e->getMessage())->danger()->send();return;}
        Notification::make()->title('Delegation revoked')->success()->send();
    }
    public function telegramConfigured():bool{return config('approval_alerts.enabled')===true;}
    public function telegramConnected():bool{$a=$this->actor();return app(TelegramApprovalAlertService::class)->hasActiveLink($a,$this->companyId);}
    public function beginTelegram():void
    {
        try{$start=app(TelegramApprovalAlertService::class)->beginPairing($this->actor(),$this->companyId);$this->telegramUrl=$start['url'];}
        catch(\Throwable $e){Notification::make()->title('Telegram pairing unavailable')->body($e->getMessage())->danger()->send();}
    }
    public function revokeTelegram():void
    {
        try{app(TelegramApprovalAlertService::class)->revoke($this->actor(),$this->companyId);$this->telegramUrl=null;}
        catch(\Throwable $e){Notification::make()->title('Telegram revocation failed')->body($e->getMessage())->danger()->send();return;}
        Notification::make()->title('Telegram alerts revoked')->success()->send();
    }
}
