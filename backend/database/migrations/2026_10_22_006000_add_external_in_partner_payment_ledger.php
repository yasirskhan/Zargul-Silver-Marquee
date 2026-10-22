<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gated balanced ledger posting for a verified EXTERNAL_IN partner payment:
 * debit inventory_partner_loss, credit cash_on_hand/bank_clearing. Reversal
 * swaps the lines. Posting remains behind finance_controls.posting_enabled.
 */
return new class extends Migration {
    public function up():void {
        DB::statement('ALTER TABLE finance_journals DROP CONSTRAINT finance_journal_type');
        DB::statement("ALTER TABLE finance_journals ADD CONSTRAINT finance_journal_type
            CHECK (entry_type IN ('opening_cash','reversal','customer_deposit',
                'inventory_recovery','inventory_partner_payment'))");
        DB::statement('ALTER TABLE finance_journal_lines DROP CONSTRAINT finance_line_account');
        DB::statement("ALTER TABLE finance_journal_lines ADD CONSTRAINT finance_line_account
            CHECK (account_code IN ('cash_on_hand','opening_equity','bank_clearing',
                'customer_deposit_liability','inventory_loss_recovery_income',
                'inventory_partner_loss'))");
        Schema::table('hall_inventory_external_in_loss_settlement_reviews',function(Blueprint $t):void {
            $t->uuid('journal_id')->nullable();
            $t->foreign(['company_id','journal_id'],'ext_in_settlement_review_journal_fk')
                ->references(['company_id','id'])->on('finance_journals')->restrictOnDelete();
        });
        Schema::create('hall_inventory_external_in_loss_settlement_reversals',function(Blueprint $t):void {
            $t->uuid('id')->primary();
            $t->uuid('company_id'); $t->uuid('hall_id'); $t->uuid('settlement_id');
            $t->uuid('original_journal_id'); $t->uuid('reversal_journal_id');
            $t->string('reason',500);
            $t->foreignId('reversed_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id','settlement_id'],'ext_in_settlement_one_reversal');
            $t->unique('reversal_journal_id','ext_in_settlement_reversal_journal_unique');
            $t->foreign(['company_id','hall_id','settlement_id'],'ext_in_settlement_reversal_settlement_fk')
                ->references(['company_id','hall_id','id'])
                ->on('hall_inventory_external_in_loss_settlements')->restrictOnDelete();
            $t->foreign(['company_id','original_journal_id'],'ext_in_settlement_reversal_original_fk')
                ->references(['company_id','id'])->on('finance_journals')->restrictOnDelete();
            $t->foreign(['company_id','reversal_journal_id'],'ext_in_settlement_reversal_journal_fk')
                ->references(['company_id','id'])->on('finance_journals')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE hall_inventory_external_in_loss_settlement_reversals
            ADD CONSTRAINT ext_in_settlement_reversal_valid CHECK (length(reason) BETWEEN 15 AND 500)");
        DB::statement('CREATE TRIGGER immutable_hall_inventory_external_in_loss_settlement_reversals
            BEFORE UPDATE OR DELETE ON hall_inventory_external_in_loss_settlement_reversals
            FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()');
    }
    public function down():void {
        if(DB::table('hall_inventory_external_in_loss_settlements')->exists())
            throw new RuntimeException('External-in partner settlements cannot be rolled back.');
        Schema::dropIfExists('hall_inventory_external_in_loss_settlement_reversals');
        Schema::table('hall_inventory_external_in_loss_settlement_reviews',function(Blueprint $t):void {
            $t->dropForeign('ext_in_settlement_review_journal_fk');
            $t->dropColumn('journal_id');
        });
        DB::statement('ALTER TABLE finance_journal_lines DROP CONSTRAINT finance_line_account');
        DB::statement("ALTER TABLE finance_journal_lines ADD CONSTRAINT finance_line_account
            CHECK (account_code IN ('cash_on_hand','opening_equity','bank_clearing',
                'customer_deposit_liability','inventory_loss_recovery_income'))");
        DB::statement('ALTER TABLE finance_journals DROP CONSTRAINT finance_journal_type');
        DB::statement("ALTER TABLE finance_journals ADD CONSTRAINT finance_journal_type
            CHECK (entry_type IN ('opening_cash','reversal','customer_deposit','inventory_recovery'))");
    }
};
