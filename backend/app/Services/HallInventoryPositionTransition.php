<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * Pure candidate stock-position transition. No database writes, physical
 * evidence, financial approval or loan-completion authority.
 *
 * Real movements must subsequently require hall-scoped authorization,
 * two-party custody evidence, immutable journal and deterministic row locks.
 */
final class HallInventoryPositionTransition
{
    private const FIELDS = [
        'ownedUsable', 'ownedOnLoan', 'borrowedUsable',
        'borrowedTransit', 'ownedDamaged', 'borrowedDamaged',
    ];
    private const EVENTS = [
        // These event labels describe counters ONLY, not permission to post.
        'opening_owned' => [null, 'ownedUsable'],
        'acquire_owned' => [null, 'ownedUsable'],
        'dispatch_owned_loan' => ['ownedUsable', 'ownedOnLoan'],
        'return_owned_good' => ['ownedOnLoan', 'ownedUsable'],
        'return_owned_damaged' => ['ownedOnLoan', 'ownedDamaged'],
        'accept_borrowed' => [null, 'borrowedUsable'],
        'send_borrowed_back' => ['borrowedUsable', 'borrowedTransit'],
        'confirm_borrowed_return' => ['borrowedTransit', null],
        'damage_borrowed' => ['borrowedUsable', 'borrowedDamaged'],
        'reclassify_owned_damage' => ['ownedUsable', 'ownedDamaged'],
        'restore_owned_usable' => ['ownedDamaged', 'ownedUsable'],
        'writeoff_owned_on_loan' => ['ownedOnLoan', null],
        'writeoff_owned_damaged' => ['ownedDamaged', null],
    ];

    /** @param array<string,int> $position
     * @return array<string,int>
     */
    public function apply(array $position,string $event,int $quantity):array
    {
        $this->validate($position);
        if(!array_key_exists($event,self::EVENTS) ||
            $quantity<1 || $quantity>100000000) {
            throw new InvalidArgumentException('Unknown inventory transition or invalid quantity.');
        }
        [$source,$destination]=self::EVENTS[$event];
        if($source!==null) {
            if($position[$source]<$quantity) {
                throw new InvalidArgumentException('Inventory custody would become negative.');
            }
            $position[$source]-=$quantity;
        }
        if($destination!==null) {
            if($position[$destination]>1000000000-$quantity) {
                throw new InvalidArgumentException('Inventory position would overflow.');
            }
            $position[$destination]+=$quantity;
        }
        return $position;
    }

    private function validate(array $position):void
    {
            $names=array_keys($position);
            sort($names,SORT_STRING);
            $allowed=self::FIELDS;
            sort($allowed,SORT_STRING);
            if($names!==$allowed) {
                throw new InvalidArgumentException('Inventory position must include exactly six ownership and custody buckets.');
            }
            foreach($position as $value) {
                if(!is_int($value) || $value<0 || $value>1000000000) {
                    throw new InvalidArgumentException('Inventory position has invalid quantities.');
                }
            }
    }

    /** Every typed count represents distinct buckets, NEVER borrowed-as-owned. */
    public function summary(array $position):array
    {
        $this->validate($position);
        return [
            'ownedTotal'=>$position['ownedUsable']+
                $position['ownedOnLoan']+$position['ownedDamaged'],
            'borrowedInCustody'=>$position['borrowedUsable']+
                $position['borrowedTransit']+$position['borrowedDamaged'],
            'ownedUsable'=>$position['ownedUsable'],
            'borrowedUsable'=>$position['borrowedUsable'],
            'postingAuthorized'=>false,
        ];
    }
}
