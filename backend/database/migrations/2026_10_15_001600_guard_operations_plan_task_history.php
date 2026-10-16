<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Preserve the immutable task identity and history. Status can change
        // only through a monotone planned/reviewed revision transition.
        DB::unprepared(<<<'SQL'
CREATE FUNCTION venuedesk_guard_operations_plan_tasks() RETURNS trigger
LANGUAGE plpgsql AS $ops_guard$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'VenueDesk planning tasks cannot be hard deleted';
    END IF;
    IF ROW(NEW.id, NEW.company_id, NEW.booking_id, NEW.hall_id,
           NEW.title, NEW.creation_reason, NEW.created_by, NEW.created_at)
       IS DISTINCT FROM
       ROW(OLD.id, OLD.company_id, OLD.booking_id, OLD.hall_id,
           OLD.title, OLD.creation_reason, OLD.created_by, OLD.created_at) THEN
        RAISE EXCEPTION 'VenueDesk planning task identity is immutable';
    END IF;
    IF NEW.revision IS DISTINCT FROM OLD.revision + 1
       OR NOT (
           (OLD.status = 'planned' AND NEW.status = 'reviewed')
           OR (OLD.status = 'reviewed' AND NEW.status = 'planned')
       ) THEN
        RAISE EXCEPTION 'VenueDesk planning task transition requires next revision and opposite status';
    END IF;
    RETURN NEW;
END;
$ops_guard$;
SQL);
        DB::statement('CREATE TRIGGER guard_operations_plan_tasks BEFORE UPDATE OR DELETE ON operations_plan_tasks FOR EACH ROW EXECUTE FUNCTION venuedesk_guard_operations_plan_tasks()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS guard_operations_plan_tasks ON operations_plan_tasks');
        DB::statement('DROP FUNCTION IF EXISTS venuedesk_guard_operations_plan_tasks()');
    }
};
