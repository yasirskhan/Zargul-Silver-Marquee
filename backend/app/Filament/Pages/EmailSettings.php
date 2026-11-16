<?php
namespace App\Filament\Pages;

use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\TenantEmailSettingsService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class EmailSettings extends Page
{
    protected string $view='filament.pages.email-settings';
    protected static string|BackedEnum|null $navigationIcon=Heroicon::OutlinedEnvelope;
    protected static ?string $navigationLabel='Email settings';
    protected static ?string $slug='email-settings';
    protected static string|\UnitEnum|null $navigationGroup='Configuration';

    #[Url(as:'company')] public ?string $companyId=null;
    #[Locked] public int $revision=0;
    public string $host='';public int $port=587;public string $username='';public string $password='';
    public string $encryption='tls';public string $fromAddress='';public string $fromName='';
    public bool $hasPassword=false;public string $testRecipient='';

    public static function canAccess():bool
    {
        $actor=Filament::auth()->user();
        return $actor instanceof User&&$actor->is_active&&$actor->hasVerifiedEmail()
            &&CompanyMembership::query()->where('user_id',$actor->id)->where('role','owner')
                ->where('is_active',true)->exists();
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
        $actor=Filament::auth()->user();abort_unless($actor instanceof User,403);
        abort_unless($this->companyId&&array_key_exists($this->companyId,$this->companyOptions()),403);
        $state=app(TenantEmailSettingsService::class)->state($actor,$this->companyId);
        $this->revision=(int)$state['revision'];$c=$state['config'];
        $this->host=(string)($c['host']??'');$this->port=(int)($c['port']??587);$this->username=(string)($c['username']??'');
        $this->encryption=(string)($c['encryption']??'tls');$this->fromAddress=(string)($c['fromAddress']??'');
        $this->fromName=(string)($c['fromName']??'');$this->hasPassword=(bool)($c['hasPassword']??false);$this->password='';
        if($this->fromAddress==='')$this->fromAddress=$actor->email??'';
    }
    public function save():void
    {
        $actor=Filament::auth()->user();abort_unless($actor instanceof User&&$this->companyId,403);
        try{$r=app(TenantEmailSettingsService::class)->save($actor,$this->companyId,[
            'host'=>$this->host,'port'=>$this->port,'username'=>$this->username,'password'=>$this->password,
            'encryption'=>$this->encryption,'fromAddress'=>$this->fromAddress,'fromName'=>$this->fromName,
        ],$this->revision);}catch(InvalidArgumentException|ConflictHttpException $e){
            Notification::make()->title('Email settings not saved')->body($e->getMessage())->danger()->send();return;}
        $this->revision=(int)$r['revision'];$this->load();Notification::make()->title('Email settings saved')->success()->send();
    }
    public function sendTest():void
    {
        $actor=Filament::auth()->user();abort_unless($actor instanceof User&&$this->companyId,403);
        $to=$this->testRecipient!==''?$this->testRecipient:(string)($actor->email??'');
        try{app(TenantEmailSettingsService::class)->sendTest($actor,$this->companyId,$to);}
        catch(InvalidArgumentException $e){Notification::make()->title('Test email not sent')->body($e->getMessage())->danger()->send();return;}
        Notification::make()->title('Test email attempted')->body('Check the recipient inbox or application log when SMTP is not configured.')->send();
    }
}
