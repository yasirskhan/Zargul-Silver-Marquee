<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void {
        Schema::create('hall_inventory_stocktake_book_snapshots',function(Blueprint $t):void {
            $t->uuid('id')->primary();
            $t->uuid('stocktake_line_id')->unique('stocktake_book_line_unique');
            $t->unsignedBigInteger('book_revision')->nullable();
            $t->unsignedBigInteger('book_usable')->nullable();
            $t->unsignedBigInteger('book_damaged')->nullable();
            $t->unsignedBigInteger('away_count')->nullable();
            $t->string('book_state',32);
            $t->timestampTz('created_at')->useCurrent();
            $t->foreign('stocktake_line_id','stocktake_book_line_fk')
                ->references('id')->on('hall_inventory_stocktake_lines')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE hall_inventory_stocktake_book_snapshots
            ADD CONSTRAINT stocktake_book_state_valid CHECK (
                book_state IN ('NO_POSTED_BOOK','POSTED_BOOK_SNAPSHOT','INTEGRITY_REVIEW_REQUIRED')
            )");
        DB::statement('CREATE TRIGGER immutable_hall_stocktake_book_snapshots
            BEFORE UPDATE OR DELETE ON hall_inventory_stocktake_book_snapshots
            FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()');
    }
    public function down():void {
        if(DB::table('hall_inventory_stocktake_book_snapshots')->exists())
            throw new RuntimeException('Physical-count book snapshots require archive before rollback.');
        Schema::dropIfExists('hall_inventory_stocktake_book_snapshots');
    }
};
