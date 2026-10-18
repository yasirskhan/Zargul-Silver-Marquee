<?php

namespace Tests\Feature;

use App\Services\CentralSupportReplyInboxService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CentralSupportReplyInboxServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_vendor_reply_ingress_is_inert_with_default_off_gates():void
    {
        config()->set('central_control.support_enabled',false);
        config()->set('central_control.support_reply_pull_enabled',false);
        $this->expectException(AuthorizationException::class);
        try {
            app(CentralSupportReplyInboxService::class)->accept([], 'untrusted',0);
        } finally {
            $this->assertDatabaseCount('central_support_reply_inbox',0);
            $this->assertDatabaseCount('central_support_reply_cursor',0);
        }
    }

    public function test_no_enrollment_denies_all_signed_support_page_attempts():void
    {
        config()->set('central_control.support_enabled',true);
        config()->set('central_control.support_reply_pull_enabled',true);
        $this->expectException(AuthorizationException::class);
        try {
            app(CentralSupportReplyInboxService::class)->accept([], 'not-enrolled',0);
        } finally {
            $this->assertDatabaseCount('central_support_reply_inbox',0);
            $this->assertDatabaseCount('central_support_reply_cursor',0);
            $this->assertDatabaseCount('finance_journals',0);
        }
    }
}
