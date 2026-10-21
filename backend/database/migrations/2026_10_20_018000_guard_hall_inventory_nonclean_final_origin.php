<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SQL-enforced source identity for the immutable numbered NCL ledger.
 * Existing loan/hall company FKs alone allow a mismatched same-company hall.
 */
return new class extends Migration {
    public function up():void
    {
        Schema::table('hall_inventory_loan_drafts',function(Blueprint $t):void {
            $t->unique(['company_id','id','lending_hall_id'],'inventory_ncl_orig_loan_hall_key');
        });
        DB::statement('ALTER TABLE hall_inventory_nonclean_final_closures
            ADD CONSTRAINT ncl_original_lending_hall_fk
            FOREIGN KEY (company_id, loan_id, hall_id)
            REFERENCES hall_inventory_loan_drafts(company_id, id, lending_hall_id)
            ON DELETE RESTRICT');
        DB::unprepared(<<<'SQL'
CREATE FUNCTION venuedesk_ncl_guard_final_direction() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM hall_inventory_loan_drafts AS l
        WHERE l.company_id=NEW.company_id AND l.id=NEW.loan_id
          AND l.lending_hall_id=NEW.hall_id AND l.direction='external_out'
    ) THEN
        RAISE EXCEPTION 'NCL final certificate requires original external-out lending hall';
    END IF;
    RETURN NEW;
END
$$;
SQL);
        DB::statement('CREATE TRIGGER ncl_final_original_direction_guard
            BEFORE INSERT ON hall_inventory_nonclean_final_closures
            FOR EACH ROW EXECUTE FUNCTION venuedesk_ncl_guard_final_direction()');
    }
    public function down():void
    {
        if(DB::table('hall_inventory_nonclean_final_closures')->exists())
            throw new RuntimeException('Immutable final NCL source bindings require archival before rollback.');
        DB::statement('DROP TRIGGER IF EXISTS ncl_final_original_direction_guard
            ON hall_inventory_nonclean_final_closures');
        DB::statement('DROP FUNCTION IF EXISTS venuedesk_ncl_guard_final_direction()');
        DB::statement('ALTER TABLE hall_inventory_nonclean_final_closures
            DROP CONSTRAINT IF EXISTS ncl_original_lending_hall_fk');
        Schema::table('hall_inventory_loan_drafts',function(Blueprint $t):void {
            $t->dropUnique('inventory_ncl_orig_loan_hall_key');
        });
    }
};
