<?php

namespace App\Services;

use App\Models\User;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Persistent, resumable first-run venue setup. Never resets an existing installation. */
final class InstallationWizardService
{
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
        $clean = match ($step) {
            1 => ['company' => $this->company($data)],
            2 => ['halls' => $this->halls($data)],
            3 => [],
        };

        return DB::transaction(function () use (
            $actor, $companyId, $step, $clean, $expectedRevision,
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
            $next = $expectedRevision + 1;
            DB::table('installation_wizard_states')->where('company_id', $companyId)->update([
                'step' => min(3, $step + 1), 'revision' => $next,
                'data' => json_encode($merged, JSON_THROW_ON_ERROR), 'updated_at' => now(),
            ]);
            $this->audit($actor, $companyId, 'installation.wizard_step_saved', [
                'step' => $step, 'revision' => $next,
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
            $company = $data['company'] ?? [];
            $halls = $data['halls'] ?? [];
            if (! is_array($company) || ($company['legalName'] ?? '') === ''
                || ! is_array($halls) || $halls === []) {
                throw new InvalidArgumentException('Company details and at least one hall are required.');
            }
            DB::table('companies')->where('id', $companyId)->update([
                'name' => $company['legalName'], 'updated_at' => now(),
            ]);
            $savedHalls = [];
            foreach ($halls as $hall) {
                $hallId = is_string($hall['id'] ?? null) && Str::isUuid($hall['id'])
                    ? $hall['id'] : (string) Str::uuid();
                $existing = DB::table('halls')->where('company_id', $companyId)
                    ->where('id', $hallId)->first();
                if ($existing) {
                    DB::table('halls')->where('company_id', $companyId)->where('id', $hallId)
                        ->update(['name' => $hall['name'], 'capacity' => $hall['capacity'],
                            'is_active' => true, 'updated_at' => now()]);
                } else {
                    $slug = Str::slug($hall['name']);
                    $base = $slug !== '' ? $slug : 'hall';
                    $slug = $base;
                    $suffix = 2;
                    while (DB::table('halls')->where('company_id', $companyId)
                        ->where('slug', $slug)->exists()) {
                        $slug = $base.'-'.$suffix++;
                    }
                    DB::table('halls')->insert([
                        'id' => $hallId, 'company_id' => $companyId, 'slug' => $slug,
                        'name' => $hall['name'], 'capacity' => $hall['capacity'],
                        'included_chairs' => 0, 'included_tables' => 0,
                        'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                $savedHalls[] = array_merge($hall, ['id' => $hallId]);
            }
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
            || preg_match('/\A[A-Z]{3}\z/', $currency) !== 1
            || ! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException('Valid legal name, contact, ISO currency and IANA timezone are required.');
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
