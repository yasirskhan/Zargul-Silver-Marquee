<?php

namespace App\Services;

use App\Models\Hall;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Exact guest-count pricing tiers, versioned per hall. */
final class HallGuestPricingService
{
    private const PROGRAMS = ['morning', 'noon', 'evening'];

    public function __construct(private readonly TenantAccess $access) {}

    public function configure(User $actor, string $companyId, string $hallId,
        array $tiers, string $currencyCode, int $expectedRevision, string $reason): int
    {
        $this->authorize($actor, $companyId, $hallId);
        $tiers = $this->normalizeTiers($tiers);
        if (! preg_match('/^[A-Z]{3}$/D', $currencyCode)) {
            throw new InvalidArgumentException('Currency must be a three-letter ISO code.');
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
            throw new InvalidArgumentException('A reason for pricing changes is required.');
        }

        return DB::transaction(function () use ($actor, $companyId, $hallId, $tiers,
            $currencyCode, $expectedRevision, $reason): int {
            $hall = Hall::query()->where('company_id', $companyId)->where('id', $hallId)
                ->where('is_active', true)->lockForUpdate()->first();
            if (! $hall || ! Auth::check() || (int) Auth::id() !== (int) $actor->getKey()
                || ! $actor->hasVerifiedEmail() || ! $this->access->canConfigureHall($actor, $hall)) {
                throw new AuthorizationException('You cannot change hall guest pricing.');
            }
            $live = (int) $hall->guest_pricing_revision;
            if ($expectedRevision < 0 || $live !== $expectedRevision) {
                throw new ConflictHttpException('Guest pricing changed; reload before saving.');
            }
            $next = $live + 1;
            DB::table('hall_guest_pricing_revisions')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $companyId,
                'hall_id' => $hallId, 'revision_number' => $next,
                'tiers' => json_encode($tiers, JSON_THROW_ON_ERROR),
                'currency_code' => $currencyCode, 'changed_by' => $actor->getKey(),
                'reason' => $reason, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('halls')->where('company_id', $companyId)->where('id', $hallId)
                ->update(['guest_pricing_revision' => $next, 'updated_at' => now()]);
            DB::table('audit_events')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $companyId,
                'actor_id' => $actor->getKey(), 'action' => 'hall.guest_pricing_updated',
                'entity_type' => 'hall', 'entity_id' => $hallId,
                'before_values' => json_encode(['revision' => $live]),
                'after_values' => json_encode(['revision' => $next,
                    'tiers' => $tiers, 'currencyCode' => $currencyCode], JSON_THROW_ON_ERROR),
                'context' => json_encode(['reason' => $reason], JSON_THROW_ON_ERROR),
            ]);

            return $next;
        }, 3);
    }

    /** @return array{hallId:string,guests:int,priceMinor:int,currencyCode:string,revision:int} */
    public function quote(string $companyId, string $hallId, int $guests): array
    {
        if ($guests < 1 || $guests > 100000) {
            throw new InvalidArgumentException('Guest count is outside the supported range.');
        }
        $hall = DB::table('halls')->where('company_id', $companyId)->where('id', $hallId)
            ->where('is_active', true)->first(['guest_pricing_revision']);
        if (! $hall || (int) $hall->guest_pricing_revision < 1) {
            throw new ConflictHttpException('Guest pricing is not configured for this hall.');
        }
        $revision = DB::table('hall_guest_pricing_revisions')
            ->where('company_id', $companyId)->where('hall_id', $hallId)
            ->where('revision_number', (int) $hall->guest_pricing_revision)->first();
        if (! $revision) {
            throw new ConflictHttpException('Approved guest pricing is unavailable.');
        }
        $tiers = json_decode($revision->tiers, true, 32, JSON_THROW_ON_ERROR);
        foreach ($tiers as $tier) {
            if ($guests >= $tier['minGuests'] && $guests <= $tier['maxGuests']) {
                return ['hallId' => $hallId, 'guests' => $guests,
                    'priceMinor' => $tier['priceMinor'],
                    'currencyCode' => $revision->currency_code,
                    'revision' => (int) $revision->revision_number];
            }
        }
        throw new ConflictHttpException('No approved price bracket covers this guest count.');
    }

    private function normalizeTiers(array $tiers): array
    {
        if ($tiers === [] || count($tiers) > 50) {
            throw new InvalidArgumentException('Provide between one and fifty exact guest brackets.');
        }
        $clean = [];
        foreach ($tiers as $tier) {
            if (! is_array($tier) || array_diff(array_keys($tier),
                ['minGuests', 'maxGuests', 'priceMinor'])) {
                throw new InvalidArgumentException('Invalid guest price bracket.');
            }
            $min = filter_var($tier['minGuests'] ?? null, FILTER_VALIDATE_INT);
            $max = filter_var($tier['maxGuests'] ?? null, FILTER_VALIDATE_INT);
            $price = filter_var($tier['priceMinor'] ?? null, FILTER_VALIDATE_INT);
            if ($min === false || $max === false || $price === false
                || $min < 1 || $max < $min || $max > 100000 || $price < 0) {
                throw new InvalidArgumentException('Invalid guest price bracket values.');
            }
            $clean[] = ['minGuests' => $min, 'maxGuests' => $max, 'priceMinor' => $price];
        }
        usort($clean, fn (array $a, array $b): int => $a['minGuests'] <=> $b['minGuests']);
        $previous = 0;
        foreach ($clean as $tier) {
            if ($tier['minGuests'] <= $previous) {
                throw new InvalidArgumentException('Guest price brackets must not overlap.');
            }
            $previous = $tier['maxGuests'];
        }

        return $clean;
    }

    private function authorize(User $actor, string $companyId, string $hallId): void
    {
        $hall = Hall::query()->where('company_id', $companyId)->where('id', $hallId)
            ->where('is_active', true)->first();
        if (! $hall || ! Auth::check() || (int) Auth::id() !== (int) $actor->getKey()
            || ! $actor->hasVerifiedEmail() || ! $this->access->canConfigureHall($actor, $hall)) {
            throw new AuthorizationException('You cannot change hall guest pricing.');
        }
    }
}