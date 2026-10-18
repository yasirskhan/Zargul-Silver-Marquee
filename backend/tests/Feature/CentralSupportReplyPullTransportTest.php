<?php

namespace Tests\Feature;

use App\Services\CentralSupportReplyPullTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CentralSupportReplyPullTransportTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_pull_gates_default_off_without_network_or_identity():void
    {
        config()->set('central_control.support_enabled',false);
        config()->set('central_control.support_reply_pull_enabled',false);
        Http::preventStrayRequests();
        $this->assertSame(['state'=>'disabled'],
            app(CentralSupportReplyPullTransport::class)->poll());
        $this->assertDatabaseCount('central_support_reply_cursor',0);
        $this->assertDatabaseCount('central_support_reply_inbox',0);
        Http::assertNothingSent();
    }

    public function test_even_with_gate_on_unregistered_client_never_contacts_vendor():void
    {
        config()->set('central_control.support_enabled',true);
        config()->set('central_control.support_reply_pull_enabled',true);
        Http::preventStrayRequests();
        $this->assertSame(['state'=>'not_enrolled'],
            app(CentralSupportReplyPullTransport::class)->poll());
        Http::assertNothingSent();
        $this->assertDatabaseCount('finance_journals',0);
        $this->assertDatabaseCount('central_license_check_state',0);
    }
}
