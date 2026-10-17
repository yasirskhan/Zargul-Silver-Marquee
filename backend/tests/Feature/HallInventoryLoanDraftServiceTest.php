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
}
