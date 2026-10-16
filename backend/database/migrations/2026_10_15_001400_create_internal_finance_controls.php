<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Internal, nonbinding snapshot. Not an issued invoice or tax document.
        Schema::create('finance_quote_previews', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('booking_id');
            $table->uuid('hall_id');
            $table->unsignedInteger('booking_revision');
            $table->unsignedInteger('program_rules_revision');
            $table->string('program', 16);
            $table->char('currency_code', 3);
            $table->jsonb('frozen_lines');
            $table->bigInteger('subtotal_minor');
            $table->string('reason', 500);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['company_id', 'id']);
            $table->foreign(['company_id', 'booking_id'])->references(['company_id', 'id'])
                ->on('booking_drafts')->restrictOnDelete();
            $table->foreign(['company_id', 'hall_id'])->references(['company_id', 'id'])
                ->on('halls')->restrictOnDelete();
            $table->index(['company_id', 'booking_id', 'created_at']);
        });
        DB::statement("ALTER TABLE finance_quote_previews ADD CONSTRAINT finance_preview_currency CHECK (currency_code ~ '^[A-Z]{3}$')");
        DB::statement('ALTER TABLE finance_quote_previews ADD CONSTRAINT finance_preview_amount CHECK (subtotal_minor >= 0 AND subtotal_minor <= 100000000000)');
        DB::statement('ALTER TABLE finance_quote_previews ADD CONSTRAINT finance_preview_revision CHECK (booking_revision > 0 AND program_rules_revision > 0)');

        Schema::create('finance_journals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('entry_type', 24);
            $table->char('currency_code', 3);
            $table->bigInteger('amount_minor');
            $table->uuid('reversal_of')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('reason', 500);
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['company_id', 'id']);
            $table->unique('reversal_of');
            $table->foreign(['company_id', 'reversal_of'])->references(['company_id', 'id'])
                ->on('finance_journals')->restrictOnDelete();
            $table->index(['company_id', 'created_at']);
        });
        DB::statement("ALTER TABLE finance_journals ADD CONSTRAINT finance_journal_type CHECK (entry_type IN ('opening_cash', 'reversal'))");
        DB::statement("ALTER TABLE finance_journals ADD CONSTRAINT finance_journal_currency CHECK (currency_code ~ '^[A-Z]{3}$')");
        DB::statement('ALTER TABLE finance_journals ADD CONSTRAINT finance_journal_amount CHECK (amount_minor > 0 AND amount_minor <= 100000000000)');
        DB::statement("ALTER TABLE finance_journals ADD CONSTRAINT finance_journal_reverse CHECK ((entry_type = 'reversal') = (reversal_of IS NOT NULL))");

        Schema::create('finance_journal_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('journal_id');
            $table->string('account_code', 32);
            $table->string('side', 6);
            $table->bigInteger('amount_minor');
            $table->timestampTz('created_at')->useCurrent();
            $table->foreign(['company_id', 'journal_id'])->references(['company_id', 'id'])
                ->on('finance_journals')->restrictOnDelete();
            $table->index(['company_id', 'account_code']);
        });
        DB::statement("ALTER TABLE finance_journal_lines ADD CONSTRAINT finance_line_account CHECK (account_code IN ('cash_on_hand', 'opening_equity'))");
        DB::statement("ALTER TABLE finance_journal_lines ADD CONSTRAINT finance_line_side CHECK (side IN ('debit','credit'))");
        DB::statement('ALTER TABLE finance_journal_lines ADD CONSTRAINT finance_line_amount CHECK (amount_minor > 0 AND amount_minor <= 100000000000)');

        // A future direct SQL bypass cannot commit an incomplete/unbalanced
        // journal or mismatched line amount. Check at transaction COMMIT, after
        // the parent and child lines have been inserted atomically.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION venuedesk_finance_assert_balanced(target_id uuid) RETURNS void
LANGUAGE plpgsql AS $$

DECLARE total_debits bigint;
DECLARE total_credits bigint;
DECLARE line_count bigint;
DECLARE journal_amount bigint;
BEGIN
    SELECT amount_minor INTO journal_amount FROM finance_journals WHERE id = target_id;
    SELECT COUNT(*),
           COALESCE(SUM(CASE WHEN side = 'debit' THEN amount_minor ELSE 0 END), 0),
           COALESCE(SUM(CASE WHEN side = 'credit' THEN amount_minor ELSE 0 END), 0)
      INTO line_count, total_debits, total_credits
      FROM finance_journal_lines WHERE journal_id = target_id;
    IF line_count <> 2 OR total_debits <> total_credits OR total_debits <> journal_amount THEN
        RAISE EXCEPTION 'VenueDesk journal must contain exactly two balanced lines for the exact journal amount';
    END IF;
END;
$$;
CREATE OR REPLACE FUNCTION venuedesk_finance_parent_check() RETURNS trigger
LANGUAGE plpgsql AS $$ BEGIN
    PERFORM venuedesk_finance_assert_balanced(NEW.id);
    RETURN NEW;
END; $$;
CREATE OR REPLACE FUNCTION venuedesk_finance_line_check() RETURNS trigger
LANGUAGE plpgsql AS $$ BEGIN
    PERFORM venuedesk_finance_assert_balanced(NEW.journal_id);
    RETURN NEW;
END; $$;
SQL);
        DB::statement('CREATE CONSTRAINT TRIGGER finance_parent_balanced AFTER INSERT ON finance_journals DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION venuedesk_finance_parent_check()');
        DB::statement('CREATE CONSTRAINT TRIGGER finance_lines_balanced AFTER INSERT ON finance_journal_lines DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION venuedesk_finance_line_check()');

        Schema::create('finance_cash_closeouts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->date('close_date');
            $table->char('currency_code', 3);
            $table->bigInteger('expected_minor');
            $table->bigInteger('counted_minor');
            $table->bigInteger('variance_minor');
            $table->string('reason', 500);
            $table->foreignId('closed_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['company_id', 'close_date', 'currency_code']);
        });
        DB::statement("ALTER TABLE finance_cash_closeouts ADD CONSTRAINT finance_close_currency CHECK (currency_code ~ '^[A-Z]{3}$')");
        DB::statement('ALTER TABLE finance_cash_closeouts ADD CONSTRAINT finance_close_counted CHECK (counted_minor >= 0 AND counted_minor <= 100000000000)');
        DB::statement('ALTER TABLE finance_cash_closeouts ADD CONSTRAINT finance_close_expected CHECK (expected_minor >= 0 AND expected_minor <= 100000000000)');
        DB::statement('ALTER TABLE finance_cash_closeouts ADD CONSTRAINT finance_close_variance CHECK (variance_minor = counted_minor - expected_minor)');

        foreach (['finance_quote_previews', 'finance_journals', 'finance_journal_lines', 'finance_cash_closeouts'] as $table) {
            DB::statement("CREATE TRIGGER immutable_{$table} BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_cash_closeouts');
        Schema::dropIfExists('finance_journal_lines');
        Schema::dropIfExists('finance_journals');
        Schema::dropIfExists('finance_quote_previews');
        DB::statement('DROP FUNCTION IF EXISTS venuedesk_finance_parent_check()');
        DB::statement('DROP FUNCTION IF EXISTS venuedesk_finance_line_check()');
        DB::statement('DROP FUNCTION IF EXISTS venuedesk_finance_assert_balanced(uuid)');
    }
};
