<?php

namespace App\Services;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Pure multi-line COUNT reconciliation, not a receipt verifier. Input must
 * be aggregated by an authorized service from immutable confirmed movements.
 * A matching count alone NEVER grants loan completion or stock ownership.
 */
final class HallInventoryReturnMatchPolicy
{
    public function compare(array $lines): array
    {
        if (count($lines) < 1 || count($lines) > 100) {
            throw new InvalidArgumentException('A loan needs one to 100 distinct item rows.');
        }
        $outstanding = 0;
        $damaged = 0;
        $details = [];
        foreach ($lines as $line) {
            if (! is_array($line) || array_is_list($line)
                || count($line) !== 4
                || array_diff(array_keys($line), ['itemId','borrowed','returnedGood','returnedDamaged'])
                || ! is_string($line['itemId'] ?? null)
                || ! Str::isUuid($line['itemId'])
                || ! is_int($line['borrowed'] ?? null) || $line['borrowed'] < 1
                || ! is_int($line['returnedGood'] ?? null) || $line['returnedGood'] < 0
                || ! is_int($line['returnedDamaged'] ?? null) || $line['returnedDamaged'] < 0
                || $line['borrowed'] > 100000000
                || $line['returnedGood'] + $line['returnedDamaged'] > $line['borrowed']) {
                throw new InvalidArgumentException('Invalid loan count, excess return or item reference.');
            }
            $id = $line['itemId'];
            if (array_key_exists($id, $details)) {
                throw new InvalidArgumentException('Loan item appears more than once.');
            }
            $missing = $line['borrowed'] - $line['returnedGood'] - $line['returnedDamaged'];
            $outstanding += $missing;
            $damaged += $line['returnedDamaged'];
            $details[$id] = [
                'borrowed' => $line['borrowed'],
                'returnedGood' => $line['returnedGood'],
                'returnedDamaged' => $line['returnedDamaged'],
                'outstanding' => $missing,
                'fullyMatchedInGoodCondition' => $missing === 0
                    && $line['returnedDamaged'] === 0,
            ];
        }
        return [
            'countsMatchInGoodCondition' => $outstanding === 0 && $damaged === 0,
            'outstanding' => $outstanding,
            'damaged' => $damaged,
            'items' => $details,
            'completionAuthorized' => false,
            'nextStep' => $outstanding === 0 && $damaged === 0
                ? 'Await independently verified handover acknowledgments, evidence and financial obligations review.'
                : 'Keep loan open for partial return or damage/loss reconciliation.',
        ];
    }
}
