<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // External partner identities are scoped to ONE customer tenant.
        // Their phone/address must be encrypted by an authorized service.
        Schema::create('hall_inventory_external_partners', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('name', 160);
            $table->text('contact_ciphertext')->nullable();
            $table->text('phone_ciphertext')->nullable();
            $table->text('address_ciphertext')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'name']);
        });
        DB::statement('CREATE TRIGGER immutable_hall_inventory_external_partners BEFORE UPDATE OR DELETE ON hall_inventory_external_partners FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()');

        // Immutable initial request and its multi-item list. This foundation
        // does NOT dispatch goods, recognize ownership or complete a loan.
        Schema::create('hall_inventory_loan_drafts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('direction', 20);
            $table->uuid('lending_hall_id')->nullable();
            $table->uuid('borrowing_hall_id')->nullable();
            $table->uuid('external_partner_id')->nullable();
            $table->date('expected_return_date');
            $table->string('purpose', 500);
            $table->char('request_fingerprint', 64);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['company_id', 'id']);
            $table->foreign(['company_id', 'lending_hall_id'])
                ->references(['company_id', 'id'])->on('halls')->restrictOnDelete();
            $table->foreign(['company_id', 'borrowing_hall_id'])
                ->references(['company_id', 'id'])->on('halls')->restrictOnDelete();
            $table->foreign(['company_id', 'external_partner_id'])
                ->references(['company_id', 'id'])->on('hall_inventory_external_partners')->restrictOnDelete();
            $table->index(['company_id', 'lending_hall_id', 'expected_return_date']);
            $table->index(['company_id', 'borrowing_hall_id', 'expected_return_date']);
        });
        DB::statement(<<<'SQL'
ALTER TABLE hall_inventory_loan_drafts ADD CONSTRAINT inventory_loan_direction_valid CHECK (
    (direction = 'internal' AND lending_hall_id IS NOT NULL AND borrowing_hall_id IS NOT NULL
        AND lending_hall_id <> borrowing_hall_id AND external_partner_id IS NULL)
    OR (direction = 'external_in' AND lending_hall_id IS NULL AND borrowing_hall_id IS NOT NULL
        AND external_partner_id IS NOT NULL)
    OR (direction = 'external_out' AND lending_hall_id IS NOT NULL AND borrowing_hall_id IS NULL
        AND external_partner_id IS NOT NULL)
)
SQL);

        Schema::create('hall_inventory_loan_draft_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('loan_id');
            $table->uuid('catalog_hall_id');
            $table->uuid('item_id');
            $table->unsignedInteger('requested_quantity');
            $table->string('item_name_snapshot', 160);
            $table->string('unit_snapshot', 16);
            $table->foreign(['company_id', 'loan_id'])
                ->references(['company_id', 'id'])->on('hall_inventory_loan_drafts')->restrictOnDelete();
            $table->foreign(['company_id', 'catalog_hall_id', 'item_id'])
                ->references(['company_id', 'hall_id', 'id'])->on('hall_inventory_items')->restrictOnDelete();
            $table->unique(['loan_id', 'item_id']);
            $table->index(['company_id', 'catalog_hall_id', 'item_id']);
        });
        DB::statement('ALTER TABLE hall_inventory_loan_draft_lines ADD CONSTRAINT inventory_loan_line_quantity CHECK (requested_quantity > 0 AND requested_quantity <= 100000000)');

        // Cross-hall binding is a SQL invariant, not merely a UI convention.
        // Internal/external-out loan lines must point to the lending hall;
        // external-in lines must point to the borrowing hall's local catalog.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION venuedesk_inventory_guard_loan_line_hall() RETURNS trigger
LANGUAGE plpgsql AS $
DECLARE source_hall uuid;
BEGIN
    SELECT CASE
        WHEN direction = 'external_in' THEN borrowing_hall_id
        ELSE lending_hall_id
    END INTO source_hall
    FROM hall_inventory_loan_drafts
    WHERE company_id = NEW.company_id AND id = NEW.loan_id;

    IF source_hall IS NULL OR NEW.catalog_hall_id IS DISTINCT FROM source_hall THEN
        RAISE EXCEPTION 'Inventory loan line must reference its owning/initiating hall catalog';
    END IF;
    RETURN NEW;
END
$;
SQL);
        DB::statement('CREATE TRIGGER inventory_loan_line_hall_guard BEFORE INSERT ON hall_inventory_loan_draft_lines FOR EACH ROW EXECUTE FUNCTION venuedesk_inventory_guard_loan_line_hall()');

        foreach (['hall_inventory_loan_drafts', 'hall_inventory_loan_draft_lines'] as $name) {
            DB::statement("CREATE TRIGGER immutable_{$name} BEFORE UPDATE OR DELETE ON {$name} FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()");
        }
    }

    public function down(): void
    {
        if (DB::table('hall_inventory_external_partners')->exists()
            || DB::table('hall_inventory_loan_drafts')->exists()) {
            throw new RuntimeException('External partner and loan request evidence require archival before rollback.');
        }
        Schema::dropIfExists('hall_inventory_loan_draft_lines');
        Schema::dropIfExists('hall_inventory_loan_drafts');
        Schema::dropIfExists('hall_inventory_external_partners');
        DB::statement('DROP FUNCTION IF EXISTS venuedesk_inventory_guard_loan_line_hall()');
    }
};
