<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::table('hall_inventory_internal_loan_receipt_lines',function(Blueprint $t):void {
   $t->unique(['company_id','receipt_id','source_item_id'],'internal_discrepancy_receipt_line_scope');
  });
  Schema::create('hall_inventory_internal_discrepancy_statements',function(Blueprint $t):void {
   $t->uuid('id')->primary();$t->uuid('company_id');$t->uuid('loan_id');$t->uuid('receipt_id');
   $t->uuid('borrowing_hall_id');$t->uuid('source_item_id');
   $t->string('kind',20);$t->unsignedInteger('quantity');$t->string('reason',500);
   $t->char('fingerprint',64);$t->char('evidence_sha256',64);$t->unsignedInteger('evidence_size');
   $t->string('evidence_storage_path',500);$t->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
   $t->timestampTz('created_at')->useCurrent();
   $t->unique(['company_id','id'],'internal_discrepancy_statement_identity');
   $t->unique(['company_id','id','loan_id','borrowing_hall_id'],'internal_discrepancy_statement_scope');
   $t->foreign(['company_id','loan_id'])->references(['company_id','id'])->on('hall_inventory_loan_drafts')->restrictOnDelete();
   $t->foreign(['company_id','receipt_id','borrowing_hall_id'],'internal_discrepancy_receipt_fk')
    ->references(['company_id','id','receiving_hall_id'])->on('hall_inventory_internal_loan_receipts')->restrictOnDelete();
   $t->foreign(['company_id','receipt_id','source_item_id'],'internal_discrepancy_line_fk')
    ->references(['company_id','receipt_id','source_item_id'])->on('hall_inventory_internal_loan_receipt_lines')->restrictOnDelete();
  });
  Schema::create('hall_inventory_internal_discrepancy_decisions',function(Blueprint $t):void {
   $t->uuid('id')->primary();$t->uuid('company_id');$t->uuid('statement_id');
   $t->string('decision',24);$t->string('reason',500);$t->char('fingerprint',64);
   $t->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();$t->timestampTz('created_at')->useCurrent();
   $t->unique(['company_id','statement_id'],'internal_discrepancy_review_once');
   $t->foreign(['company_id','statement_id'])->references(['company_id','id'])
    ->on('hall_inventory_internal_discrepancy_statements')->restrictOnDelete();
  });
  DB::statement("ALTER TABLE hall_inventory_internal_discrepancy_statements ADD CONSTRAINT internal_discrepancy_statement_valid CHECK (kind IN ('missing','dispute') AND quantity BETWEEN 1 AND 100000000 AND length(reason) BETWEEN 10 AND 500)");
  DB::statement("ALTER TABLE hall_inventory_internal_discrepancy_decisions ADD CONSTRAINT internal_discrepancy_decision_valid CHECK (decision IN ('corroborated','disputed','requires_more_evidence') AND length(reason) BETWEEN 10 AND 500)");
  foreach(['hall_inventory_internal_discrepancy_statements','hall_inventory_internal_discrepancy_decisions'] as $table)
   DB::statement("CREATE TRIGGER immutable_{$table} BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()");
 }
 public function down():void {
  if(DB::table('hall_inventory_internal_discrepancy_statements')->exists())throw new RuntimeException('Immutable signed discrepancy evidence cannot be rolled back.');
  Schema::dropIfExists('hall_inventory_internal_discrepancy_decisions');
  Schema::dropIfExists('hall_inventory_internal_discrepancy_statements');
 }
};