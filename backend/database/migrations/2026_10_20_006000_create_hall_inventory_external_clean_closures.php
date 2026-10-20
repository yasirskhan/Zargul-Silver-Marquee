<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void {
        // Require the closure's hall to be the exact hall of the original
        // independently approved external borrowing, not any hall in tenant.
        DB::statement('ALTER TABLE hall_inventory_external_in_acceptances ADD CONSTRAINT ext_in_accept_clean_scope UNIQUE (company_id,loan_id,hall_id)');
        Schema::create('hall_inventory_external_in_clean_closures',function(Blueprint $t):void {
            $t->uuid('id')->primary();
            $t->uuid('company_id'); $t->uuid('loan_id'); $t->uuid('hall_id');
            $t->unsignedBigInteger('receipt_number');
            $t->char('reconciliation_sha256',64);
            $t->char('pdf_sha256',64); $t->unsignedInteger('pdf_size');
            $t->string('pdf_storage_path',500);
            $t->foreignId('closed_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id','loan_id'],'ext_borrowed_clean_one_close');
            $t->unique(['company_id','receipt_number'],'ext_borrowed_clean_number');
            $t->unique(['company_id','hall_id','id'],'ext_borrowed_clean_scope');
            $t->foreign(['company_id','loan_id','hall_id'],'ext_in_clean_exact_acceptance_fk')
                ->references(['company_id','loan_id','hall_id'])
                ->on('hall_inventory_external_in_acceptances')->restrictOnDelete();
            $t->foreign(['company_id','hall_id'])->references(['company_id','id'])
                ->on('halls')->restrictOnDelete();
        });
        DB::statement('CREATE TRIGGER immutable_hall_inventory_external_in_clean_closures BEFORE UPDATE OR DELETE
            ON hall_inventory_external_in_clean_closures FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()');
    }
    public function down():void {
        if(DB::table('hall_inventory_external_in_clean_closures')->exists())
            throw new RuntimeException('Immutable numbered partner-loan closeouts require archival before rollback.');
        Schema::dropIfExists('hall_inventory_external_in_clean_closures');
        DB::statement('ALTER TABLE hall_inventory_external_in_acceptances DROP CONSTRAINT IF EXISTS ext_in_accept_clean_scope');
    }
};
