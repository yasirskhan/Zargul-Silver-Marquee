<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\CentralSupportReplyInboxReader;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

/** Private owner/admin support inbox, local-only. No vendor polling on render. */
final class SupportInbox extends Page
{
    protected string $view = 'filament.pages.support-inbox';
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedEnvelope;
    protected static ?string $navigationLabel = 'HAFZATECH Support';
    protected static ?string $slug = 'hafzatech-support';

    #[Url(as: 'company')]
    public ?string $companyId = null;
    #[Locked]
    public array $messages = [];
    #[Locked]
    public int $unread = 0;

    private static function allowedCompanies(User $actor):array
    {
        if(!DB::table('users')->where('id',$actor->getKey())
            ->where('is_active',true)->whereNotNull('email_verified_at')->exists())return [];
        return DB::table('company_memberships as m')
            ->join('companies as c','c.id','=','m.company_id')
            ->join('central_installation_activation as a','a.company_id','=','c.id')
            ->where('m.user_id',$actor->getKey())
            ->where('m.is_active',true)->where('c.is_active',true)
            ->where('a.status','registered_pending_license')
            ->whereIn('m.role',['owner','admin'])
            ->orderBy('c.name')->pluck('c.name','c.id')->all();
    }

    public static function canAccess():bool
    {
        $actor=Filament::auth()->user();
        return $actor instanceof User && self::allowedCompanies($actor)!==[];
    }

    public function companyOptions():array
    {
        $actor=Filament::auth()->user();
        return $actor instanceof User ? self::allowedCompanies($actor) : [];
    }

    public function mount():void
    {
        abort_unless(self::canAccess(),403);
        $options=$this->companyOptions();
        $requested=$this->companyId??request()->query('company');
        abort_unless($requested===null||(is_string($requested)&&array_key_exists($requested,$options)),403);
        $this->companyId=$requested??array_key_first($options);
        $this->refreshMessages();
    }

    public function hydrate():void
    {
        abort_unless(self::canAccess() && $this->companyId!==null &&
            array_key_exists($this->companyId,$this->companyOptions()),403);
        $this->refreshMessages();
    }

    public function updatedCompanyId():void
    {
        $this->refreshMessages();
    }

    public function refreshMessages():void
    {
        $actor=Filament::auth()->user();
        abort_unless($actor instanceof User && self::canAccess() &&
            $this->companyId!==null &&
            array_key_exists($this->companyId,$this->companyOptions()),403);
        $reader=app(CentralSupportReplyInboxReader::class);
        $this->messages=$reader->list($actor,$this->companyId,25);
        $this->unread=$reader->unreadCount($actor,$this->companyId);
    }

    public function markMessageRead(string $messageId):void
    {
        $actor=Filament::auth()->user();
        abort_unless($actor instanceof User && self::canAccess() &&
            $this->companyId!==null &&
            array_key_exists($this->companyId,$this->companyOptions()),403);
        try {
            app(CentralSupportReplyInboxReader::class)
                ->markRead($actor,$this->companyId,$messageId);
        }catch(\Illuminate\Auth\Access\AuthorizationException){abort(403);}
        $this->refreshMessages();
    }
}
