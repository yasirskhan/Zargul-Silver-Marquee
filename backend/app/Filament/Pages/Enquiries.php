<?php

namespace App\Filament\Pages;

use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\VenueEnquiryService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Locked;

/** Owner/admin-only triage of private public-website enquiries. */
final class Enquiries extends Page
{
    protected string $view = 'filament.pages.enquiries';

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedInbox;

    protected static ?string $navigationLabel = 'Enquiries';

    protected static ?string $slug = 'enquiries';

    public ?string $companyId = null;

    #[Locked]
    public array $rows = [];

    public array $reasons = [];

    public static function canAccess(): bool
    {
        $actor = Filament::auth()->user();

        return $actor instanceof User && $actor->is_active && $actor->hasVerifiedEmail()
            && static::visibleCompanies($actor) !== [];
    }

    private static function visibleCompanies(User $actor): array
    {
        $options = [];
        foreach (CompanyMembership::query()->with('company')
            ->where('user_id', $actor->getKey())->where('is_active', true)
            ->whereIn('role', ['owner', 'admin'])
            ->whereHas('company', fn ($query) => $query->where('is_active', true))
            ->orderBy('company_id')->get() as $membership) {
            $options[$membership->company_id] = $membership->company->name;
        }

        return $options;
    }

    public function companyOptions(): array
    {
        $actor = Filament::auth()->user();

        return $actor instanceof User ? static::visibleCompanies($actor) : [];
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->companyId = array_key_first($this->companyOptions());
        $this->refresh();
    }

    public function hydrate(): void
    {
        abort_unless(static::canAccess() && $this->companyId !== null
            && array_key_exists($this->companyId, $this->companyOptions()), 403);
    }

    public function updatedCompanyId(): void
    {
        $this->refresh();
    }

    public function refresh(): void
    {
        $actor = Filament::auth()->user();
        abort_unless($actor instanceof User && $this->companyId !== null
            && array_key_exists($this->companyId, $this->companyOptions()), 403);
        $this->rows = app(VenueEnquiryService::class)->list($actor, $this->companyId);
    }

    public function updateStatus(string $enquiryId, string $status): void
    {
        $actor = Filament::auth()->user();
        abort_unless($actor instanceof User && $this->companyId !== null
            && array_key_exists($this->companyId, $this->companyOptions()), 403);
        $reason = trim((string) ($this->reasons[$enquiryId] ?? ''));
        try {
            app(VenueEnquiryService::class)->updateStatus(
                $actor, $this->companyId, $enquiryId, $status, $reason,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'reasons.'.$enquiryId => $exception->getMessage(),
            ]);
        }
        $this->reasons[$enquiryId] = '';
        $this->refresh();
        Notification::make()->title('Enquiry status updated with audit history')->success()->send();
    }
}
