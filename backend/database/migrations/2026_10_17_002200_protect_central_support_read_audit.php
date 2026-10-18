<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up():void
    {
        // Defense in depth: once a read receipt has been written, even an
        // application-level UPDATE/DELETE must be rejected by PostgreSQL.
        DB::unprepared(<<<'SQL'
CREATE FUNCTION prevent_central_support_read_audit_mutation()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Support read audit events are immutable'
        USING ERRCODE = '23514';
END;
$$;
CREATE TRIGGER prevent_support_read_event_rewrite
BEFORE UPDATE OR DELETE ON central_support_reply_read_events
FOR EACH ROW EXECUTE FUNCTION prevent_central_support_read_audit_mutation();
SQL);
    }

    public function down():void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS prevent_support_read_event_rewrite
    ON central_support_reply_read_events;
DROP FUNCTION IF EXISTS prevent_central_support_read_audit_mutation();
SQL);
    }
};
