<?php

namespace App\Services;

use App\Models\User;
use App\Support\Iso4217;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Persistent, resumable first-run venue setup. Partial stages are saved but
 * final completion remains unavailable until every approved stage exists.
 */
final class InstallationWizardService
{
    /** Final completion requires every approved operational stage. */
    public const REQUIRED_STAGES = [
        'company', 'halls', 'programs', 'pricing', 'addons',
        'branding', 'payments', 'staff', 'license',
    ];

    public function state(User $actor, string $companyId): array
    {
        $this->authorize($actor, $companyId);
        $row = DB::table('installation_wizard_states')->where('company_id', $companyId)->first();
        if ($row === null) {
            $id = (string) Str::uuid();
            DB::table('installation_wizard_states')->insertOrIgnore([
                'id' => $id, 'company_id' => $companyId, 'step' => 1,
                'revision' => 1, 'status' => 'draft',
                'data' => json_encode($this->emptyData(), JSON_THROW_ON_ERROR),
            ]);
            $row = DB::table('installation_wizard_states')->where('company_id', $companyId)->first();
        }

        return $this->project($row);
    }

    public function save(
        User $actor, string $companyId, int $step, array $data, int $expectedRevision,
    ): array {
        $this->authorize($actor, $companyId);
        if ($step < 1 || $step > 3 || $expectedRevision < 1) {
            throw new InvalidArgumentException('Invalid installation wizard step or revision.');
        }
        $this->state($actor, $companyId);
        $stage = match ($step) { 1 => 'company', 2 => 'halls', 3 => null };
        $clean = match ($step) {
            1 => ['company' => $this->company($data)],
            2 => ['halls' => $this->halls($data)],
            3 => [],
        };

        return DB::transaction(function () use (
            $actor, $companyId, $step, $stage, $clean, $expectedRevision,
        ): array {
            $row = DB::table('installation_wizard_states')->where('company_id', $companyId)
                ->lockForUpdate()->first();
            if ($row === null) {
                throw new ConflictHttpException('Installation state is unavailable.');
            }
            if ($row->status === 'completed') {
                throw new ConflictHttpException('This installation is already complete.');
            }
            if ((int) $row->revision !== $expectedRevision) {
                throw new ConflictHttpException('Installation setup changed; reload before retrying.');
            }
            $current = json_decode($row->data, true, 512, JSON_THROW_ON_ERROR);
            $merged = array_replace_recursive($current, $clean);
            $stages = array_values(array_unique(array_merge(
                is_array($current['completedStages'] ?? null) ? $current['completedStages'] : [],
                $stage !== null ? [$stage] : [],
            )));
            $merged['completedStages'] = $stages;
            if ($stage === 'company') {
                $this->persistCompany($companyId, $merged['company']);
            }
            if ($stage === 'halls') {
                $merged['halls'] = $this->persistHalls($companyId, $merged['halls']);
            }
            $next = $expectedRevision + 1;
            DB::table('installation_wizard_states')->where('company_id', $companyId)->update([
                'step' => min(3, $step + 1), 'revision' => $next,
                'status' => 'in_progress',
                'data' => json_encode($merged, JSON_THROW_ON_ERROR), 'updated_at' => now(),
            ]);
            $this->audit($actor, $companyId, 'installation.wizard_step_saved', [
                'step' => $step, 'stage' => $stage, 'revision' => $next,
                'completedStages' => $stages,
            ]);
            $fresh = DB::table('installation_wizard_states')->where('company_id', $companyId)->first();

            return $this->project($fresh);
        }, 3);
    }

    public function savePrograms(
        User $actor, string $companyId, array $programs, int $expectedRevision,
    ): array {
        $this->authorize($actor, $companyId);

        return $this->saveOperationalStage(
            $actor, $companyId, $expectedRevision, 'programs', 4,
            function (array $state) use ($actor, $companyId, $programs): array {
                $rulesService = app(HallProgramRulesService::class);
                $saved = [];
                foreach ($state['halls'] as $hall) {
                    $rules = $programs[$hall['id']] ?? null;
                    if (! is_array($rules)) {
                        throw new InvalidArgumentException('Configure morning, noon and evening for every hall.');
                    }
                    $current = $rulesService->current($actor, $companyId, $hall['id']);
                    $revision = $rulesService->configure(
                        $actor, $companyId, $hall['id'], $rules,
                        $state['company']['currency'], (int) ($current['revision'] ?? 0),
                        'Initial installation wizard hall programs',
                    );
                    $saved[$hall['id']] = ['rules' => $rules, 'revision' => $revision];
                }

                return ['programs' => $saved];
            },
        );
    }

