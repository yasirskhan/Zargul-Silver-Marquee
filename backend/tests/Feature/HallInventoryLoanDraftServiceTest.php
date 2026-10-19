<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\HallAssignment;
use App\Models\User;
use App\Services\HallInventoryCatalogService;
use App\Services\HallInventoryLoanDraftService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class HallInventoryLoanDraftServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $company = Company::create(['name'=>'Loan Inventory Business', 'slug'=>'loan-inventory-business']);
        $hallA = Hall::create(['company_id'=>$company->id,'name'=>'Hall A',
            'slug'=>'loan-hall-a','capacity'=>500]);
        $hallB = Hall::create(['company_id'=>$company->id,'name'=>'Hall B',
            'slug'=>'loan-hall-b','capacity'=>300]);
        $owner = User::create(['name'=>'Owner', 'email'=>'loan-owner@example.test', 'password'=>'test-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id'=>$company->id,'user_id'=>$owner->id,'role'=>'owner']);
        $manager = User::create(['name'=>'Manager', 'email'=>'loan-manager@example.test', 'password'=>'test-only']);
        $manager->markEmailAsVerified();
        $membership = CompanyMembership::create(['company_id'=>$company->id,
            'user_id'=>$manager->id,'role'=>'manager']);
        HallAssignment::create(['company_id'=>$company->id,
            'membership_id'=>$membership->id, 'hall_id'=>$hallA->id]);
        $this->be($owner);
        $catalog = app(HallInventoryCatalogService::class);
        $chair=(string) Str::uuid(); $table=(string) Str::uuid(); $glass=(string) Str::uuid();
        $catalog->create($owner,$company->id,$hallA->id,$chair,'Chairs','Furniture',
            'piece',500,'Hall A opening item');
        $catalog->create($owner,$company->id,$hallA->id,$table,'Tables','Furniture',
            'piece',50,'Hall A opening item');
        $catalog->create($owner,$company->id,$hallA->id,$glass,'Glasses','Glassware',
            'piece',300,'Hall A opening item');
        return [$company,$hallA,$hallB,$owner,$manager,$chair,$table,$glass];
    }

    public function test_internal_multiple_item_loan_draft_is_immutable_and_does_not_transfer_stock(): void
    {
        [$company,$a,$b,$owner,,$chair,$table,$glass]=$this->fixture();
        config()->set('inventory_controls.loan_drafts_enabled',true);
        $rows=[
            ['itemId'=>$chair,'quantity'=>50],
            ['itemId'=>$table,'quantity'=>10],
            ['itemId'=>$glass,'quantity'=>100],
        ];
        $id=(string) Str::uuid();
        $svc=app(HallInventoryLoanDraftService::class);
        $this->assertSame($id,$svc->create($owner,$company->id,$id,
            'internal',$a->id,$b->id,$rows,'2027-01-20','Hall B evening celebration'));
        $this->assertSame($id,$svc->create($owner,$company->id,$id,
            'internal',$a->id,$b->id,array_reverse($rows),'2027-01-20','Hall B evening celebration'));
        $this->assertSame(1,DB::table('hall_inventory_loan_drafts')->count());
        $this->assertSame(3,DB::table('hall_inventory_loan_draft_lines')->count());
        $this->assertSame(50,(int) DB::table('hall_inventory_loan_draft_lines')
            ->where('item_id',$chair)->value('requested_quantity'));
        $this->assertSame(1,DB::table('audit_events')
            ->where('action','hall_inventory.loan_draft_created')->count());
        $this->assertSame(0,DB::table('finance_journals')->count());
    }

    public function test_external_in_loan_keeps_partner_contact_encrypted_and_does_not_add_owned_stock(): void
    {
        [$company,$a,$b,$owner]=$this->fixture();
        config()->set('inventory_controls.loan_drafts_enabled',true);
        $jugs=(string) Str::uuid();
        app(HallInventoryCatalogService::class)->create($owner,$company->id,$b->id,
            $jugs,'Water Jugs','Serving','piece',30,'Hall B borrowed item catalog');
        $id=(string) Str::uuid();
        app(HallInventoryLoanDraftService::class)->create(
            $owner,$company->id,$id,'external_in',null,$b->id,
            [['itemId'=>$jugs,'quantity'=>20]],'2027-03-01','Outside catering borrow',
            'Royal Marriage Hall','Outside Manager','0300-555-9999','Example Road');
        $partner=DB::table('hall_inventory_external_partners')->first();
        $this->assertSame('Royal Marriage Hall',$partner->name);
        $this->assertStringNotContainsString('0300-555-9999',$partner->phone_ciphertext);
        $this->assertSame('external_in',DB::table('hall_inventory_loan_drafts')
            ->where('id',$id)->value('direction'));
        $this->assertSame(0,DB::table('finance_journals')->count());
    }

    public function test_loan_creation_gate_is_disabled_by_default(): void
    {
        [$company,$a,$b,$owner,,$chair]=$this->fixture();
        $this->expectException(ConflictHttpException::class);
        app(HallInventoryLoanDraftService::class)->create($owner,$company->id,
            (string) Str::uuid(),'internal',$a->id,$b->id,
            [['itemId'=>$chair,'quantity'=>10]],'2027-01-20','Gate disabled');
    }

    public function test_unassigned_manager_and_conflicting_retry_are_denied(): void
    {
        [$company,$a,$b,$owner,$manager,$chair]=$this->fixture();
        config()->set('inventory_controls.loan_drafts_enabled',true);
        $id=(string) Str::uuid();
        $svc=app(HallInventoryLoanDraftService::class);
        $svc->create($owner,$company->id,$id,'internal',$a->id,$b->id,
            [['itemId'=>$chair,'quantity'=>50]],'2027-01-20','Managed hall loan');
        try {
            $svc->create($owner,$company->id,$id,'internal',$a->id,$b->id,
                [['itemId'=>$chair,'quantity'=>40]],'2027-01-20','Managed hall loan');
            $this->fail('Changed request accepted.');
        } catch (ConflictHttpException) {}
        $this->be($manager);
        $this->expectException(AuthorizationException::class);
        $svc->create($manager,$company->id,(string) Str::uuid(),
            'external_out',$b->id,null,[['itemId'=>$chair,'quantity'=>50]],
            '2027-01-20','Unassigned hall forbidden','Outside Hall');
    }

    public function test_manager_of_lending_hall_can_prepare_internal_request_not_dispatch_it(): void
    {
        [$company,$a,$b,$owner,$manager,$chair]=$this->fixture();
        config()->set('inventory_controls.loan_drafts_enabled',true);
        $this->be($manager);
        $id=(string) Str::uuid();
        $this->assertSame($id,app(HallInventoryLoanDraftService::class)->create(
            $manager,$company->id,$id,'internal',$a->id,$b->id,
            [['itemId'=>$chair,'quantity'=>25]],'2027-01-20','Source manager request'));
        $this->assertSame(1,DB::table('hall_inventory_loan_drafts')->count());
        $this->assertSame(0,DB::table('finance_journals')->count());
    }

    public function test_sql_cannot_insert_item_from_wrong_hall_into_existing_loan(): void
    {
        [$company,$a,$b,$owner,,$chair]=$this->fixture();
        config()->set('inventory_controls.loan_drafts_enabled',true);
        $otherId=(string) Str::uuid();
        app(HallInventoryCatalogService::class)->create($owner,$company->id,$b->id,
            $otherId,'Water Jugs','Serving','piece',10,'Hall B catalog');
        $id=(string) Str::uuid();
        app(HallInventoryLoanDraftService::class)->create(
            $owner,$company->id,$id,'internal',$a->id,$b->id,
            [['itemId'=>$chair,'quantity'=>25]],'2027-01-20','Hall A lending request');
        $this->expectException(QueryException::class);
        DB::table('hall_inventory_loan_draft_lines')->insert([
            'id'=>(string) Str::uuid(), 'company_id'=>$company->id,
            'loan_id'=>$id,'catalog_hall_id'=>$b->id,'item_id'=>$otherId,
            'requested_quantity'=>5,'item_name_snapshot'=>'Water Jugs',
            'unit_snapshot'=>'piece',
        ]);
    }

    public function test_immutable_loan_line_cannot_be_deleted_or_modified_by_sql(): void
    {
        [$company,$a,$b,$owner,,$chair]=$this->fixture();
        config()->set('inventory_controls.loan_drafts_enabled',true);
        $id=(string) Str::uuid();
        app(HallInventoryLoanDraftService::class)->create(
            $owner,$company->id,$id,'internal',$a->id,$b->id,
            [['itemId'=>$chair,'quantity'=>50]],'2027-01-20','Immutable loan evidence');
        $this->expectException(QueryException::class);
        DB::table('hall_inventory_loan_draft_lines')->where('loan_id',$id)->delete();
    }
    public function test_read_only_loan_review_is_hall_scoped_and_preserves_item_snapshots(): void
    {
        [$company,$a,$b,$owner,$manager,$chair,$table]=$this->fixture();
        config()->set('inventory_controls.loan_drafts_enabled',true);
        $service=app(HallInventoryLoanDraftService::class);
        $loan=(string) Str::uuid();
        $service->create($owner,$company->id,$loan,'internal',$a->id,$b->id,
            [['itemId'=>$chair,'quantity'=>50],['itemId'=>$table,'quantity'=>10]],
            '2027-05-18','Borrow seats for wedding');
        $review=app(\App\Services\HallInventoryLoanDraftReview::class);
        $items=$review->recent($owner,$company->id,$a->id);
        $this->assertCount(1,$items);
        $this->assertSame($loan,$items[0]['id']);
        $this->assertSame('Hall A',$items[0]['lendingHallName']);
        $this->assertSame('Hall B',$items[0]['borrowingHallName']);
        $this->assertSame('draft_only',$items[0]['status']);
        $this->assertFalse($items[0]['stockMoved']);
        $this->assertCount(2,$items[0]['lines']);
        $this->assertEqualsCanonicalizing([10,50],array_column($items[0]['lines'],'quantity'));
        // A receiving hall can inspect an immutable incoming draft, not dispatch it.
        $incoming=$review->recent($owner,$company->id,$b->id);
        $this->assertCount(1,$incoming);
        $this->assertSame('receiving',$incoming[0]['viewRole']);
        $this->assertSame('draft_only',$incoming[0]['status']);
        $this->assertFalse($incoming[0]['stockMoved']);
        $this->be($manager);
        $this->assertCount(1,$review->recent($manager,$company->id,$a->id));
        try{
            $review->recent($manager,$company->id,$b->id);
            $this->fail('Unassigned manager read the receiving hall.');
        }catch(AuthorizationException){}
        $this->assertSame(0,DB::table('finance_journals')->count());
    }


    public function test_assigned_destination_manager_can_view_internal_incoming_draft_only():void
    {
        [$company,$a,$b,$owner,$sourceManager,$chair]=$this->fixture();
        config()->set('inventory_controls.loan_drafts_enabled',true);
        $loan=(string)Str::uuid();
        app(HallInventoryLoanDraftService::class)->create($owner,$company->id,$loan,
            'internal',$a->id,$b->id,[['itemId'=>$chair,'quantity'=>15]],
            '2027-05-20','Borrow chairs for incoming event');
        $dest=User::create(['name'=>'Receiving Manager',
            'email'=>'receiving-manager@example.test','password'=>'test-only']);
        $dest->markEmailAsVerified();
        $membership=CompanyMembership::create(['company_id'=>$company->id,
            'user_id'=>$dest->id,'role'=>'manager']);
        HallAssignment::create(['company_id'=>$company->id,
            'membership_id'=>$membership->id,'hall_id'=>$b->id]);
        $this->be($dest);
        $review=app(\App\Services\HallInventoryLoanDraftReview::class);
        $incoming=$review->recent($dest,$company->id,$b->id);
        $this->assertCount(1,$incoming);
        $this->assertSame($loan,$incoming[0]['id']);
        $this->assertSame('receiving',$incoming[0]['viewRole']);
        $this->assertFalse($incoming[0]['stockMoved']);
        try {
            $review->recent($dest,$company->id,$a->id);
            $this->fail('Receiving manager inspected unassigned source hall.');
        } catch(AuthorizationException) {}
        DB::table('hall_assignments')->where('membership_id',$membership->id)->delete();
        try {
            $review->recent($dest,$company->id,$b->id);
            $this->fail('Revoked destination hall assignment still read incoming draft.');
        } catch(AuthorizationException) {}
        $this->assertSame(0,DB::table('hall_inventory_positions')->count());
        $this->assertSame(0,DB::table('hall_inventory_movement_events')->count());
        $this->assertSame(0,DB::table('finance_journals')->count());
    }

    public function test_external_draft_review_excludes_ciphertext_and_rejects_revocation():void
    {
        [$company,$a,$b,$owner,$manager]=$this->fixture();
        config()->set('inventory_controls.loan_drafts_enabled',true);
        $jugs=(string)Str::uuid();
        app(HallInventoryCatalogService::class)->create($owner,$company->id,
            $b->id,$jugs,'Water Jugs','Serving','piece',30,'Outside hall catalog');
        app(HallInventoryLoanDraftService::class)->create(
            $owner,$company->id,(string)Str::uuid(),'external_in',null,$b->id,
            [['itemId'=>$jugs,'quantity'=>20]],'2027-05-18','Borrow water jugs',
            'Outside Venue','Manager Secret','0300-555-1234','External Road');
        $review=app(\App\Services\HallInventoryLoanDraftReview::class);
        $summary=$review->recent($owner,$company->id,$b->id);
        $this->assertCount(1,$summary);
        $this->assertSame('Outside Venue',$summary[0]['externalPartnerName']);
        $this->assertStringNotContainsString('Manager Secret',json_encode($summary));
        $this->assertStringNotContainsString('0300-555-1234',json_encode($summary));
        $this->assertFalse($summary[0]['stockMoved']);
        $this->expectException(AuthorizationException::class);
        $this->be($manager);
        $review->recent($manager,$company->id,$b->id);
    }

    public function test_loan_review_never_crosses_company_and_rejects_unbounded_requests():void
    {
        [$company,$a,$b,$owner,$manager,$chair]=$this->fixture();
        config()->set('inventory_controls.loan_drafts_enabled',true);
        $review=app(\App\Services\HallInventoryLoanDraftReview::class);
        $this->assertSame([],$review->recent($owner,$company->id,$a->id,20));
        try {
            $review->recent($owner,$company->id,$a->id,21);
            $this->fail('Unbounded inventory draft view was accepted.');
        } catch (\InvalidArgumentException) {}
        $foreign=Company::create(['name'=>'Other Business','slug'=>'other-loan-business']);
        $otherHall=Hall::create(['company_id'=>$foreign->id,
            'name'=>'Foreign Hall','slug'=>'foreign-inventory-hall','capacity'=>200]);
        $this->expectException(AuthorizationException::class);
        $review->recent($owner,$foreign->id,$otherHall->id);
    }

    public function test_loan_review_release_gate_and_revoked_hall_assignment():void
    {
        [$company,$a,$b,$owner,$manager,$chair]=$this->fixture();
        $review=app(\App\Services\HallInventoryLoanDraftReview::class);
        $this->expectException(ConflictHttpException::class);
        $review->recent($owner,$company->id,$a->id);
    }

    public function test_loan_review_rechecks_persisted_hall_assignment():void
    {
        [$company,$a,$b,$owner,$manager,$chair]=$this->fixture();
        config()->set('inventory_controls.loan_drafts_enabled',true);
        $this->be($manager);
        $review=app(\App\Services\HallInventoryLoanDraftReview::class);
        $this->assertSame([],$review->recent($manager,$company->id,$a->id));
        DB::table('company_memberships')->where('user_id',$manager->id)
            ->update(['is_active'=>false]);
        $this->expectException(AuthorizationException::class);
        $review->recent($manager,$company->id,$a->id);
    }

}
