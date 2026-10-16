<?php

namespace App\Services;

use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Private opt-in Telegram *notification* channel. Decisions stay behind
 * the authenticated Filament/MFA screen. Never sends client or money details.
 * Telegram sendMessage has no idempotency key: DB retries are deduplicated
 * after an acknowledged send; an ambiguous network crash is at-least-once.
 */
final class TelegramApprovalAlertService
{
    private function owner(User $actor, string $companyId): void
    {
        if (! Auth::check() || (int) Auth::id() !== (int) $actor->getKey()
            || ! $actor->is_active || ! $actor->hasVerifiedEmail()
            || ! CompanyMembership::query()->where('company_id', $companyId)
                ->where('user_id', $actor->getKey())->where('role', 'owner')
                ->where('is_active', true)
                ->whereHas('company', fn ($q) => $q->where('is_active', true))->exists()) {
            throw new AuthorizationException('Only a verified active company owner can manage Telegram linking.');
        }
    }

    private function enabled(): bool
    {
        return config('approval_alerts.enabled') === true
            && is_string(config('approval_alerts.bot_token'))
            && preg_match('/^[0-9]{5,}:[A-Za-z0-9_-]{30,}$/D', config('approval_alerts.bot_token')) === 1
            && is_string(config('approval_alerts.webhook_secret'))
            && preg_match('/^[A-Za-z0-9_-]{24,256}$/D', config('approval_alerts.webhook_secret')) === 1;
    }

