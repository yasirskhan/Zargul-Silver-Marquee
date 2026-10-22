<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Final non-clean EXTERNAL_IN closure after a green readiness assessment.
 * Writes off only the partner-settled missing borrowed transit, never owned
 * stock, and issues one company-numbered immutable private PDF.
 */
return new class extends Migration {
    public function up():void {
        DB::statement('ALTER TABLE hall_inventory_movement_events DROP CONSTRAINT hall_inventory_movement_kind_check');
        DB::statement("ALTER TABLE hall_inventory_movement_events ADD CONSTRAINT hall_inventory_movement_kind_check CHECK (
            event_kind IN ('opening_owned','acquire_owned','dispatch_owned_loan','return_owned_good',
                'return_owned_damaged','accept_borrowed','send_borrowed_back','confirm_borrowed_return',
                'damage_borrowed','reclassify_owned_damage','restore_owned_usable',
                'writeoff_owned_on_loan','writeoff_owned_damaged','writeoff_borrowed_transit'))");
        Schema::create('hall_inventory_external_in_nonclean_closures',function(Blueprint $t):void {
            $t->uuid('id')->primary();
            $t->uuid('company_id'); $t->uuid('hall_id'); $t->uuid('loan_id');
            $t->unsignedInteger('serial_seq');
            $t->string('serial_number',40);
            $t->char('currency',3);
            $t->unsignedBigInteger('amount_minor');
            $t->unsignedInteger('missing_quantity');
            $t->unsignedInteger('damaged_quantity');
            $t->char('pdf_sha256',64);
            $t->unsignedInteger('pdf_size');
            $t->string('pdf_path',500);
            $t->jsonb('readiness');
            $t->foreignId('closed_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id','loan_id'],'ext_in_nonclean_one_close');
            $t->unique(['company_id','serial_number'],'ext_in_nonclean_number');
            $t->unique(['company_id','serial_seq'],'ext_in_nonclean_sequence');
            $t->unique(['company_id','hall_id','id'],'ext_in_nonclean_scope');
            $t->foreign(['company_id','hall_id'])->references(['company_id','id'])
                ->on('halls')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE hall_inventory_external_in_nonclean_closures
            ADD CONSTRAINT ext_in_nonclean_valid CHECK (
                serial_seq BETWEEN 1 AND 1000000000
                AND currency ~ '^[A-Z]{3}$'
                AND amount_minor BETWEEN 1 AND 100000000000
                AND missing_quantity BETWEEN 0 AND 100000000
                AND damaged_quantity BETWEEN 0 AND 100000000
                AND pdf_size BETWEEN 1 AND 5242880
                AND length(serial_number) BETWEEN 1 AND 40)");
        DB::statement('CREATE TRIGGER immutable_hall_inventory_external_in_nonclean_closures
            BEFORE UPDATE OR DELETE ON hall_inventory_external_in_nonclean_closures
            FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()');
    }
    public function down():void {
        if(DB::table('hall_inventory_external_in_nonclean_closures')->exists())
            throw new RuntimeException('Immutable external-in non-clean closures cannot be rolled back.');
        Schema::dropIfExists('hall_inventory_external_in_nonclean_closures');
        DB::statement('ALTER TABLE hall_inventory_movement_events DROP CONSTRAINT hall_inventory_movement_kind_check');
        DB::statement("ALTER TABLE hall_inventory_movement_events ADD CONSTRAINT hall_inventory_movement_kind_check CHECK (
            event_kind IN ('opening_owned','acquire_owned','dispatch_owned_loan','return_owned_good',
                'return_owned_damaged','accept_borrowed','send_borrowed_back','confirm_borrowed_return',
                'damage_borrowed','reclassify_owned_damage','restore_owned_usable',
                'writeoff_owned_on_loan','writeoff_owned_damaged'))");
    }
};
