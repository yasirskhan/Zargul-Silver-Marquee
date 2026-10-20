<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void {
        Schema::create('hall_inventory_internal_loan_closures',function(Blueprint $t):void {
            $t->uuid('id')->primary();
            $t->uuid('company_id');
            $t->uuid('loan_id');
            $t->uuid('source_hall_id');
            $t->unsignedBigInteger('receipt_number');
            $t->char('reconciliation_sha256',64);
            $t->char('pdf_sha256',64);
            $t->unsignedInteger('pdf_size');
            $t->string('pdf_storage_path',500);
            $t->foreignId('closed_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id','loan_id'],'clean_loan_one_close');
            $t->unique(['company_id','receipt_number'],'clean_loan_receipt_sequence');
            $t->foreign(['company_id','loan_id'])
                ->references(['company_id','id'])->on('hall_inventory_loan_drafts')->restrictOnDelete();
            $t->foreign(['company_id','source_hall_id'])
                ->references(['company_id','id'])->on('halls')->restrictOnDelete();
        });
        DB::statement('CREATE TRIGGER immutable_hall_inventory_internal_loan_closures BEFORE UPDATE OR DELETE ON hall_inventory_internal_loan_closures FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()');
    }
    public function down():void {
        if(DB::table('hall_inventory_internal_loan_closures')->exists())
            throw new RuntimeException('Numbered immutable loan closure records must be archived.');
        Schema::dropIfExists('hall_inventory_internal_loan_closures');
    }
};