    /** Pairing code is shown ONCE to the owner and never stored or audited. */
    public function beginPairing(User $owner, string $companyId): array
    {
        $this->owner($owner, $companyId);
        $username = config('approval_alerts.bot_username');
        if (! $this->enabled() || ! is_string($username)
            || preg_match('/^[A-Za-z0-9_]{5,32}$/D', $username) !== 1) {
            throw new ConflictHttpException('Telegram must be securely configured before owner pairing.');
        }

        $code = 'VD'.bin2hex(random_bytes(24));
        DB::transaction(function () use ($owner, $companyId, $code): void {
            $this->owner($owner, $companyId);
            DB::table('telegram_pair_challenges')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $companyId,
                'owner_id' => $owner->getKey(), 'token_hash' => hash('sha256', $code),
                'expires_at' => now()->addMinutes(10),
            ]);
        }, 3);

        return ['url' => 'https://t.me/'.$username.'?start='.$code, 'expiresInSeconds' => 600];
    }

    /**
     * Called ONLY by the secret-header verified webhook route.
     * One-time challenge cannot be claimed in group chats or by a different
     * sender, even with a leaked link. No untrusted update fields are logged.
     */
    public function acceptWebhook(array $update, string $headerSecret): bool
    {
        if (! $this->enabled() || ! hash_equals(config('approval_alerts.webhook_secret'), $headerSecret)) {
            throw new AuthorizationException('Untrusted Telegram webhook.');
        }
        $updateId = $update['update_id'] ?? null;
        if (! is_int($updateId) || $updateId < 1) {
            throw new InvalidArgumentException('Invalid webhook update identifier.');
        }

        return DB::transaction(function () use ($update, $updateId): bool {
            if (! DB::table('telegram_webhook_updates')->insertOrIgnore([
                'update_id' => $updateId, 'created_at' => now(),
            ])) {
                return false;
            }
            $message = $update['message'] ?? [];
            $chat = $message['chat'] ?? [];
            $from = $message['from'] ?? [];
            $text = $message['text'] ?? null;
            if (! is_array($message) || ! is_array($chat) || ! is_array($from)
                || ($chat['type'] ?? null) !== 'private'
                || ! is_int($chat['id'] ?? null) || ($chat['id'] ?? 0) < 1
                || ($chat['id'] ?? null) !== ($from['id'] ?? null)
                || ($from['is_bot'] ?? true) !== false
                || ! is_string($text)
                || preg_match('/^\/start(?:@[A-Za-z0-9_]{5,32})? (VD[a-f0-9]{48})$/D', $text, $match) !== 1) {
                return false;
            }

            $challenge = DB::table('telegram_pair_challenges')
                ->where('token_hash', hash('sha256', $match[1]))
                ->lockForUpdate()->first();
            if (! $challenge || $challenge->consumed_at !== null
                || now()->gte($challenge->expires_at)) {
                return false;
            }
            $eligible = CompanyMembership::query()
                ->where('company_id', $challenge->company_id)->where('user_id', $challenge->owner_id)
                ->where('role', 'owner')->where('is_active', true)
                ->whereHas('company', fn ($q) => $q->where('is_active', true))
                ->whereHas('user', fn ($q) => $q->where('is_active', true)
                    ->whereNotNull('email_verified_at'))->exists();
            if (! $eligible) {
                return false;
            }

            $chatId = (string) $chat['id'];
            $fingerprint = hash_hmac('sha256', $chatId, config('app.key'));
            $link = DB::table('telegram_owner_links')
                ->where('company_id', $challenge->company_id)
                ->where('owner_id', $challenge->owner_id)->lockForUpdate()->first();
            if ($link) {
                DB::table('telegram_owner_links')->where('id', $link->id)->update([
                    'chat_id_encrypted' => Crypt::encryptString($chatId),
                    'chat_fingerprint' => $fingerprint, 'verified_at' => now(),
                    'revoked_at' => null, 'updated_at' => now(),
                ]);
            } else {
                DB::table('telegram_owner_links')->insert([
                    'id' => (string) Str::uuid(), 'company_id' => $challenge->company_id,
                    'owner_id' => $challenge->owner_id,
                    'chat_id_encrypted' => Crypt::encryptString($chatId),
                    'chat_fingerprint' => $fingerprint, 'verified_at' => now(),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('telegram_pair_challenges')->where('id', $challenge->id)
                ->update(['consumed_at' => now()]);
            $this->audit($challenge->company_id, (int) $challenge->owner_id,
                'approval.telegram_paired', 'Telegram owner private chat verified');
            return true;
        }, 3);
    }

    public function revoke(User $actor, string $companyId): void
    {
        $this->owner($actor, $companyId);
        DB::transaction(function () use ($actor, $companyId): void {
            $this->owner($actor, $companyId);
            $link = DB::table('telegram_owner_links')->where('company_id', $companyId)
                ->where('owner_id', $actor->getKey())->lockForUpdate()->first();
            if (! $link || $link->revoked_at !== null) {
                throw new ConflictHttpException('No active Telegram pairing for this owner.');
            }
            // Erase the previous chat ID and unlink its fingerprint on opt-out.
            // Immutable audit records only a non-identifying linked=true/false.
            DB::table('telegram_owner_links')->where('id', $link->id)->update([
                'chat_id_encrypted' => Crypt::encryptString(''),
                'chat_fingerprint' => hash_hmac('sha256', 'revoked:'.$link->id, config('app.key')),
                'revoked_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit($companyId, (int) $actor->getKey(),
                'approval.telegram_revoked', 'Owner revoked private Telegram alerts');
        }, 3);
    }

    public function hasActiveLink(User $actor, string $companyId): bool
    {
        $this->owner($actor, $companyId);
        return DB::table('telegram_owner_links')->where('company_id', $companyId)
            ->where('owner_id', $actor->getKey())->whereNull('revoked_at')->exists();
    }

    /**
     * Invoked via guarded operator CLI. No webhook may send arbitrary text.
     * Single DB row lock prevents simultaneous sender duplicates on success.
     * Retry each undelivered alert at most 3 times, never loop indefinitely.
     */
    public function deliverPending(int $limit = 10): array
    {
        $baseUrl = (string) config('app.url');
        $parts = parse_url($baseUrl);
        if (! $this->enabled() || $limit < 1 || $limit > 50 || $parts === false
            || ($parts['scheme'] ?? null) !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            return ['sent' => 0, 'failed' => 0, 'disabled' => true];
        }

        $alerts = DB::table('approval_alert_outbox')->orderBy('created_at')
            ->orderBy('id')->limit(100)->get(['id', 'company_id']);
        $sent = 0;
        $failed = 0;
        foreach ($alerts as $alert) {
            if ($sent + $failed >= $limit) {
                break;
            }
            $links = DB::table('telegram_owner_links')->where('company_id', $alert->company_id)
                ->whereNull('revoked_at')->get(['owner_id']);
            foreach ($links as $link) {
                if ($sent + $failed >= $limit) {
                    break;
                }
                $outcome = DB::transaction(function () use ($alert, $link, $baseUrl): ?bool {
                    $activeLink = DB::table('telegram_owner_links')
                        ->where('company_id', $alert->company_id)->where('owner_id', $link->owner_id)
                        ->lockForUpdate()->first();
                    if (! $activeLink || $activeLink->revoked_at !== null
                        || ! CompanyMembership::query()->where('company_id', $alert->company_id)
                            ->where('user_id', $link->owner_id)->where('role', 'owner')
                            ->where('is_active', true)
                            ->whereHas('company', fn ($q) => $q->where('is_active', true))
                            ->whereHas('user', fn ($q) => $q->where('is_active', true)
                                ->whereNotNull('email_verified_at'))->exists()) {
                        return null;
                    }
                    DB::table('telegram_approval_deliveries')->insertOrIgnore([
                        'id' => (string) Str::uuid(),
                        'company_id' => $alert->company_id,
                        'alert_id' => $alert->id, 'owner_id' => $link->owner_id,
                        'attempts' => 0, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $delivery = DB::table('telegram_approval_deliveries')
                        ->where('alert_id', $alert->id)->where('owner_id', $link->owner_id)
                        ->lockForUpdate()->first();
                    if ($delivery->sent_at !== null || $delivery->attempts >= 3) {
                        return null;
                    }

                    $result = null;
                    $httpStatus = null;
                    try {
                        $response = Http::asForm()->timeout(5)->post(
                            'https://api.telegram.org/bot'.config('approval_alerts.bot_token').'/sendMessage',
                            [
                                'chat_id' => Crypt::decryptString($activeLink->chat_id_encrypted),
                                'text' => 'VenueDesk: an approval request was updated. Sign in with MFA: '
                                    .rtrim($baseUrl, '/').'/admin/approval-center',
                                'disable_web_page_preview' => 'true',
                            ]
                        );
                        $httpStatus = $response->status();
                        if ($response->successful() && $response->json('ok') === true
                            && is_int($response->json('result.message_id'))) {
                            $result = (int) $response->json('result.message_id');
                        }
                    } catch (\Throwable) {
                        // Never serialize/log an exception containing the bot token.
                    }
                    DB::table('telegram_approval_deliveries')
                        ->where('id', $delivery->id)->update([
                            'attempts' => $delivery->attempts + 1,
                            'last_http_status' => $httpStatus,
                            'sent_at' => $result !== null ? now() : null,
                            'telegram_message_id' => $result,
                            'updated_at' => now(),
                        ]);
                    return $result !== null;
                }, 3);
                if ($outcome === true) {
                    $sent++;
                } elseif ($outcome === false) {
                    $failed++;
                }
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'disabled' => false];
    }

    private function audit(string $companyId, int $ownerId, string $action, string $reason): void
    {
        DB::table('audit_events')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $companyId,
            'actor_id' => $ownerId, 'action' => $action,
            'entity_type' => 'user', 'entity_id' => (string) $ownerId,
            'before_values' => null, 'after_values' => json_encode(['telegramPrivateChatLinked' =>
                $action === 'approval.telegram_paired'], JSON_THROW_ON_ERROR),
            'context' => json_encode(['reason' => $reason], JSON_THROW_ON_ERROR),
        ]);
    }
}
