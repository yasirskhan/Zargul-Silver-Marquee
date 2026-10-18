<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void
    {
        // A receipt must identify a message in the SAME company. Separate
        // FKs permit inconsistent, cross-company audit metadata.
        Schema::table('central_support_reply_inbox',function(Blueprint $table):void{
            $table->unique(['company_id','id'],'support_inbox_company_message_unique');
        });
        Schema::table('central_support_reply_read_events',function(Blueprint $table):void{
            $table->foreign(['company_id','message_id'],'support_read_same_company_message')
                ->references(['company_id','id'])
                ->on('central_support_reply_inbox')
                ->restrictOnDelete();
        });
    }

    public function down():void
    {
        Schema::table('central_support_reply_read_events',function(Blueprint $table):void{
            $table->dropForeign('support_read_same_company_message');
        });
        Schema::table('central_support_reply_inbox',function(Blueprint $table):void{
            $table->dropUnique('support_inbox_company_message_unique');
        });
    }
};
