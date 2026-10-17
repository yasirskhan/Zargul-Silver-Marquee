<?php

namespace Tests\Feature;

use App\Services\HallInventoryReturnMatchPolicy;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class HallInventoryReturnMatchPolicyTest extends TestCase
{
    public function test_multi_item_full_usable_return_matches_but_never_auto_completes_without_evidence(): void
    {
        $a=(string) Str::uuid(); $b=(string) Str::uuid(); $c=(string) Str::uuid();
        $result=app(HallInventoryReturnMatchPolicy::class)->compare([
            ['itemId'=>$a, 'borrowed'=>50, 'returnedGood'=>50, 'returnedDamaged'=>0],
            ['itemId'=>$b, 'borrowed'=>10, 'returnedGood'=>10, 'returnedDamaged'=>0],
            ['itemId'=>$c, 'borrowed'=>100, 'returnedGood'=>100, 'returnedDamaged'=>0],
        ]);
        $this->assertTrue($result['countsMatchInGoodCondition']);
        $this->assertFalse($result['completionAuthorized']);
        $this->assertSame(0,$result['outstanding']);
        $this->assertSame(0,$result['damaged']);
        $this->assertCount(3,$result['items']);
    }

    public function test_partial_and_damaged_returns_remain_open(): void
    {
        $a=(string) Str::uuid(); $b=(string) Str::uuid();
        $r=app(HallInventoryReturnMatchPolicy::class)->compare([
            ['itemId'=>$a,'borrowed'=>50,'returnedGood'=>45,'returnedDamaged'=>0],
            ['itemId'=>$b,'borrowed'=>100,'returnedGood'=>80,'returnedDamaged'=>5],
        ]);
        $this->assertFalse($r['countsMatchInGoodCondition']);
        $this->assertSame(20,$r['outstanding']);
        $this->assertSame(5,$r['damaged']);
        $this->assertFalse($r['completionAuthorized']);
        $this->assertFalse($r['items'][$a]['fullyMatchedInGoodCondition']);
    }

    public function test_extra_returns_and_repeated_item_ids_cannot_be_accepted(): void
    {
        $a=(string) Str::uuid();
        $service=app(HallInventoryReturnMatchPolicy::class);
        try {
            $service->compare([['itemId'=>$a,'borrowed'=>10,
                'returnedGood'=>11,'returnedDamaged'=>0]]);
            $this->fail('Excess physical return accepted.');
        } catch (InvalidArgumentException) {}
        $this->expectException(InvalidArgumentException::class);
        $service->compare([
            ['itemId'=>$a,'borrowed'=>10,'returnedGood'=>5,'returnedDamaged'=>0],
            ['itemId'=>$a,'borrowed'=>10,'returnedGood'=>5,'returnedDamaged'=>0],
        ]);
    }
}
