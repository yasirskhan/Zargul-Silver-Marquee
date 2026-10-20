<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void {
        Schema::table('hall_inventory_financial_obligations',function(Blueprint $t):void {
            $t->unique(['company_id','id','claim_id','loan_id','source_hall_id','currency'],
                'fin_recovery_obligation_scope');
        });
        Schema::create('hall_inventory_financial_recovery_receipts',function(Blueprint $t):void {
            $t->uuid('id')->primary();
            $t->uuid('company_id');$t->uuid('obligation_id');$t->uuid('claim_id');
            $t->uuid('loan_id');$t->uuid('source_hall_id');$t->char('currency',3);
            $t->unsignedBigInteger('amount_minor');$t->string('method',12);
            $t->string('external_reference',120)->nullable();
            $t->char('evidence_sha256',64);$t->unsignedInteger('evidence_size');
            $t->string('evidence_mime',40);$t->string('evidence_path',500);
            $t->char('fingerprint',64);
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id','id','obligation_id'],'fin_recovery_exact_receipt');
            $t->unique(['company_id','method','external_reference'],'fin_recovery_unique_bank_ref');
            $t->index(['company_id','loan_id','source_hall_id']);
            $t->foreign(['company_id','obligation_id','claim_id','loan_id','source_hall_id','currency'],
                'fin_recovery_exact_obligation_fk')
                ->references(['company_id','id','claim_id','loan_id','source_hall_id','currency'])
                ->on('hall_inventory_financial_obligations')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE hall_inventory_financial_recovery_receipts
            ADD CONSTRAINT fin_recovery_receipt_check CHECK (
                amount_minor BETWEEN 1 AND 100000000000
                AND method IN ('cash','bank')
                AND ((method='cash' AND external_reference IS NULL) OR
                     (method='bank' AND length(external_reference) BETWEEN 4 AND 120))
                AND evidence_size BETWEEN 1 AND 5242880
                AND evidence_sha256 ~ '^[0-9a-f]{64}$'
            )");
        DB::statement('CREATE TRIGGER immutable_fin_recovery_receipts
            BEFORE UPDATE OR DELETE ON hall_inventory_financial_recovery_receipts
            FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()');
        // Finance ledger remains balanced, restricted, append-only. A recovered
        // cash receipt is CASH-BASIS income only, NOT accounts receivable.
        DB::statement('ALTER TABLE finance_journals DROP CONSTRAINT finance_journal_type');
        DB::statement("ALTER TABLE finance_journals ADD CONSTRAINT finance_journal_type
            CHECK (entry_type IN ('opening_cash','reversal','customer_deposit','inventory_recovery'))");
        DB::statement('ALTER TABLE finance_journal_lines DROP CONSTRAINT finance_line_account');
        DB::statement("ALTER TABLE finance_journal_lines ADD CONSTRAINT finance_line_account
            CHECK (account_code IN ('cash_on_hand','opening_equity','bank_clearing',
                'customer_deposit_liability','inventory_loss_recovery_income'))");
        Schema::create('hall_inventory_financial_recovery_reviews',function(Blueprint $t):void {
            $t->uuid('id')->primary();
            $t->uuid('company_id');$t->uuid('receipt_id');$t->uuid('obligation_id');
            $t->string('decision',16);$t->string('verification_method',40);
            $t->string('verification_reference',160);$t->string('reason',500);
            $t->uuid('journal_id')->nullable();$t->char('fingerprint',64);
            $t->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id','receipt_id'],'fin_recovery_one_review');
            $t->unique(['company_id','journal_id'],'fin_recovery_one_journal');
            $t->foreign(['company_id','receipt_id','obligation_id'],
                'fin_recovery_review_exact_receipt_fk')
                ->references(['company_id','id','obligation_id'])
                ->on('hall_inventory_financial_recovery_receipts')->restrictOnDelete();
            $t->foreign(['company_id','journal_id'],'fin_recovery_review_journal_fk')
                ->references(['company_id','id'])->on('finance_journals')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE hall_inventory_financial_recovery_reviews
            ADD CONSTRAINT fin_recovery_review_check CHECK (
                (decision = 'confirmed' AND journal_id IS NOT NULL) OR
                (decision = 'rejected' AND journal_id IS NULL)
            )");
        DB::statement('CREATE TRIGGER immutable_fin_recovery_reviews
            BEFORE UPDATE OR DELETE ON hall_inventory_financial_recovery_reviews
            FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()');
        Schema::create('hall_inventory_financial_recovery_reversals',function(Blueprint $t):void {
            $t->uuid('id')->primary();$t->uuid('company_id');
            $t->uuid('receipt_id');$t->uuid('original_journal_id');
            $t->uuid('reversal_journal_id');
            $t->string('reason',500);
            $t->foreignId('reversed_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id','receipt_id'],'fin_recovery_one_reversal');
            $t->unique(['company_id','reversal_journal_id'],'fin_recovery_reversal_journal_unique');
            $t->foreign(['company_id','receipt_id'])->references(['company_id','receipt_id'])
                ->on('hall_inventory_financial_recovery_reviews')->restrictOnDelete();
            $t->foreign(['company_id','original_journal_id'])
                ->references(['company_id','id'])->on('finance_journals')->restrictOnDelete();
            $t->foreign(['company_id','reversal_journal_id'])
                ->references(['company_id','id'])->on('finance_journals')->restrictOnDelete();
        });
        DB::statement('CREATE TRIGGER immutable_fin_recovery_reversals
            BEFORE UPDATE OR DELETE ON hall_inventory_financial_recovery_reversals
            FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()');
    }
    public function down():void {
        if(DB::table('hall_inventory_financial_recovery_receipts')->exists() ||
           DB::table('hall_inventory_financial_recovery_reviews')->exists() ||
           DB::table('hall_inventory_financial_recovery_reversals')->exists())
            throw new RuntimeException('Posted recoveries and immutable correction history must be archived.');
        Schema::dropIfExists('hall_inventory_financial_recovery_reversals');
        Schema::dropIfExists('hall_inventory_financial_recovery_reviews');
        Schema::dropIfExists('hall_inventory_financial_recovery_receipts');
        DB::statement('ALTER TABLE finance_journal_lines DROP CONSTRAINT finance_line_account');
        DB::statement("ALTER TABLE finance_journal_lines ADD CONSTRAINT finance_line_account
            CHECK (account_code IN ('cash_on_hand','opening_equity','bank_clearing','customer_deposit_liability'))");
        DB::statement('ALTER TABLE finance_journals DROP CONSTRAINT finance_journal_type');
        DB::statement("ALTER TABLE finance_journals ADD CONSTRAINT finance_journal_type
            CHECK (entry_type IN ('opening_cash','reversal','customer_deposit'))");
        Schema::table('hall_inventory_financial_obligations',function(Blueprint $t):void {
            $t->dropUnique('fin_recovery_obligation_scope');
        });
    }
};
