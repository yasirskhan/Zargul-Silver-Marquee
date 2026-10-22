<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Immutable signed correction, never edit/delete the original finding or physical/finance journals. */
return new class extends Migration {
 public function up():void {
  Schema::table('hall_inventory_internal_physical_findings',function(Blueprint $t):void {
   $t->unique(['company_id','id','loan_id','source_hall_id'],'internal_finding_correction_scope');
  });
  Schema::create('hall_inventory_internal_finding_corrections',function(Blueprint $t):void {
   $t->uuid('id')->primary();$t->uuid('company_id');$t->uuid('loan_id');
   $t->uuid('source_hall_id');$t->uuid('finding_id');
   $t->string('reason',500);$t->char('fingerprint',64);
   $t->char('proof_sha256',64);$t->unsignedInteger('proof_size');$t->string('proof_path',500);
   $t->foreignId('proposed_by')->constrained('users')->restrictOnDelete();
   $t->timestampTz('created_at')->useCurrent();
   $t->unique(['company_id','id'],'internal_correction_identity');
   $t->unique(['company_id','finding_id'],'internal_correction_one_per_finding');
   $t->foreign(['company_id','finding_id','loan_id','source_hall_id'],
    'internal_correction_immutable_original_fk')
    ->references(['company_id','id','loan_id','source_hall_id'])
    ->on('hall_inventory_internal_physical_findings')->restrictOnDelete();
  });
  Schema::create('hall_inventory_internal_finding_correction_reviews',function(Blueprint $t):void {
   $t->uuid('id')->primary();$t->uuid('company_id');$t->uuid('correction_id');
   $t->string('decision',16);$t->string('reason',500);$t->char('fingerprint',64);
   $t->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
   $t->timestampTz('created_at')->useCurrent();
   $t->unique(['company_id','correction_id'],'internal_correction_review_once');
   $t->foreign(['company_id','correction_id'])->references(['company_id','id'])
    ->on('hall_inventory_internal_finding_corrections')->restrictOnDelete();
  });
  DB::statement("ALTER TABLE hall_inventory_internal_finding_corrections ADD CONSTRAINT internal_correction_valid CHECK (length(reason) BETWEEN 10 AND 500 AND proof_size BETWEEN 1 AND 5242880)");
  DB::statement("ALTER TABLE hall_inventory_internal_finding_correction_reviews ADD CONSTRAINT internal_correction_review_valid CHECK (decision IN ('superseded','rejected') AND length(reason) BETWEEN 10 AND 500)");
  foreach(['hall_inventory_internal_finding_corrections','hall_inventory_internal_finding_correction_reviews'] as $table)
   DB::statement("CREATE TRIGGER immutable_{$table} BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()");
 }
 public function down():void {
  if(DB::table('hall_inventory_internal_finding_corrections')->exists())
   throw new RuntimeException('Immutable signed internal finding corrections cannot be rolled back.');
  Schema::dropIfExists('hall_inventory_internal_finding_correction_reviews');
  Schema::dropIfExists('hall_inventory_internal_finding_corrections');
  Schema::table('hall_inventory_internal_physical_findings',function(Blueprint $t):void {
   $t->dropUnique('internal_finding_correction_scope');
  });
 }
};