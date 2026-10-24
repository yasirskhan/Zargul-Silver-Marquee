<?php

namespace App\Filament\Pages;

use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\InstallationWizardService;
use BackedEnum;
use DateTimeZone;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Locked;

/** Persistent owner-only first-run venue setup; no destructive re-initialization. */
final class VenueSetupWizard extends Page
{
    protected string $view = 'filament.pages.venue-setup-wizard';

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $navigationLabel = 'Venue setup';

    protected static ?string $slug = 'venue-setup';

    public ?string $companyId = null;

    public int $step = 1;

    #[Locked]
    public int $revision = 1;

    #[Locked]
    public string $status = 'draft';

    #[Locked]
    public ?string $completedAt = null;

    public array $company = [
        'legalName' => '', 'contactEmail' => '', 'contactPhone' => '',
        'address' => '', 'currency' => 'PKR', 'timezone' => 'Asia/Karachi',
    ];

    public array $halls = [
        ['id' => null, 'name' => '', 'address' => '', 'phone' => '', 'capacity' => 100],
    ];

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
            ->where('role', 'owner')
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

    public function timezones(): array
    {
        return DateTimeZone::listIdentifiers();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->companyId = array_key_first($this->companyOptions());
        $this->loadState();
    }

    public function hydrate(): void
    {
        abort_unless(static::canAccess() && $this->companyId !== null
            && array_key_exists($this->companyId, $this->companyOptions()), 403);
    }

    public function updatedCompanyId(): void
    {
        $this->loadState();
    }

    private function loadState(): void
    {
        $actor = Filament::auth()->user();
        abort_unless($actor instanceof User && $this->companyId !== null, 403);
        $state = app(InstallationWizardService::class)->state($actor, $this->companyId);
        $this->step = $state['step'];
        $this->revision = $state['revision'];
        $this->status = $state['status'];
        $this->completedAt = $state['completedAt'];
        $this->company = array_merge($this->company, $state['data']['company'] ?? []);
        $halls = $state['data']['halls'] ?? [];
        if (is_array($halls) && $halls !== []) {
            $this->halls = array_values($halls);
        }
    }

    public function addHall(): void
    {
        abort_if($this->status === 'completed' || count($this->halls) >= 10, 403);
        $this->halls[] = ['id' => null, 'name' => '', 'address' => '', 'phone' => '', 'capacity' => 100];
    }

    public function removeHall(int $index): void
    {
        abort_if($this->status === 'completed' || count($this->halls) <= 1, 403);
        unset($this->halls[$index]);
        $this->halls = array_values($this->halls);
    }

    public function back(): void
    {
        $this->step = max(1, $this->step - 1);
    }

    public function saveAndContinue(): void
    {
        abort_if($this->status === 'completed', 403);
        $actor = Filament::auth()->user();
        abort_unless($actor instanceof User && $this->companyId !== null, 403);
        $this->validate($this->rulesForStep());
        try {
            $data = $this->step === 1 ? ['company' => $this->company] : ['halls' => $this->halls];
            $state = app(InstallationWizardService::class)->save(
                $actor, $this->companyId, $this->step, $data, $this->revision,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['wizard' => $exception->getMessage()]);
        }
        $this->applyState($state);
        Notification::make()->title('Installation step saved')->success()->send();
    }

    public function complete(): void
    {
        abort_if($this->status === 'completed', 403);
        $actor = Filament::auth()->user();
        abort_unless($actor instanceof User && $this->companyId !== null, 403);
        try {
            $state = app(InstallationWizardService::class)->complete(
                $actor, $this->companyId, $this->revision,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['wizard' => $exception->getMessage()]);
        }
        $this->applyState($state);
        Notification::make()->title('Venue setup completed and audited')->success()->send();
    }

    private function applyState(array $state): void
    {
        $this->step = $state['step'];
        $this->revision = $state['revision'];
        $this->status = $state['status'];
        $this->completedAt = $state['completedAt'];
        $this->company = array_merge($this->company, $state['data']['company'] ?? []);
        if (is_array($state['data']['halls'] ?? null) && $state['data']['halls'] !== []) {
            $this->halls = array_values($state['data']['halls']);
        }
    }

    private function rulesForStep(): array
    {
        if ($this->step === 1) {
            return [
                'company.legalName' => ['required', 'string', 'min:2', 'max:160'],
                'company.contactEmail' => ['nullable', 'email', 'max:254'],
                'company.contactPhone' => ['nullable', 'string', 'max:32'],
                'company.address' => ['nullable', 'string', 'max:240'],
                'company.currency' => ['required', 'regex:/^[A-Z]{3}$/'],
                'company.timezone' => ['required', 'in:'.implode(',', DateTimeZone::listIdentifiers())],
            ];
        }

        return [
            'halls' => ['required', 'array', 'min:1', 'max:10'],
            'halls.*.name' => ['required', 'string', 'min:2', 'max:120'],
            'halls.*.address' => ['nullable', 'string', 'max:240'],
            'halls.*.phone' => ['nullable', 'string', 'max:32'],
            'halls.*.capacity' => ['required', 'integer', 'min:1', 'max:100000'],
        ];
    }
}
