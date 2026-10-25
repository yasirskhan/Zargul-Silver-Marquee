<?php

namespace App\Filament\Pages;

use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\InstallationWizardService;
use App\Support\Iso4217;
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

    /** @var array<string,array<string,array<string,mixed>>> */
    public array $programs = [];

    /** @var array<string,array<string,int>> */
    public array $pricing = [];

    /** @var array<string,array<int,array<string,mixed>>> */
    public array $addons = [];

    public array $branding = [
        'siteName' => '', 'tagline' => '', 'themePreset' => 'silver',
        'fontPreset' => 'classic', 'footerText' => '', 'seoDescription' => '',
    ];

    public array $payments = [
        'banks' => [], 'instructions' => '', 'qrInstructions' => '',
        'methods' => [], 'depositPercent' => 0, 'providerApiEnabled' => false,
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
        $this->hydrateOperationalInputs($state['data']);
    }

    private function hydrateOperationalInputs(array $data): void
    {
        $defaults = [];
        foreach ($this->halls as $hall) {
            $id = (string) ($hall['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $slot = static fn (string $start, string $end): array => [
                'start' => $start, 'end' => $end, 'setupMinutes' => 20,
                'cleaningMinutes' => 20, 'graceMinutes' => 15,
                'overtimeIntervalMinutes' => 30, 'overtimeRateMinor' => 0,
                'rentalRateMinor' => 0,
            ];
            $defaults[$id] = [
                'morning' => $slot('08:00', '11:00'),
                'noon' => $slot('12:00', '15:00'),
                'evening' => $slot('17:00', '22:00'),
            ];
            $this->pricing[$id] = $this->pricing[$id] ?? [
                'capacity' => (int) $hall['capacity'],
                'includedChairs' => 0, 'includedTables' => 0,
                'extraChairRateMinor' => 0, 'extraTableRateMinor' => 0,
            ];
            $this->addons[$id] = $this->addons[$id] ?? [[
                'code' => 'catering_standard', 'name' => 'Catering package',
                'category' => 'catering', 'chargeUnit' => 'flat',
                'rateMinor' => 0, 'enabled' => true,
            ]];
        }
        foreach (($data['pricing'] ?? []) as $hallId => $saved) {
            if (isset($saved['config'])) {
                $this->pricing[$hallId] = $saved['config'];
            }
        }
        foreach (($data['addons'] ?? []) as $hallId => $saved) {
            if (isset($saved['items']) && is_array($saved['items'])) {
                $this->addons[$hallId] = array_values($saved['items']);
            }
        }
        foreach (($data['programs'] ?? []) as $hallId => $saved) {
            if (isset($saved['rules'])) {
                $defaults[$hallId] = $saved['rules'];
            }
        }
        $this->programs = $defaults;
        if (is_array($data['branding'] ?? null)) {
            $this->branding = array_merge($this->branding, $data['branding']);
        }
        if (is_array($data['payments'] ?? null)) {
            $this->payments = array_merge($this->payments, $data['payments']);
        }
    }

    public function addAddon(string $hallId): void
    {
        abort_if($this->status === 'completed' || ! isset($this->addons[$hallId])
            || count($this->addons[$hallId]) >= 20, 403);
        $this->addons[$hallId][] = [
            'code' => '', 'name' => '', 'category' => 'catering',
            'chargeUnit' => 'flat', 'rateMinor' => 0, 'enabled' => true,
        ];
    }

    public function removeAddon(string $hallId, int $index): void
    {
        abort_if($this->status === 'completed' || ! isset($this->addons[$hallId][$index]), 403);
        unset($this->addons[$hallId][$index]);
        $this->addons[$hallId] = array_values($this->addons[$hallId]);
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
            $service = app(InstallationWizardService::class);
            $state = match ($this->step) {
                1, 2 => $service->save(
                    $actor, $this->companyId, $this->step,
                    $this->step === 1 ? ['company' => $this->company] : ['halls' => $this->halls],
                    $this->revision,
                ),
                3 => $service->savePrograms($actor, $this->companyId, $this->programs, $this->revision),
                4 => $service->savePricing($actor, $this->companyId, $this->pricing, $this->revision),
                5 => $service->saveAddons($actor, $this->companyId, $this->addons, $this->revision),
                6 => $service->saveBranding($actor, $this->companyId, $this->branding, $this->revision),
                7 => $service->savePayments($actor, $this->companyId, $this->payments, $this->revision),
                default => throw new InvalidArgumentException('Review the saved setup before completing.'),
            };
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
        $this->hydrateOperationalInputs($state['data']);
    }

    private function rulesForStep(): array
    {
        return match ($this->step) {
            1 => [
                'company.legalName' => ['required', 'string', 'min:2', 'max:160'],
                'company.contactEmail' => ['nullable', 'email', 'max:254'],
                'company.contactPhone' => ['nullable', 'string', 'max:32'],
                'company.address' => ['nullable', 'string', 'max:240'],
                'company.currency' => ['required', 'in:'.implode(',', array_keys(Iso4217::all()))],
                'company.timezone' => ['required', 'in:'.implode(',', DateTimeZone::listIdentifiers())],
            ],
            2 => [
                'halls' => ['required', 'array', 'min:1', 'max:10'],
                'halls.*.name' => ['required', 'string', 'min:2', 'max:120'],
                'halls.*.address' => ['nullable', 'string', 'max:240'],
                'halls.*.phone' => ['nullable', 'string', 'max:32'],
                'halls.*.capacity' => ['required', 'integer', 'min:1', 'max:100000'],
            ],
            3 => [
                'programs' => ['required', 'array', 'min:1'],
                'programs.*.morning.start' => ['required', 'date_format:H:i'],
                'programs.*.morning.end' => ['required', 'date_format:H:i'],
                'programs.*.noon.start' => ['required', 'date_format:H:i'],
                'programs.*.noon.end' => ['required', 'date_format:H:i'],
                'programs.*.evening.start' => ['required', 'date_format:H:i'],
                'programs.*.evening.end' => ['required', 'date_format:H:i'],
                'programs.*.*.setupMinutes' => ['required', 'integer', 'min:0', 'max:720'],
                'programs.*.*.cleaningMinutes' => ['required', 'integer', 'min:0', 'max:720'],
                'programs.*.*.graceMinutes' => ['required', 'integer', 'min:0', 'max:120'],
                'programs.*.*.overtimeIntervalMinutes' => ['required', 'integer', 'min:1', 'max:120'],
                'programs.*.*.rentalRateMinor' => ['required', 'integer', 'min:0'],
                'programs.*.*.overtimeRateMinor' => ['required', 'integer', 'min:0'],
            ],
            4 => [
                'pricing' => ['required', 'array', 'min:1'],
                'pricing.*.capacity' => ['required', 'integer', 'min:1', 'max:100000'],
                'pricing.*.includedChairs' => ['required', 'integer', 'min:0', 'max:100000'],
                'pricing.*.includedTables' => ['required', 'integer', 'min:0', 'max:100000'],
                'pricing.*.extraChairRateMinor' => ['required', 'integer', 'min:0'],
                'pricing.*.extraTableRateMinor' => ['required', 'integer', 'min:0'],
            ],
            5 => [
                'addons' => ['required', 'array'],
                'addons.*' => ['array'],
                'addons.*.*.code' => ['required', 'string', 'min:2', 'max:40'],
                'addons.*.*.name' => ['required', 'string', 'min:2', 'max:120'],
                'addons.*.*.category' => ['required', 'in:catering,music,lighting,bridal_entry,stage,photography,other'],
                'addons.*.*.chargeUnit' => ['required', 'in:flat,per_guest,per_unit'],
                'addons.*.*.rateMinor' => ['required', 'integer', 'min:0'],
            ],
            6 => [
                'branding.siteName' => ['required', 'string', 'min:1', 'max:120'],
                'branding.tagline' => ['nullable', 'string', 'max:240'],
                'branding.themePreset' => ['required', 'in:silver,ivory,midnight,sage'],
                'branding.fontPreset' => ['required', 'in:classic,modern'],
                'branding.footerText' => ['nullable', 'string', 'max:240'],
                'branding.seoDescription' => ['nullable', 'string', 'max:160'],
            ],
            7 => [
                'payments.banks' => ['array', 'max:5'],
                'payments.banks.*.bankName' => ['nullable', 'string', 'max:120'],
                'payments.banks.*.branch' => ['nullable', 'string', 'max:160'],
                'payments.banks.*.accountMasked' => ['nullable', 'string', 'max:32'],
                'payments.instructions' => ['nullable', 'string', 'max:1000'],
                'payments.qrInstructions' => ['nullable', 'string', 'max:1000'],
                'payments.methods' => ['array'],
                'payments.methods.*' => ['in:bank_transfer,cash,qr,easypaisa,jazzcash,raast'],
                'payments.depositPercent' => ['required', 'integer', 'min:0', 'max:100'],
            ],
            default => [],
        };
    }
}
