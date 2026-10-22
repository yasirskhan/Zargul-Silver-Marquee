<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Private INTERNAL settlement evidence and independent verification.
 * Evidence only: no journal, cash mutation by this layer, waiver, stock
 * retirement or loan closure.
 */
return new class extends Migration {
    public function up():void {
        Schema::create('hall_inventory_internal_loss_settlements',function(Blueprint $t):void {
            $t->uuid('id')->primary();
            $t->uuid('company_id'); $t->uuid('obligation_id');
            $t->uuid('source_hall_id'); $t->uuid('borrowing_hall_id');
            $t->unsignedBigInteger('amount_minor'); $t->char('currency',3);
            $t->string('method',8); $t->string('reference',100);
            $t->date('paid_on');
            $t->string('proof_path',500); $t->char('proof_sha256',64);
            $t->unsignedInteger('proof_size');
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id','obligation_id'],'internal_settlement_one_per_obligation');
            $t->unique(['company_id','id'],'internal_settlement_identity');
            $t->foreign(['company_id','obligation_id'],'internal_settlement_obligation_fk')
                ->references(['company_id','id'])
                ->on('hall_inventory_internal_loss_obligations')->restrictOnDelete();
            $t->foreign(['company_id','source_hall_id'])->references(['company_id','id'])
                ->on('halls')->restrictOnDelete();
            $t->foreign(['company_id','borrowing_hall_id'])->references(['company_id','id'])
                ->on('halls')->restrictOnDelete();
        });
        Schema::create('hall_inventory_internal_loss_settlement_reviews',function(Blueprint $t):void {
            $t->uuid('id')->primary(); $t->uuid('company_id'); $t->uuid('settlement_id');
            $t->string('decision',16); $t->string('reason',500); $t->char('fingerprint',64);
            $t->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id','settlement_id'],'internal_settlement_one_review');
            $t->foreign(['company_id','settlement_id'])->references(['company_id','id'])
                ->on('hall_inventory_internal_loss_settlements')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE hall_inventory_internal_loss_settlements
            ADD CONSTRAINT internal_settlement_valid CHECK (
                amount_minor BETWEEN 1 AND 100000000000
                AND currency ~ '^[A-Z]{3}$'
                AND method IN ('cash','bank')
                AND length(reference) BETWEEN 3 AND 100
                AND proof_size BETWEEN 1 AND 5242880)");
        DB::statement("ALTER TABLE hall_inventory_internal_loss_settlement_reviews
            ADD CONSTRAINT internal_settlement_review_valid CHECK (
                decision IN ('verified','rejected') AND length(reason) BETWEEN 10 AND 500)");
        foreach(['hall_inventory_internal_loss_settlements',
            'hall_inventory_internal_loss_settlement_reviews'] as $table)
            DB::statement("CREATE TRIGGER immutable_{$table} BEFORE UPDATE OR DELETE ON {$table}
                FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()");
    }
    public function down():void {
        if(DB::table('hall_inventory_internal_loss_settlements')->exists())
            throw new RuntimeException('Immutable internal settlement evidence cannot be rolled back.');
        Schema::dropIfExists('hall_inventory_internal_loss_settlement_reviews');
        Schema::dropIfExists('hall_inventory_internal_loss_settlements');
    }
};