    public function savePricing(
        User $actor, string $companyId, array $pricing, int $expectedRevision,
    ): array {
        $this->authorize($actor, $companyId);

        return $this->saveOperationalStage(
            $actor, $companyId, $expectedRevision, 'pricing', 5,
            function (array $state) use ($actor, $companyId, $pricing): array {
                $service = app(HallSeatingPriceService::class);
                $saved = [];
                foreach ($state['halls'] as $hall) {
                    $config = $pricing[$hall['id']] ?? null;
                    if (! is_array($config)) {
                        throw new InvalidArgumentException('Configure seating pricing for every hall.');
                    }
                    $current = $service->current($actor, $companyId, $hall['id']);
                    $revision = $service->configure(
                        $actor, $companyId, $hall['id'], $config,
                        $state['company']['currency'], (int) ($current['revision'] ?? 0),
                        'Initial installation wizard seating and unit prices',
                    );
                    $saved[$hall['id']] = ['config' => $config, 'revision' => $revision];
                }

                return ['pricing' => $saved];
            },
        );
    }

    public function saveAddons(
        User $actor, string $companyId, array $addons, int $expectedRevision,
    ): array {
        $this->authorize($actor, $companyId);

        return $this->saveOperationalStage(
            $actor, $companyId, $expectedRevision, 'addons', 6,
            function (array $state) use ($actor, $companyId, $addons): array {
                $service = app(HallAddonCatalogService::class);
                $saved = [];
                foreach ($state['halls'] as $hall) {
                    $items = $addons[$hall['id']] ?? null;
                    if (! is_array($items)) {
                        throw new InvalidArgumentException('Configure the optional add-on list for every hall.');
                    }
                    $current = $service->current($actor, $companyId, $hall['id']);
                    $revision = $service->configure(
                        $actor, $companyId, $hall['id'], $items,
                        $state['company']['currency'], (int) ($current['revision'] ?? 0),
                        'Initial installation wizard add-on catalog',
                    );
                    $saved[$hall['id']] = ['items' => $items, 'revision' => $revision];
                }

                return ['addons' => $saved];
            },
        );
    }

