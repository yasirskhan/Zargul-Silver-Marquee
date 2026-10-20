<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void {
        Schema::table('hall_inventory_external_out_dispatches',function(Blueprint $t):void{
            $t->unique(['company_id','loan_id','hall_id'],'ext_out_clean_exact_dispatch_scope');
        });
        Schema::create('hall_inventory_external_out_clean_closures',function(Blueprint $t):void{
            $t->uuid('id')->primary();
            $t->uuid('company_id');$t->uuid('loan_id');$t->uuid('hall_id');
            $t->unsignedBigInteger('receipt_number');
            $t->char('reconciliation_sha256',64);
            $t->char('pdf_sha256',64);$t->unsignedInteger('pdf_size');
            $t->string('pdf_storage_path',500);
            $t->foreignId('closed_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id','loan_id'],'ext_out_clean_one_loan');
            $t->unique(['company_id','receipt_number'],'ext_out_clean_one_number');
            $t->unique(['company_id','hall_id','id'],'ext_out_clean_tenant_scope');
            $t->foreign(['company_id','loan_id','hall_id'],'ext_out_clean_exact_source_dispatch_fk')
                ->references(['company_id','loan_id','hall_id'])
                ->on('hall_inventory_external_out_dispatches')->restrictOnDelete();
        });
        DB::statement('CREATE TRIGGER immutable_hall_inventory_external_out_clean_closures BEFORE UPDATE OR DELETE ON hall_inventory_external_out_clean_closures FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()');
    }
    public function down():void {
        if(DB::table('hall_inventory_external_out_clean_closures')->exists())
            throw new RuntimeException('Signed external owned loan closeout receipts require archival before rollback.');
        Schema::dropIfExists('hall_inventory_external_out_clean_closures');
        Schema::table('hall_inventory_external_out_dispatches',function(Blueprint $t):void{
            $t->dropUnique('ext_out_clean_exact_dispatch_scope');
        });
    }
};