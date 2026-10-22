<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Evidence of a partner payment against an approved provisional EXTERNAL_IN
 * loss obligation, plus independent verification. No ledger journal, cash
 * mutation, invoice, waiver or loan closure.
 */
return new class extends Migration {
    public function up():void {
        Schema::table('hall_inventory_external_in_loss_obligation_decisions',function(Blueprint $t):void {
            $t->unique(['company_id','obligation_id','id'],'ext_in_obligation_decision_scope');
        });
        Schema::create('hall_inventory_external_in_loss_settlements',function(Blueprint $t):void {
            $t->uuid('id')->primary();
            $t->uuid('company_id'); $t->uuid('hall_id'); $t->uuid('loan_id');
            $t->uuid('obligation_id'); $t->uuid('decision_id');
            $t->unsignedBigInteger('amount_minor');
            $t->char('currency',3);
            $t->string('method',8);
            $t->string('payment_reference',120);
            $t->timestampTz('paid_at');
            $t->char('evidence_sha256',64);
            $t->unsignedInteger('evidence_size');
            $t->string('evidence_storage_path',500);
            $t->char('fingerprint',64);
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id','obligation_id'],'ext_in_settlement_one_per_obligation');
            $t->unique(['company_id','hall_id','id'],'ext_in_settlement_scope');
            $t->foreign(['company_id','hall_id','obligation_id'],'ext_in_settlement_obligation_fk')
                ->references(['company_id','hall_id','id'])
                ->on('hall_inventory_external_in_loss_obligations')->restrictOnDelete();
            $t->foreign(['company_id','obligation_id','decision_id'],'ext_in_settlement_decision_fk')
                ->references(['company_id','obligation_id','id'])
                ->on('hall_inventory_external_in_loss_obligation_decisions')->restrictOnDelete();
        });
        Schema::create('hall_inventory_external_in_loss_settlement_reviews',function(Blueprint $t):void {
            $t->uuid('id')->primary();
            $t->uuid('company_id'); $t->uuid('hall_id'); $t->uuid('settlement_id');
            $t->string('decision',8);
            $t->string('reason',500);
            $t->char('fingerprint',64);
            $t->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id','settlement_id'],'ext_in_settlement_one_review');
            $t->foreign(['company_id','hall_id','settlement_id'],'ext_in_settlement_review_fk')
                ->references(['company_id','hall_id','id'])
                ->on('hall_inventory_external_in_loss_settlements')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE hall_inventory_external_in_loss_settlements
            ADD CONSTRAINT ext_in_settlement_valid CHECK (
                amount_minor BETWEEN 1 AND 100000000000
                AND currency ~ '^[A-Z]{3}$'
                AND method IN ('cash','bank')
                AND length(btrim(payment_reference)) BETWEEN 1 AND 120
                AND evidence_size BETWEEN 1 AND 5242880)");
        DB::statement("ALTER TABLE hall_inventory_external_in_loss_settlement_reviews
            ADD CONSTRAINT ext_in_settlement_review_valid CHECK (
                decision IN ('verified','rejected')
                AND length(reason) BETWEEN 10 AND 500)");
        foreach(['hall_inventory_external_in_loss_settlements',
            'hall_inventory_external_in_loss_settlement_reviews'] as $table)
            DB::statement("CREATE TRIGGER immutable_{$table} BEFORE UPDATE OR DELETE ON {$table}
                FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()");
    }
    public function down():void {
        if(DB::table('hall_inventory_external_in_loss_settlements')->exists())
            throw new RuntimeException('Immutable external-in loss settlements cannot be rolled back.');
        Schema::dropIfExists('hall_inventory_external_in_loss_settlement_reviews');
        Schema::dropIfExists('hall_inventory_external_in_loss_settlements');
        Schema::table('hall_inventory_external_in_loss_obligation_decisions',function(Blueprint $t):void {
            $t->dropUnique('ext_in_obligation_decision_scope');
        });
    }
};
