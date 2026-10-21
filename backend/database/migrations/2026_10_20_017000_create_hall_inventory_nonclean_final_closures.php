<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void {
        Schema::create('hall_inventory_nonclean_final_closures',function(Blueprint $t):void {
            $t->uuid('id')->primary();
            $t->uuid('company_id');$t->uuid('loan_id');$t->uuid('hall_id');
            $t->unsignedBigInteger('receipt_number');
            $t->char('reconciliation_sha256',64);
            $t->char('pdf_sha256',64);$t->unsignedBigInteger('pdf_size');
            $t->string('pdf_storage_path',400);
            $t->foreignId('closed_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id','loan_id'],'nonclean_single_final_closure');
            $t->unique(['company_id','receipt_number'],'nonclean_unique_company_receipt');
            $t->foreign(['company_id','loan_id'],'nonclean_closure_loan_fk')
                ->references(['company_id','id'])->on('hall_inventory_loan_drafts')->restrictOnDelete();
            $t->foreign(['company_id','hall_id'],'nonclean_closure_hall_fk')
                ->references(['company_id','id'])->on('halls')->restrictOnDelete();
        });
        DB::statement('CREATE TRIGGER immutable_nonclean_final_closures BEFORE UPDATE OR DELETE ON hall_inventory_nonclean_final_closures FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()');
    }
    public function down():void {
        if(DB::table('hall_inventory_nonclean_final_closures')->exists())
            throw new RuntimeException('Immutable numbered non-clean closure records cannot be rolled back while populated.');
        Schema::dropIfExists('hall_inventory_nonclean_final_closures');
    }
};