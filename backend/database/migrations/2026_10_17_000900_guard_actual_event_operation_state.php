<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // The function can survive migrate:fresh because dropping tables does not
        // remove PostgreSQL functions. Recreate its exact guarded definition
        // on replay without disabling any immutable row protections.
        // Event evidence is a strict single forward transition. A new event
        // must start as in_progress; only the service's finish transaction
        // may complete it once. No SQL rewrite after the actual closeout.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION venuedesk_guard_actual_event_operation() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'VenueDesk actual event records cannot be deleted';
    END IF;
    IF OLD.status <> 'in_progress' OR NEW.status <> 'completed'
        OR NEW.revision <> OLD.revision + 1
        OR NEW.id IS DISTINCT FROM OLD.id
        OR NEW.company_id IS DISTINCT FROM OLD.company_id
        OR NEW.reservation_id IS DISTINCT FROM OLD.reservation_id
        OR NEW.started_clock IS DISTINCT FROM OLD.started_clock
        OR NEW.started_by IS DISTINCT FROM OLD.started_by
        OR NEW.created_at IS DISTINCT FROM OLD.created_at
        OR OLD.ended_clock IS NOT NULL
        OR OLD.overtime_snapshot IS NOT NULL
        OR NEW.ended_clock IS NULL
        OR NEW.overtime_snapshot IS NULL
        OR NEW.completed_by IS NULL
    THEN
        RAISE EXCEPTION 'VenueDesk actual event evidence permits only one final completion';
    END IF;
    RETURN NEW;
END
$$;
SQL);
        DB::statement('CREATE TRIGGER guard_venue_event_operation_state BEFORE UPDATE OR DELETE ON venue_event_operations FOR EACH ROW EXECUTE FUNCTION venuedesk_guard_actual_event_operation()');
    }

    public function down(): void
    {
        // Reversible only while there is no actual service evidence.
        if (DB::table('venue_event_operations')->exists()) {
            throw new RuntimeException('Cannot remove actual event row protection with existing closeout evidence.');
        }
        DB::statement('DROP TRIGGER IF EXISTS guard_venue_event_operation_state ON venue_event_operations');
        DB::statement('DROP FUNCTION IF EXISTS venuedesk_guard_actual_event_operation()');
    }
};
