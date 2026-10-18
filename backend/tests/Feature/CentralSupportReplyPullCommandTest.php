<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CentralSupportReplyPullCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduled_support_reply_pull_is_default_off_with_no_http_requests():void
    {
        config()->set('central_control.support_enabled',false);
        config()->set('central_control.support_reply_pull_enabled',false);
        Http::preventStrayRequests();
        $this->assertSame(0,Artisan::call('venuedesk:pull-hafzatech-support-replies'));
        $this->assertStringContainsString('disabled',Artisan::output());
        Http::assertNothingSent();
        $this->assertDatabaseCount('central_support_reply_inbox',0);
        $this->assertDatabaseCount('central_license_check_state',0);
    }
}