    public function saveBranding(
        User $actor, string $companyId, array $branding, int $expectedRevision,
    ): array {
        $this->authorize($actor, $companyId);

        return $this->saveOperationalStage(
            $actor, $companyId, $expectedRevision, 'branding', 7,
            function (array $state) use ($actor, $companyId, $branding): array {
                $website = DB::table('websites')->where('company_id', $companyId)
                    ->orderBy('id')->first(['id', 'branding_draft_revision',
                        'branding_published_revision_id']);
                if ($website === null) {
                    $websiteId = (string) Str::uuid();
                    DB::table('websites')->insert([
                        'id' => $websiteId, 'company_id' => $companyId,
                        'name' => $state['company']['legalName'],
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $draftRevision = 0;
                    $publishedRevisionId = null;
                } else {
                    $websiteId = $website->id;
                    $draftRevision = (int) $website->branding_draft_revision;
                    $publishedRevisionId = $website->branding_published_revision_id;
                }
                $existing = null;
                if ($draftRevision > 0) {
                    $existing = DB::table('site_branding_revisions')
                        ->where('company_id', $companyId)->where('website_id', $websiteId)
                        ->where('revision_number', $draftRevision)->value('content');
                } elseif ($publishedRevisionId !== null) {
                    $existing = DB::table('site_branding_revisions')
                        ->where('company_id', $companyId)->where('website_id', $websiteId)
                        ->where('id', $publishedRevisionId)->value('content');
                }
                $base = is_string($existing)
                    ? json_decode($existing, true, 512, JSON_THROW_ON_ERROR) : [];
                $payload = array_merge(is_array($base) ? $base : [], [
                    'siteName' => $branding['siteName'],
                    'tagline' => $branding['tagline'] ?? '',
                    'themePreset' => $branding['themePreset'] ?? 'silver',
                    'fontPreset' => $branding['fontPreset'] ?? 'classic',
                    'footerText' => $branding['footerText'] ?? '',
                    'seoDescription' => $branding['seoDescription'] ?? '',
                ]);
                // Existing approved logo/favicon/navigation/media references survive
                // wizard edits unless a separate media workflow changes them.
                $payload['logoMediaId'] = $base['logoMediaId'] ?? null;
                $payload['faviconMediaId'] = $base['faviconMediaId'] ?? null;
                if (isset($base['navigationOrder']) && is_array($base['navigationOrder'])) {
                    $payload['navigationOrder'] = $base['navigationOrder'];
                }
                $revision = app(SiteBrandingService::class)->saveDraft(
                    $actor, $companyId, $websiteId, $payload,
                    $draftRevision, 'Initial installation wizard branding',
                );

                return ['branding' => [
                    'websiteId' => $websiteId, 'revision' => $revision,
                    'siteName' => $branding['siteName'],
                    'tagline' => $branding['tagline'] ?? '',
                    'themePreset' => $branding['themePreset'] ?? 'silver',
                    'fontPreset' => $branding['fontPreset'] ?? 'classic',
                    'footerText' => $branding['footerText'] ?? '',
                    'seoDescription' => $branding['seoDescription'] ?? '',
                ]];
            },
        );
    }

    public function savePayments(
        User $actor, string $companyId, array $payments, int $expectedRevision,
    ): array {
        $this->authorize($actor, $companyId);

        return $this->saveOperationalStage(
            $actor, $companyId, $expectedRevision, 'payments', 8,
            function (array $state) use ($actor, $companyId, $payments): array {
                $paymentService = app(TenantPaymentSettingsService::class);
                $current = $paymentService->state($actor, $companyId);
                $saved = $paymentService->save(
                    $actor, $companyId, $payments, (int) $current['revision'],
                );

                return ['payments' => $saved['config']];
            },
        );
    }

    public function saveStaff(
        User $actor, string $companyId, bool $ownerOnly, int $expectedRevision,
    ): array {
        $this->authorize($actor, $companyId);

        return $this->saveOperationalStage(
            $actor, $companyId, $expectedRevision, 'staff', 9,
            function () use ($ownerOnly): array {
                // Stage marker only. Final completion independently validates
                // current eligible invitations/memberships and never trusts a
                // historical staff marker by itself.
                return ['staff' => [
                    'ownerOnly' => $ownerOnly,
                    'markedAt' => now()->toIso8601String(),
                ]];
            },
        );
    }

    public function saveLicense(
        User $actor, string $companyId, string $licenseState, int $expectedRevision,
    ): array {
        $this->authorize($actor, $companyId);

        return $this->saveOperationalStage(
            $actor, $companyId, $expectedRevision, 'license', 10,
            fn (): array => ['license' => [
                'state' => $licenseState,
                'recordedAt' => now()->toIso8601String(),
            ]],
        );
    }

    private function saveOperationalStage(
        User $actor, string $companyId, int $expectedRevision,
        string $stage, int $nextStep, callable $persist,
    ): array {
        if ($expectedRevision < 1) {
            throw new InvalidArgumentException('A valid installation revision is required.');
        }

        return DB::transaction(function () use (
            $actor, $companyId, $expectedRevision, $stage, $nextStep, $persist,
        ): array {
            $row = DB::table('installation_wizard_states')->where('company_id', $companyId)
                ->lockForUpdate()->first();
            if ($row === null) {
                throw new ConflictHttpException('Installation state is unavailable.');
            }
            if ($row->status === 'completed') {
                throw new ConflictHttpException('This installation is already complete.');
            }
            if ((int) $row->revision !== $expectedRevision) {
                throw new ConflictHttpException('Installation setup changed; reload before retrying.');
            }
            $data = json_decode($row->data, true, 512, JSON_THROW_ON_ERROR);
            $halls = $data['halls'] ?? [];
            if (! is_array($halls) || $halls === []) {
                throw new InvalidArgumentException('Save halls before configuring operations.');
            }
            $clean = $persist($data);
            $merged = array_replace_recursive($data, $clean);
            $stages = array_values(array_unique(array_merge(
                is_array($data['completedStages'] ?? null) ? $data['completedStages'] : [],
                [$stage],
            )));
            $merged['completedStages'] = $stages;
            $next = $expectedRevision + 1;
            DB::table('installation_wizard_states')->where('company_id', $companyId)->update([
                'step' => max($row->step, $nextStep), 'revision' => $next,
                'status' => 'in_progress',
                'data' => json_encode($merged, JSON_THROW_ON_ERROR), 'updated_at' => now(),
            ]);
            $this->audit($actor, $companyId, 'installation.wizard_stage_saved', [
                'stage' => $stage, 'revision' => $next, 'completedStages' => $stages,
            ]);
            $fresh = DB::table('installation_wizard_states')->where('company_id', $companyId)->first();

            return $this->project($fresh);
        }, 3);
    }

    public function complete(User $actor, string $companyId, int $expectedRevision): array
    {
        $this->authorize($actor, $companyId);
        if ($expectedRevision < 1) {
            throw new InvalidArgumentException('A valid installation revision is required.');
        }

        return DB::transaction(function () use ($actor, $companyId, $expectedRevision): array {
            $row = DB::table('installation_wizard_states')->where('company_id', $companyId)
                ->lockForUpdate()->first();
            if ($row === null) {
                throw new ConflictHttpException('Installation state is unavailable.');
            }
            if ($row->status === 'completed') {
                throw new ConflictHttpException('This installation is already complete.');
            }
            if ((int) $row->revision !== $expectedRevision) {
                throw new ConflictHttpException('Installation setup changed; reload before completing.');
            }
            $data = json_decode($row->data, true, 512, JSON_THROW_ON_ERROR);
            $stages = is_array($data['completedStages'] ?? null) ? $data['completedStages'] : [];
            $missing = array_values(array_diff(self::REQUIRED_STAGES, $stages));
            if ($missing !== []) {
                throw new InvalidArgumentException(
                    'Complete all approved setup stages before finishing: '.implode(', ', $missing)
                );
            }
            $license = app(CentralLicensingOverviewService::class)
                ->snapshotForCompany($actor, $companyId, now()->toDateTimeImmutable());
            if (! in_array($license['state'] ?? null, ['licensed', 'expired_grace'], true)) {
                throw new InvalidArgumentException(
                    'A currently verified signed license is required before installation completion.'
                );
            }
            $company = $data['company'] ?? [];
            $halls = $data['halls'] ?? [];
            if (! is_array($company) || ($company['legalName'] ?? '') === ''
                || ! is_array($halls) || $halls === []) {
                throw new InvalidArgumentException('Company details and at least one hall are required.');
            }
            DB::table('companies')->where('id', $companyId)->update([
                'name' => $company['legalName'], 'updated_at' => now(),
            ]);
            $savedHalls = $this->persistHalls($companyId, $halls);
            $data['halls'] = $savedHalls;
            $next = $expectedRevision + 1;
            DB::table('installation_wizard_states')->where('company_id', $companyId)->update([
                'status' => 'completed', 'step' => 3, 'revision' => $next,
                'data' => json_encode($data, JSON_THROW_ON_ERROR),
                'completed_at' => now(), 'completed_by' => $actor->getKey(),
                'updated_at' => now(),
            ]);
            $this->audit($actor, $companyId, 'installation.wizard_completed', [
                'revision' => $next, 'hallCount' => count($savedHalls),
            ]);
            $fresh = DB::table('installation_wizard_states')->where('company_id', $companyId)->first();

            return $this->project($fresh);
        }, 3);
    }

    private function persistCompany(string $companyId, array $company): void
    {
        DB::table('companies')->where('id', $companyId)->update([
            'legal_name' => $company['legalName'],
            'contact_email' => $company['contactEmail'] ?: null,
            'contact_phone' => $company['contactPhone'] ?: null,
            'address' => $company['address'] ?: null,
            'currency_code' => $company['currency'],
            'timezone' => $company['timezone'],
            'updated_at' => now(),
        ]);
    }

    private function persistHalls(string $companyId, array $halls): array
    {
        $saved = [];
        foreach ($halls as $hall) {
            $hallId = is_string($hall['id'] ?? null) && Str::isUuid($hall['id'])
                ? $hall['id'] : (string) Str::uuid();
            $existing = DB::table('halls')->where('company_id', $companyId)
                ->where('id', $hallId)->first();
            if ($existing) {
                // Preserve lifecycle state: setup never silently reactivates a hall.
                DB::table('halls')->where('company_id', $companyId)->where('id', $hallId)
                    ->update(['name' => $hall['name'], 'capacity' => $hall['capacity'],
                        'address' => $hall['address'] ?: null,
                        'phone' => $hall['phone'] ?: null, 'updated_at' => now()]);
            } else {
                $base = Str::slug($hall['name']) ?: 'hall';
                $slug = $base;
                $suffix = 2;
                while (DB::table('halls')->where('company_id', $companyId)
                    ->where('slug', $slug)->exists()) {
                    $slug = $base.'-'.$suffix++;
                }
                DB::table('halls')->insert([
                    'id' => $hallId, 'company_id' => $companyId, 'slug' => $slug,
                    'name' => $hall['name'], 'capacity' => $hall['capacity'],
                    'address' => $hall['address'] ?: null,
                    'phone' => $hall['phone'] ?: null,
                    'included_chairs' => 0, 'included_tables' => 0,
                    'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $saved[] = array_merge($hall, ['id' => $hallId]);
        }

        return $saved;
    }

    private function authorize(User $actor, string $companyId): void
    {
        $allowed = $actor->is_active && $actor->hasVerifiedEmail()
            && DB::table('users')->where('id', $actor->getKey())
                ->where('is_active', true)->whereNotNull('email_verified_at')->exists()
            && DB::table('company_memberships')->where('company_id', $companyId)
                ->where('user_id', $actor->getKey())->where('is_active', true)
                ->where('role', 'owner')->exists()
            && DB::table('companies')->where('id', $companyId)->where('is_active', true)->exists();
        if (! $allowed) {
            throw new AuthorizationException('Only an active verified owner can configure this installation.');
        }
    }

    private function project(object $row): array
    {
        return [
            'id' => $row->id, 'companyId' => $row->company_id,
            'step' => (int) $row->step, 'revision' => (int) $row->revision,
            'status' => $row->status,
            'completedAt' => $row->completed_at,
            'data' => json_decode($row->data, true, 512, JSON_THROW_ON_ERROR),
        ];
    }

    private function emptyData(): array
    {
        return [
            'company' => [
                'legalName' => '', 'contactEmail' => '', 'contactPhone' => '',
                'address' => '', 'currency' => 'PKR', 'timezone' => 'Asia/Karachi',
            ],
            'halls' => [],
            'completedStages' => [],
        ];
    }

    private function company(array $data): array
    {
        $company = is_array($data['company'] ?? null) ? $data['company'] : [];
        $name = trim((string) ($company['legalName'] ?? ''));
        $email = trim((string) ($company['contactEmail'] ?? ''));
        $phone = trim((string) ($company['contactPhone'] ?? ''));
        $address = trim((string) ($company['address'] ?? ''));
        $currency = strtoupper(trim((string) ($company['currency'] ?? '')));
        $timezone = trim((string) ($company['timezone'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 160
            || ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false)
            || mb_strlen($email) > 254 || mb_strlen($phone) > 32 || mb_strlen($address) > 240
            || preg_match('/[<>[:cntrl:]]/u', $name.$email.$phone.$address)
            || ! Iso4217::isValid($currency)
            || ! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException('Valid legal name, contact, ISO 4217 currency and IANA timezone are required.');
        }

        return [
            'legalName' => $name, 'contactEmail' => $email,
            'contactPhone' => $phone, 'address' => $address,
            'currency' => $currency, 'timezone' => $timezone,
        ];
    }

    private function halls(array $data): array
    {
        $halls = is_array($data['halls'] ?? null) ? $data['halls'] : [];
        if (count($halls) < 1 || count($halls) > 10) {
            throw new InvalidArgumentException('Add between one and ten halls.');
        }
        $clean = [];
        foreach ($halls as $hall) {
            if (! is_array($hall)) {
                throw new InvalidArgumentException('Invalid hall entry.');
            }
            $name = trim((string) ($hall['name'] ?? ''));
            $address = trim((string) ($hall['address'] ?? ''));
            $phone = trim((string) ($hall['phone'] ?? ''));
            $capacity = filter_var($hall['capacity'] ?? null, FILTER_VALIDATE_INT);
            if (mb_strlen($name) < 2 || mb_strlen($name) > 120
                || mb_strlen($address) > 240 || mb_strlen($phone) > 32
                || $capacity === false || $capacity < 1 || $capacity > 100000
                || preg_match('/[<>[:cntrl:]]/u', $name.$address.$phone)) {
                throw new InvalidArgumentException('Each hall needs a valid name, address and capacity.');
            }
            $id = is_string($hall['id'] ?? null) && Str::isUuid($hall['id'])
                ? $hall['id'] : null;
            $clean[] = compact('id', 'name', 'address', 'phone', 'capacity');
        }

        return $clean;
    }

    private function audit(User $actor, string $companyId, string $action, array $after): void
    {
        DB::table('audit_events')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $companyId,
            'actor_id' => $actor->getKey(), 'action' => $action,
            'entity_type' => 'installation_wizard',
            'entity_id' => DB::table('installation_wizard_states')->where('company_id', $companyId)->value('id'),
            'before_values' => null,
            'after_values' => json_encode($after, JSON_THROW_ON_ERROR),
            'context' => json_encode([], JSON_THROW_ON_ERROR),
        ]);
    }
}
