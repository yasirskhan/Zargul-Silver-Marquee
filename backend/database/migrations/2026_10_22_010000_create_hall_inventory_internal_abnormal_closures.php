<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0009 Option A: separate INTERNAL abnormal write-off authorization and
 * numbered immutable final certificate. No company-level journal.
 */
return new class extends Migration {
    public function up():void {
        Schema::create('hall_inventory_internal_abnormal_writeoff_authorizations',function(Blueprint $t):void {
            $t->uuid('id')->primary();
            $t->uuid('company_id'); $t->uuid('loan_id');
            $t->uuid('source_hall_id'); $t->uuid('borrowing_hall_id');
            $t->unsignedInteger('authorized_quantity');
            $t->string('reason',500); $t->char('fingerprint',64);
            $t->foreignId('authorized_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id','loan_id'],'internal_abnormal_one_authorization');
            $t->unique(['company_id','id'],'internal_abnormal_authorization_identity');
            $t->foreign(['company_id','source_hall_id'],'internal_abnormal_auth_source_fk')
                ->references(['company_id','id'])->on('halls')->restrictOnDelete();
            $t->foreign(['company_id','borrowing_hall_id'],'internal_abnormal_auth_borrow_fk')
                ->references(['company_id','id'])->on('halls')->restrictOnDelete();
        });
        Schema::create('hall_inventory_internal_abnormal_closures',function(Blueprint $t):void {
            $t->uuid('id')->primary();
            $t->uuid('company_id'); $t->uuid('loan_id');
            $t->uuid('source_hall_id'); $t->uuid('borrowing_hall_id');
            $t->uuid('authorization_id');
            $t->unsignedInteger('serial_seq'); $t->string('serial_number',40);
            $t->char('currency',3); $t->unsignedBigInteger('amount_minor');
            $t->unsignedInteger('writeoff_quantity');
            $t->char('pdf_sha256',64); $t->unsignedInteger('pdf_size');
            $t->string('pdf_path',500); $t->jsonb('readiness');
            $t->foreignId('closed_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id','loan_id'],'internal_abnormal_one_close');
            $t->unique(['company_id','serial_seq'],'internal_abnormal_sequence');
            $t->unique(['company_id','serial_number'],'internal_abnormal_number');
            $t->unique(['company_id','source_hall_id','id'],'internal_abnormal_scope');
            $t->foreign(['company_id','authorization_id'],'internal_abnormal_authorization_fk')
                ->references(['company_id','id'])
                ->on('hall_inventory_internal_abnormal_writeoff_authorizations')->restrictOnDelete();
            $t->foreign(['company_id','source_hall_id'],'internal_abnormal_close_source_fk')
                ->references(['company_id','id'])->on('halls')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE hall_inventory_internal_abnormal_writeoff_authorizations
            ADD CONSTRAINT internal_abnormal_authorization_valid CHECK (
                authorized_quantity BETWEEN 1 AND 100000000
                AND length(reason) BETWEEN 10 AND 500)");
        DB::statement("ALTER TABLE hall_inventory_internal_abnormal_closures
            ADD CONSTRAINT internal_abnormal_closure_valid CHECK (
                serial_seq BETWEEN 1 AND 1000000000
                AND currency ~ '^[A-Z]{3}$'
                AND amount_minor BETWEEN 1 AND 100000000000
                AND writeoff_quantity BETWEEN 1 AND 100000000
                AND pdf_size BETWEEN 1 AND 5242880
                AND length(serial_number) BETWEEN 1 AND 40)");
        foreach(['hall_inventory_internal_abnormal_writeoff_authorizations',
            'hall_inventory_internal_abnormal_closures'] as $table)
            DB::statement("CREATE TRIGGER immutable_{$table} BEFORE UPDATE OR DELETE ON {$table}
                FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()");
    }
    public function down():void {
        if(DB::table('hall_inventory_internal_abnormal_closures')->exists() ||
            DB::table('hall_inventory_internal_abnormal_writeoff_authorizations')->exists())
            throw new RuntimeException('Immutable internal abnormal closures cannot be rolled back.');
        Schema::dropIfExists('hall_inventory_internal_abnormal_closures');
        Schema::dropIfExists('hall_inventory_internal_abnormal_writeoff_authorizations');
    }
};
