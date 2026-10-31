<?php

namespace App\Filament\Pages;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\User;
use App\Services\HallGuestPricingService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class HallPricingTiers extends Page
{
    protected string $view = 'filament.pages.hall-pricing-tiers';
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedBanknotes;
    protected static ?string $navigationLabel = 'Guest pricing';
    protected static ?string $slug = 'guest-pricing';

    #[Url(as: 'company')]
    public ?string $companyId = null;

    public ?string $hallId = null;
    public array $tiers = [];
    public string $currencyCode = 'PKR';
    public string $reason = '';
    #[Locked]
    public int $expectedRevision = 0;

    public function mount(): void
    {
        $this->companyId = array_key_first($this->companyOptions());
        $this->hallId = array_key_first($this->hallOptions());
        $this->loadPricing();
    }

    public function updatedCompanyId(): void
    {
        $this->hallId = array_key_first($this->hallOptions());
        $this->loadPricing();
    }

    public function updatedHallId(): void
    {
        $this->loadPricing();
    }

    public function addTier(): void
    {
        $last = $this->tiers === [] ? 0 : (int) max(array_column($this->tiers, 'maxGuests'));
        $this->tiers[] = ['minGuests' => $last + 1, 'maxGuests' => $last + 50,
            'priceMinor' => 0];
    }

    public function removeTier(int $index): void
    {
        unset($this->tiers[$index]);
        $this->tiers = array_values($this->tiers);
    }

    public function save(): void
    {
        $actor = Filament::auth()->user();
        abort_unless($actor instanceof User && $this->companyId && $this->hallId, 403);
        $this->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'currencyCode' => ['required', 'regex:/^[A-Z]{3}$/'],
            'tiers' => ['required', 'array', 'min:1', 'max:50'],
            'tiers.*.minGuests' => ['required', 'integer', 'min:1', 'max:100000'],
            'tiers.*.maxGuests' => ['required', 'integer', 'min:1', 'max:100000'],
            'tiers.*.priceMinor' => ['required', 'integer', 'min:0'],
        ]);
        try {
            $this->expectedRevision = app(HallGuestPricingService::class)->configure(
                $actor, $this->companyId, $this->hallId, $this->tiers,
                $this->currencyCode, $this->expectedRevision, $this->reason,
            );
            $this->reason = '';
            Notification::make()->title('Guest pricing revision published')->success()->send();
            $this->loadPricing();
        } catch (ConflictHttpException $e) {
            $this->addError('tiers', $e->getMessage());
        } catch (\InvalidArgumentException $e) {
            $this->addError('tiers', $e->getMessage());
        }
    }

    public function companyOptions(): array
    {
        $actor = Filament::auth()->user();
        if (! $actor instanceof User) return [];
        return CompanyMembership::query()->where('user_id', $actor->id)
            ->where('is_active', true)->whereHas('company', fn ($q) => $q->where('is_active', true))
            ->with('company')->get()->mapWithKeys(fn ($m) => [$m->company_id => $m->company->name])->all();
    }

    public function hallOptions(): array
    {
        if (! $this->companyId) return [];
        return Hall::query()->where('company_id', $this->companyId)
            ->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all();
    }

    private function loadPricing(): void
    {
        $this->tiers = [];
        $this->expectedRevision = 0;
        if (! $this->companyId || ! $this->hallId) return;
        $hall = DB::table('halls')->where('company_id', $this->companyId)
            ->where('id', $this->hallId)->first(['guest_pricing_revision']);
        $this->expectedRevision = (int) ($hall->guest_pricing_revision ?? 0);
        if ($this->expectedRevision < 1) return;
        $revision = DB::table('hall_guest_pricing_revisions')
            ->where('company_id', $this->companyId)->where('hall_id', $this->hallId)
            ->where('revision_number', $this->expectedRevision)->first();
        if ($revision) {
            $this->tiers = json_decode($revision->tiers, true, 32, JSON_THROW_ON_ERROR);
            $this->currencyCode = $revision->currency_code;
        }
    }
}