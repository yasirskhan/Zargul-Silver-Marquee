<?php
namespace App\Filament\Pages;

use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\TenantPaymentSettingsService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class PaymentSettings extends Page
{
    protected string $view='filament.pages.payment-settings';
    protected static string|BackedEnum|null $navigationIcon=Heroicon::OutlinedCreditCard;
    protected static ?string $navigationLabel='Payment settings';
    protected static ?string $slug='payment-settings';
    protected static string|\UnitEnum|null $navigationGroup='Configuration';

    #[Url(as:'company')] public ?string $companyId=null;
    #[Locked] public int $revision=0;
    public array $methods=[];
    public array $banks=[];
    public string $instructions='';
    public string $qrInstructions='';
    public int $depositPercent=0;

    public static function canAccess(): bool
    {
        $actor=Filament::auth()->user();
        return $actor instanceof User && $actor->is_active && $actor->hasVerifiedEmail()
            && CompanyMembership::query()->where('user_id',$actor->id)->where('role','owner')
                ->where('is_active',true)->exists();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(),403);
        $this->companyId=$this->companyId??array_key_first($this->companyOptions());
        $this->loadState();
    }

    public function companyOptions(): array
    {
        $actor=Filament::auth()->user();
        if(!$actor instanceof User)return [];
        return CompanyMembership::query()->where('user_id',$actor->id)->where('role','owner')
            ->where('is_active',true)->with('company')->get()
            ->mapWithKeys(fn($m)=>[$m->company_id=>$m->company->name])->all();
    }

    public function updatedCompanyId(): void { $this->loadState(); }

    private function loadState(): void
    {
        $actor=Filament::auth()->user(); abort_unless($actor instanceof User,403);
        abort_unless($this->companyId!==null&&array_key_exists($this->companyId,$this->companyOptions()),403);
        $state=app(TenantPaymentSettingsService::class)->state($actor,$this->companyId);
        $this->revision=(int)$state['revision'];
        $this->methods=$state['config']['methods']??[];
        $this->banks=array_values($state['config']['banks']??[]);
        if($this->banks===[])$this->banks=[['bankName'=>'','branch'=>'','accountMasked'=>'']];
        $this->instructions=(string)($state['config']['instructions']??'');
        $this->qrInstructions=(string)($state['config']['qrInstructions']??'');
        $this->depositPercent=(int)($state['config']['depositPercent']??0);
    }

    public function addBank(): void
    {
        if(count($this->banks)>=5)return;
        $this->banks[]=['bankName'=>'','branch'=>'','accountMasked'=>''];
    }

    public function removeBank(int $index): void
    {
        unset($this->banks[$index]);
        $this->banks=array_values($this->banks);
        if($this->banks===[])$this->banks=[['bankName'=>'','branch'=>'','accountMasked'=>'']];
    }

    public function save(): void
    {
        $actor=Filament::auth()->user(); abort_unless($actor instanceof User&&$this->companyId,403);
        try {
            $result=app(TenantPaymentSettingsService::class)->save($actor,$this->companyId,[
                'banks'=>array_values(array_filter($this->banks,fn($b)=>trim((string)($b['bankName']??''))!=='')),
                'methods'=>$this->methods,
                'instructions'=>$this->instructions,
                'qrInstructions'=>$this->qrInstructions,
                'depositPercent'=>$this->depositPercent,
                'providerApiEnabled'=>false,
            ],$this->revision);
        } catch (InvalidArgumentException|ConflictHttpException $e) {
            Notification::make()->title('Payment settings not saved')->body($e->getMessage())->danger()->send();
            return;
        }
        $this->revision=(int)$result['revision'];
        $this->loadState();
        DB::table('audit_events')->insert([
            'id'=>(string)\Illuminate\Support\Str::uuid(),'company_id'=>$this->companyId,
            'actor_id'=>$actor->getKey(),'action'=>'ui.payment_settings_saved',
            'entity_type'=>'tenant_payment_settings','entity_id'=>$this->companyId,
            'before_values'=>null,'after_values'=>json_encode(['revision'=>$this->revision,'methods'=>$this->methods],JSON_THROW_ON_ERROR),
            'context'=>json_encode(['source'=>'authenticated_payment_settings_page'],JSON_THROW_ON_ERROR),
        ]);
        Notification::make()->title('Payment settings saved')->success()->send();
    }
}
