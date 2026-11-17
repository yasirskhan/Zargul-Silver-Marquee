<?php
namespace App\Filament\Pages;

use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\SecuritySettingsService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class SecuritySettings extends Page
{
    protected string $view='filament.pages.security-settings';
    protected static string|BackedEnum|null $navigationIcon=Heroicon::OutlinedShieldCheck;
    protected static ?string $navigationLabel='Security settings';
    protected static ?string $slug='security-settings';
    protected static string|\UnitEnum|null $navigationGroup='Configuration';

    #[Url(as:'company')] public ?string $companyId=null;
    #[Locked] public int $revision=0;
    public bool $mfaRequired=true;public int $trustedDeviceDays=30;

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
    private function load():void
    {
        $actor=Filament::auth()->user();abort_unless($actor instanceof User&&$this->companyId,403);
        $s=app(SecuritySettingsService::class)->state($actor,$this->companyId);
        $this->revision=(int)$s['revision'];$this->mfaRequired=(bool)$s['mfaRequired'];$this->trustedDeviceDays=(int)$s['trustedDeviceDays'];
    }
    public function save():void
    {
        $actor=Filament::auth()->user();abort_unless($actor instanceof User&&$this->companyId,403);
        try{$r=app(SecuritySettingsService::class)->save($actor,$this->companyId,$this->mfaRequired,$this->trustedDeviceDays,$this->revision);}
        catch(InvalidArgumentException|ConflictHttpException $e){Notification::make()->title('Security settings not saved')->body($e->getMessage())->danger()->send();return;}
        $this->revision=(int)$r['revision'];$this->load();Notification::make()->title('Security settings saved')->success()->send();
    }
    public function staffRows():array
    {
        $actor=Filament::auth()->user();if(!$actor instanceof User||!$this->companyId)return [];
        return app(SecuritySettingsService::class)->staff($actor,$this->companyId);
    }
    public function deviceRows():array
    {
        $actor=Filament::auth()->user();if(!$actor instanceof User||!$this->companyId)return [];
        return app(SecuritySettingsService::class)->devices($actor,$this->companyId);
    }
    public function setUserPolicy(int $userId,string $value):void
    {
        $actor=Filament::auth()->user();abort_unless($actor instanceof User&&$this->companyId,403);
        $required=match($value){'required'=>true,'optional'=>false,default=>null};
        app(SecuritySettingsService::class)->setUserMfa($actor,$this->companyId,$userId,$required);
        Notification::make()->title('Staff MFA policy updated')->success()->send();
    }
    public function trustThisDevice():void
    {
        $actor=Filament::auth()->user();abort_unless($actor instanceof User,403);
        app(SecuritySettingsService::class)->trustCurrentDevice($actor);
        Notification::make()->title('This device is now trusted')->body('Two-step authentication will be skipped on this device until it expires or is revoked.')->success()->send();
    }
    public function revokeDevice(string $id):void
    {
        $actor=Filament::auth()->user();abort_unless($actor instanceof User&&$this->companyId,403);
        app(SecuritySettingsService::class)->revoke($actor,$this->companyId,$id);
        Notification::make()->title('Trusted device revoked')->success()->send();
    }
}
