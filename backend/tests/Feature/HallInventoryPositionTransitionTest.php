<?php

namespace Tests\Feature;

use App\Services\HallInventoryPositionTransition;
use InvalidArgumentException;
use Tests\TestCase;

class HallInventoryPositionTransitionTest extends TestCase
{
    private function zero():array
    {
        return [
            'ownedUsable'=>0,'ownedOnLoan'=>0,
            'borrowedUsable'=>0,'borrowedTransit'=>0,
            'ownedDamaged'=>0,'borrowedDamaged'=>0,
        ];
    }

    public function test_owned_stock_stays_owned_during_loan_and_partial_good_damage_returns():void
    {
        $service=new HallInventoryPositionTransition();
        $start=$this->zero();
        $held=$service->apply($start,'opening_owned',50);
        $dispatched=$service->apply($held,'dispatch_owned_loan',50);
        $partial=$service->apply($dispatched,'return_owned_good',45);
        $damaged=$service->apply($partial,'return_owned_damaged',5);
        $this->assertSame(0,$damaged['ownedOnLoan']);
        $this->assertSame(45,$damaged['ownedUsable']);
        $this->assertSame(5,$damaged['ownedDamaged']);
        $this->assertSame(50,$service->summary($damaged)['ownedTotal']);
        $this->assertFalse($service->summary($damaged)['postingAuthorized']);
        $this->assertSame($this->zero(),$start);
    }

    public function test_borrowed_custody_does_not_increase_owned_and_needs_return_confirmation():void
    {
        $s=new HallInventoryPositionTransition();
        $p=$s->apply($this->zero(),'accept_borrowed',100);
        $p=$s->apply($p,'send_borrowed_back',80);
        $this->assertSame(0,$s->summary($p)['ownedTotal']);
        $this->assertSame(100,$s->summary($p)['borrowedInCustody']);
        $p=$s->apply($p,'confirm_borrowed_return',80);
        $this->assertSame(20,$s->summary($p)['borrowedInCustody']);
        $p=$s->apply($p,'damage_borrowed',5);
        $this->assertSame(15,$p['borrowedUsable']);
        $this->assertSame(5,$p['borrowedDamaged']);
        $this->assertSame(20,$s->summary($p)['borrowedInCustody']);
    }

    public function test_rejects_overdraft_overflow_unknown_events_and_forged_buckets():void
    {
        $s=new HallInventoryPositionTransition();
        foreach([
            [$this->zero(),'dispatch_owned_loan',1],
            [$this->zero(),'return_owned_good',1],
            [$this->zero(),'confirm_borrowed_return',1],
            [$this->zero(),'opening_owned',0],
            [$this->zero(),'opening_owned',100000001],
            [$this->zero(),'paid_invoice',1],
            [array_merge($this->zero(),['otherOwned'=>50]),'opening_owned',1],
            [array_replace($this->zero(),['ownedUsable'=>-1]),'opening_owned',1],
            [array_replace($this->zero(),['ownedUsable'=>1000000000]),'opening_owned',1],
        ] as [$position,$event,$qty]){
            try{
                $s->apply($position,$event,$qty);
                $this->fail('Invalid custody state or unsupported event accepted.');
            }catch(InvalidArgumentException){}
        }
    }
}
