<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\ApprovalWorkflowService;
use App\Services\TelegramApprovalAlertService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class TelegramApprovalAlertServiceTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'synthetic_private_webhook_token_123456789012';
    private const BOT_TOKEN = '123456:synthetic_telegram_bot_token_123456789012345';

    private function fixture(): array
    {
        config()->set('approval_alerts.enabled', true);
        config()->set('approval_alerts.bot_token', self::BOT_TOKEN);
        config()->set('approval_alerts.webhook_secret', self::WEBHOOK_SECRET);
        config()->set('approval_alerts.bot_username', 'VenueDeskTestBot');
        config()->set('app.url', 'https://venue.example.test');
        $company = Company::create(['name' => 'Sample Company', 'slug' => 'telegram-company']);
        $other = Company::create(['name' => 'Other Venue', 'slug' => 'telegram-other']);
        $owner = User::create([
            'name' => 'Owner', 'email' => 'telegram-owner@example.test', 'password' => 'test-only',
        ]);
        $owner->markEmailAsVerified();
        $admin = User::create([
            'name' => 'Admin', 'email' => 'telegram-admin@example.test', 'password' => 'test-only',
        ]);
        $admin->markEmailAsVerified();
        $membership = CompanyMembership::create([
            'company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner',
        ]);
        CompanyMembership::create([
            'company_id' => $company->id, 'user_id' => $admin->id, 'role' => 'admin',
        ]);
        $this->be($owner);

        return [$company, $other, $owner, $admin, $membership];
    }

    private function update(string $url, int $updateId, int $chatId = 88991231, string $type = 'private'): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $parts);
        $code = $parts['start'];

        return [
            'update_id' => $updateId,
            'message' => [
                'chat' => ['id' => $chatId, 'type' => $type],
                'from' => ['id' => $chatId, 'is_bot' => false],
                'text' => '/start '.$code,
            ],
        ];
    }

    private function pair(Company $company, User $owner, int $updateId = 1001): void
    {
        $service = app(TelegramApprovalAlertService::class);
        $link = $service->beginPairing($owner, $company->id);
        $this->assertStringStartsWith('https://t.me/VenueDeskTestBot?start=VD', $link['url']);
        $this->assertTrue($service->acceptWebhook(
            $this->update($link['url'], $updateId), self::WEBHOOK_SECRET
        ));
    }

    public function test_private_owner_pairing_requires_webhook_secret_and_is_one_time(): void
    {
        [$company, , $owner] = $this->fixture();
        $service = app(TelegramApprovalAlertService::class);
        $start = $service->beginPairing($owner, $company->id);
        $update = $this->update($start['url'], 1001);
        try {
            $service->acceptWebhook($update, 'invalid-secret');
            $this->fail('Spoofed webhook accepted.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $this->assertFalse($service->acceptWebhook($this->update($start['url'], 1002, 88991231, 'supergroup'),
            self::WEBHOOK_SECRET));
        $this->assertTrue($service->acceptWebhook($update, self::WEBHOOK_SECRET));
        $this->assertFalse($service->acceptWebhook($update, self::WEBHOOK_SECRET));
        $this->assertFalse($service->acceptWebhook($this->update($start['url'], 1003),
            self::WEBHOOK_SECRET));
        $this->assertTrue($service->hasActiveLink($owner, $company->id));
        $saved = DB::table('telegram_owner_links')->first();
        $this->assertNotSame('88991231', $saved->chat_id_encrypted);
        $this->assertSame(64, strlen($saved->chat_fingerprint));
        $this->assertSame(1, DB::table('audit_events')->where('action', 'approval.telegram_paired')->count());
        $this->assertStringNotContainsString('88991231',
            json_encode(DB::table('audit_events')->first(), JSON_THROW_ON_ERROR));
    }

    public function test_owner_pairing_denies_group_sender_mismatch_expired_and_revoked_membership(): void
    {
        [$company, , $owner, , $membership] = $this->fixture();
        $service = app(TelegramApprovalAlertService::class);
        $first = $service->beginPairing($owner, $company->id);
        $malformed = $this->update($first['url'], 5);
        $malformed['message']['from']['id'] = 123;
        $this->assertFalse($service->acceptWebhook($malformed, self::WEBHOOK_SECRET));
        $this->assertFalse($service->hasActiveLink($owner, $company->id));
        DB::table('telegram_pair_challenges')->update(['expires_at' => now()->subMinute()]);
        $this->assertFalse($service->acceptWebhook($this->update($first['url'], 6), self::WEBHOOK_SECRET));
        $second = $service->beginPairing($owner, $company->id);
        $membership->update(['is_active' => false]);
        $this->assertFalse($service->acceptWebhook($this->update($second['url'], 7), self::WEBHOOK_SECRET));
        $this->expectException(AuthorizationException::class);
        $service->beginPairing($owner, $company->id);
    }

    public function test_delivery_sends_generic_mfa_link_only_once_and_no_contact_or_amount(): void
    {
        [$company, , $owner, $admin] = $this->fixture();
        $this->pair($company, $owner);
        $this->be($admin);
        $request = app(ApprovalWorkflowService::class)->request(
            $admin, $company->id, (string) Str::uuid(), 'expense', 125789,
            'PKR', null, 'Synthetic secret business proposal'
        );
        Http::fake(['*' => Http::response([
            'ok' => true, 'result' => ['message_id' => 673],
        ], 200)]);
        $service = app(TelegramApprovalAlertService::class);
        $this->assertSame(['sent' => 1, 'failed' => 0, 'disabled' => false], $service->deliverPending());
        $this->assertSame(['sent' => 0, 'failed' => 0, 'disabled' => false], $service->deliverPending());
        Http::assertSentCount(1);
        Http::assertSent(static function ($sent): bool {
            $message = (string) $sent['text'];
            return $sent->url() === 'https://api.telegram.org/bot'.self::BOT_TOKEN.'/sendMessage'
                && $sent['chat_id'] === '88991231'
                && str_contains($message, 'https://venue.example.test/admin/approval-center')
                && ! str_contains($message, '125789')
                && ! str_contains($message, 'Synthetic secret business')
                && ! str_contains($message, '@');
        });
        $this->assertSame(1, DB::table('telegram_approval_deliveries')->count());
        $this->assertSame(1, DB::table('telegram_approval_deliveries')->whereNotNull('sent_at')->count());
        $this->assertSame('pending', DB::table('approval_requests')->where('id', $request['id'])->value('status'));
    }

    public function test_failed_sends_have_a_real_max_three_attempts_and_no_false_delivery(): void
    {
        [$company, , $owner, $admin] = $this->fixture();
        $this->pair($company, $owner);
        $this->be($admin);
        app(ApprovalWorkflowService::class)->request(
            $admin, $company->id, (string) Str::uuid(), 'expense', 100,
            'PKR', null, 'Fixture unsent request'
        );
        Http::fake(['*' => Http::response(['ok' => false], 502)]);
        $service = app(TelegramApprovalAlertService::class);
        for ($i = 0; $i < 4; $i++) {
            $service->deliverPending();
        }
        Http::assertSentCount(3);
        $this->assertSame(3, DB::table('telegram_approval_deliveries')->value('attempts'));
        $this->assertNull(DB::table('telegram_approval_deliveries')->value('sent_at'));
        $this->assertSame(502, DB::table('telegram_approval_deliveries')->value('last_http_status'));
        $this->assertSame(0, DB::table('telegram_approval_deliveries')->whereNotNull('sent_at')->count());
    }

    public function test_revocation_and_revoked_owner_block_delivery_and_cross_tenant_reads(): void
    {
        [$company, $foreign, $owner, $admin, $membership] = $this->fixture();
        $this->pair($company, $owner);
        $this->be($admin);
        app(ApprovalWorkflowService::class)->request(
            $admin, $company->id, (string) Str::uuid(), 'expense', 15,
            'PKR', null, 'Proposed expense'
        );
        $this->be($owner);
        try {
            app(TelegramApprovalAlertService::class)->hasActiveLink($owner, $foreign->id);
            $this->fail('Foreign link allowed.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $service = app(TelegramApprovalAlertService::class);
        $service->revoke($owner, $company->id);
        $this->assertFalse($service->hasActiveLink($owner, $company->id));
        Http::fake();
        $this->assertSame(0, $service->deliverPending()['sent']);
        Http::assertNothingSent();
        $this->expectException(ConflictHttpException::class);
        $service->revoke($owner, $company->id);
    }

    public function test_webhook_endpoint_rejects_missing_header_and_only_accepts_private_secret(): void
    {
        [$company, , $owner] = $this->fixture();
        $service = app(TelegramApprovalAlertService::class);
        $start = $service->beginPairing($owner, $company->id);
        $update = $this->update($start['url'], 8);
        $this->postJson('/integrations/telegram/approval-webhook', $update)->assertForbidden();
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::WEBHOOK_SECRET)
            ->postJson('/integrations/telegram/approval-webhook', $update)
            ->assertOk()->assertJson(['ok' => true]);
        $this->assertTrue($service->hasActiveLink($owner, $company->id));
    }

    public function test_transport_fails_closed_without_configuration_or_secure_application_url(): void
    {
        [$company, , $owner, $admin] = $this->fixture();
        $this->pair($company, $owner);
        $this->be($admin);
        app(ApprovalWorkflowService::class)->request(
            $admin, $company->id, (string) Str::uuid(), 'expense',
            100, 'PKR', null, 'Private expense request'
        );
        Http::fake();
        config()->set('app.url', 'http://localhost');
        $this->assertTrue(app(TelegramApprovalAlertService::class)->deliverPending()['disabled']);
        config()->set('app.url', 'https://venue.example.test');
        config()->set('approval_alerts.enabled', false);
        $this->assertTrue(app(TelegramApprovalAlertService::class)->deliverPending()['disabled']);
        Http::assertNothingSent();
    }

    public function test_webhook_replay_registry_is_immutable_in_real_postgres(): void
    {
        [$company, , $owner] = $this->fixture();
        $this->pair($company, $owner);
        $this->expectException(QueryException::class);
        DB::table('telegram_webhook_updates')->delete();
    }
}
