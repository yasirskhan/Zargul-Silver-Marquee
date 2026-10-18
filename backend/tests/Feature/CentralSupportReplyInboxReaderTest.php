<?php

namespace Tests\Feature;

use App\Services\CentralSupportReplyInboxReader;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CentralSupportReplyInboxReaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_unenrolled_user_cannot_read_or_mark_support_messages():void
    {
        $actor=new User();
        $reader=app(CentralSupportReplyInboxReader::class);
        $company=(string)Str::uuid();
        foreach([
            fn()=>$reader->unreadCount($actor,$company),
            fn()=>$reader->list($actor,$company),
            fn()=>$reader->markRead($actor,$company,(string)Str::uuid()),
        ] as $attempt){
            try{$attempt();$this->fail('Unrelated user reached central support inbox.');}
            catch(AuthorizationException $expected){}
        }
        $this->assertDatabaseCount('central_support_reply_inbox',0);
    }

    public function test_invalid_page_limit_is_denied_before_query():void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(CentralSupportReplyInboxReader::class)->list(
            new User(),(string)Str::uuid(),500
        );
    }
}
