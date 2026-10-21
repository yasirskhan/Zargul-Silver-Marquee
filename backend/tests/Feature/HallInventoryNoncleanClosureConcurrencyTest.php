<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\User;
use App\Services\HallInventoryNoncleanFinalClosureService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Genuine two-connection PostgreSQL lock test without uncommitted fixtures.
 * The final numbered NCL issuance must acquire the company lock before
 * examining the loan or allocating a company-wide receipt number.
 */
final class HallInventoryNoncleanClosureConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_a_parallel_company_lock_blocks_ncl_receipt_number_allocation(): void
    {
        $company=Company::create(['name'=>'NCL Serial Lock Venue','slug'=>'ncl-serial-lock-venue']);
        $hall=Hall::create(['company_id'=>$company->id,'name'=>'NCL Original Hall',
            'slug'=>'ncl-original-hall','capacity'=>120]);
        $owner=User::create(['name'=>'NCL Independent Owner',
            'email'=>'ncl-lock-owner@example.test','password'=>'test-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id'=>$company->id,
            'user_id'=>$owner->id,'role'=>'owner']);
        $this->be($owner);
        foreach ([
            'loan_nonclean_final_closure_enabled','loan_nonclean_reconciliation_enabled',
            'loan_nonclean_writeoffs_enabled','loan_financial_waivers_enabled',
            'loan_recoveries_enabled','loan_authorized_obligations_enabled',
            'loan_partner_responses_enabled',
            'external_out_dispatch_enabled','external_out_return_enabled'
        ] as $gate) config()->set('inventory_controls.'.$gate,true);

        config(['database.connections.second_pgsql'=>config('database.connections.pgsql')]);
        DB::purge('second_pgsql');
        $other=DB::connection('second_pgsql');
        $this->assertNotSame(DB::connection()->getPdo(),$other->getPdo());
        $loanId=(string)Str::uuid();
        $service=app(HallInventoryNoncleanFinalClosureService::class);
        $other->beginTransaction();
        try {
            $locked=$other->table('companies')->where('id',$company->id)
                ->lockForUpdate()->first(['id']);
            $this->assertSame($company->id,$locked->id);
            DB::statement("SET lock_timeout = '300ms'");
            try {
                $service->close($owner,$company->id,$hall->id,$loanId,(string)Str::uuid());
                $this->fail('A competing closure bypassed the company serial lock.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('lock timeout',strtolower($e->getMessage()));
            }
            $this->assertSame(0,$other->table('hall_inventory_nonclean_final_closures')
                ->where('company_id',$company->id)->count());
        } finally {
            DB::statement('SET lock_timeout TO DEFAULT');
            $other->rollBack();
            DB::disconnect('second_pgsql');
        }

        // Once the lock is released, normal company authorization and exact
        // source-loan checks run, without manufacturing a receipt or document.
        $this->expectException(AuthorizationException::class);
        $service->close($owner,$company->id,$hall->id,$loanId,(string)Str::uuid());
    }
}
