<?php

namespace App\Services;

use App\Models\Hall;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class HallInventoryLoanDraftService
{
    public function __construct(private readonly TenantAccess $access) {}

    /**
     * Prepare a hall-owned multi-item loan request. DRAFT ONLY:
     * no dispatch, accepted receipt, owned-stock movement or auto-closure.
     * A later controlled dual-confirmation workflow will resolve custody.
     */
    public function create(
        User $actor, string $companyId, string $requestId, string $direction,
        ?string $lendingHallId, ?string $borrowingHallId,
        array $lines, string $returnDate, string $purpose,
        ?string $externalName = null, ?string $externalContact = null,
        ?string $externalPhone = null, ?string $externalAddress = null,
    ): string {
        if (! config('inventory_controls.loan_drafts_enabled')) {
            throw new ConflictHttpException('Inventory loan request recording is not released.');
        }
        $purpose = trim($purpose);
        $externalName = trim($externalName ?? '');
        $externalContact = trim($externalContact ?? '');
        $externalPhone = trim($externalPhone ?? '');
        $externalAddress = trim($externalAddress ?? '');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $returnDate);
        if (! Str::isUuid($companyId) || ! Str::isUuid($requestId)
            || ! in_array($direction, ['internal', 'external_in', 'external_out'], true)
            || ! $date || $date->format('Y-m-d') !== $returnDate
            || mb_strlen($purpose) < 3 || mb_strlen($purpose) > 500
            || ($lendingHallId !== null && ! Str::isUuid($lendingHallId))
            || ($borrowingHallId !== null && ! Str::isUuid($borrowingHallId))
            || count($lines) < 1 || count($lines) > 100) {
            throw new InvalidArgumentException('Invalid inventory loan request or return date.');
        }
        if (($direction === 'internal' && ($lendingHallId === null
                || $borrowingHallId === null || $lendingHallId === $borrowingHallId
                || $externalName !== ''))
            || ($direction === 'external_in' && ($lendingHallId !== null
                || $borrowingHallId === null || $externalName === ''))
            || ($direction === 'external_out' && ($lendingHallId === null
                || $borrowingHallId !== null || $externalName === ''))
            || mb_strlen($externalName) > 160
            || mb_strlen($externalContact) > 240
            || mb_strlen($externalPhone) > 100
            || mb_strlen($externalAddress) > 500) {
            throw new InvalidArgumentException('Specify valid internal halls or an external establishment.');
        }

        $catalogHallId = $direction === 'external_in' ? $borrowingHallId : $lendingHallId;
        $items = [];
        foreach ($lines as $line) {
            if (! is_array($line) || array_is_list($line)
                || count($line) !== 2
                || array_diff(array_keys($line), ['itemId', 'quantity'])
                || ! is_string($line['itemId'] ?? null) || ! Str::isUuid($line['itemId'])
                || ! is_int($line['quantity'] ?? null)
                || $line['quantity'] < 1 || $line['quantity'] > 100000000) {
                throw new InvalidArgumentException('Each loan row needs one catalog item and a positive quantity.');
            }
            if (isset($items[$line['itemId']])) {
                throw new InvalidArgumentException('Duplicate item rows are not allowed in one loan.');
            }
            $items[$line['itemId']] = (int) $line['quantity'];
        }
        ksort($items, SORT_STRING);

        $fingerprint = hash('sha256', json_encode([
            'actor' => $actor->getKey(), 'company' => $companyId,
            'direction' => $direction, 'lending' => $lendingHallId,
            'borrowing' => $borrowingHallId, 'catalogHall' => $catalogHallId,
            'lines' => $items, 'returnDate' => $returnDate, 'purpose' => $purpose,
            'externalName' => $externalName, 'externalContact' => $externalContact,
            'externalPhone' => $externalPhone, 'externalAddress' => $externalAddress,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use (
            $actor, $companyId, $requestId, $direction, $lendingHallId,
            $borrowingHallId, $catalogHallId, $items, $returnDate, $purpose,
            $externalName, $externalContact, $externalPhone, $externalAddress,
            $fingerprint
        ): string {
            $hallIds = array_values(array_unique(array_filter([
                $lendingHallId, $borrowingHallId,
            ])));
            sort($hallIds, SORT_STRING);
            $halls = Hall::query()->where('company_id', $companyId)
                ->whereIn('id', $hallIds)->where('is_active', true)
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if (count($halls) !== count($hallIds)
                || ! Auth::check() || (int) Auth::id() !== (int) $actor->getKey()) {
                throw new AuthorizationException('The selected loan halls are not available.');
            }
            $local = $halls->get($catalogHallId);
            if (! $local || ! $this->access->canManageHallInventory($actor, $local)) {
                throw new AuthorizationException('Only assigned hall managers or owners may initiate this loan.');
            }

            $existing = DB::table('hall_inventory_loan_drafts')->where('id', $requestId)->first();
            if ($existing) {
                if ($existing->company_id !== $companyId
                    || (int) $existing->created_by !== (int) $actor->getKey()
                    || $existing->request_fingerprint !== $fingerprint) {
                    throw new ConflictHttpException('Loan request ID was reused with different details.');
                }
                return $requestId;
            }

            $catalog = DB::table('hall_inventory_items')
                ->where('company_id', $companyId)->where('hall_id', $catalogHallId)
                ->where('is_active', true)->whereIn('id', array_keys($items))
                ->orderBy('id')->lockForUpdate()->get(['id','name','unit'])->keyBy('id');
            if (count($catalog) !== count($items)) {
                throw new AuthorizationException('Loan rows must reference current items in the initiating hall catalog.');
            }

            $partnerId = null;
            if ($direction !== 'internal') {
                $partnerId = (string) Str::uuid();
                DB::table('hall_inventory_external_partners')->insert([
                    'id' => $partnerId, 'company_id' => $companyId,
                    'name' => $externalName,
                    'contact_ciphertext' => $externalContact !== ''
                        ? Crypt::encryptString($externalContact) : null,
                    'phone_ciphertext' => $externalPhone !== ''
                        ? Crypt::encryptString($externalPhone) : null,
                    'address_ciphertext' => $externalAddress !== ''
                        ? Crypt::encryptString($externalAddress) : null,
                    'created_by' => $actor->getKey(),
                ]);
            }
            DB::table('hall_inventory_loan_drafts')->insert([
                'id' => $requestId, 'company_id' => $companyId,
                'direction' => $direction, 'lending_hall_id' => $lendingHallId,
                'borrowing_hall_id' => $borrowingHallId,
                'external_partner_id' => $partnerId,
                'expected_return_date' => $returnDate,
                'purpose' => $purpose, 'request_fingerprint' => $fingerprint,
                'created_by' => $actor->getKey(),
            ]);
            $loanLines = [];
            foreach ($items as $itemId => $quantity) {
                $item = $catalog->get($itemId);
                $loanLines[] = [
                    'id' => (string) Str::uuid(), 'company_id' => $companyId,
                    'loan_id' => $requestId, 'catalog_hall_id' => $catalogHallId,
                    'item_id' => $itemId, 'requested_quantity' => $quantity,
                    'item_name_snapshot' => $item->name,
                    'unit_snapshot' => $item->unit,
                ];
            }
            DB::table('hall_inventory_loan_draft_lines')->insert($loanLines);
            DB::table('audit_events')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $companyId,
                'actor_id' => $actor->getKey(),
                'action' => 'hall_inventory.loan_draft_created',
                'entity_type' => 'hall_inventory_loan', 'entity_id' => $requestId,
                'before_values' => json_encode([], JSON_THROW_ON_ERROR),
                'after_values' => json_encode([
                    'direction' => $direction, 'lendingHallId' => $lendingHallId,
                    'borrowingHallId' => $borrowingHallId, 'catalogHallId' => $catalogHallId,
                    'lineCount' => count($items), 'fingerprint' => $fingerprint,
                ], JSON_THROW_ON_ERROR),
                'context' => json_encode(['reason' => $purpose], JSON_THROW_ON_ERROR),
            ]);
            return $requestId;
        }, 3);
    }
}
