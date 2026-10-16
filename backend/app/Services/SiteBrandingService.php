<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Versioned owner branding. Never render draft settings as public content.
 * Public serving of optimized assets is a separate release-gated workflow.
 */
final class SiteBrandingService
{
    public function __construct(
        private readonly TenantAccess $access,
        private readonly SiteBrandingValidator $validator,
    ) {}

    public function saveDraft(
        User $actor, string $companyId, string $websiteId,
        array $branding, int $expectedRevision, string $reason,
    ): int {
        $this->authorize($actor, $companyId, false);
        $this->reason($reason);
        $this->validator->validate($branding);

        return DB::transaction(function () use (
            $actor, $companyId, $websiteId, $branding, $expectedRevision, $reason
        ): int {
            $website = $this->websiteForUpdate($companyId, $websiteId);
            // Recheck after acquiring the write lock, not only before waiting.
            $this->authorize($actor, $companyId, false);
            if ($expectedRevision < 0 || (int) $website->branding_draft_revision !== $expectedRevision) {
                throw new ConflictHttpException('Branding has changed; reload before saving.');
            }
            $this->assertMedia($companyId, $branding, false);
            $next = $expectedRevision + 1;
            $revisionId = (string) Str::uuid();
            DB::table('site_branding_revisions')->insert([
                'id' => $revisionId,
                'company_id' => $companyId,
                'website_id' => $websiteId,
                'revision_number' => $next,
                'content' => json_encode($branding, JSON_THROW_ON_ERROR),
                'created_by' => $actor->getKey(),
            ]);
            DB::table('websites')->where('company_id', $companyId)->where('id', $websiteId)
                ->update(['branding_draft_revision' => $next, 'updated_at' => now()]);
            $this->audit($actor, $companyId, $websiteId, 'website.branding_draft_saved',
                ['revision' => $expectedRevision], ['revision' => $next, 'revision_id' => $revisionId], $reason);

            return $next;
        }, 3);
    }

    public function publish(
        User $actor, string $companyId, string $websiteId,
        int $expectedRevision, string $reason,
    ): string {
        $this->authorize($actor, $companyId, true);
        $this->reason($reason);

        return DB::transaction(function () use ($actor, $companyId, $websiteId, $expectedRevision, $reason): string {
            $website = $this->websiteForUpdate($companyId, $websiteId);
            // Publisher delegation can be revoked while awaiting this lock.
            $this->authorize($actor, $companyId, true);
            if ($expectedRevision < 1 || (int) $website->branding_draft_revision !== $expectedRevision) {
                throw new ConflictHttpException('Branding draft is stale; reload before publishing.');
            }
            $revision = DB::table('site_branding_revisions')
                ->where('company_id', $companyId)->where('website_id', $websiteId)
                ->where('revision_number', $expectedRevision)->first();
            if (! $revision) {
                throw new ConflictHttpException('Branding draft revision is unavailable.');
            }
            $branding = json_decode($revision->content, true, 512, JSON_THROW_ON_ERROR);
            $this->validator->validate($branding);
            $this->assertMedia($companyId, $branding, true);
            if ($website->branding_published_revision_id === $revision->id) {
                return $revision->id; // Idempotent approval, no duplicate audit or event.
            }

            DB::table('websites')->where('company_id', $companyId)->where('id', $websiteId)
                ->update(['branding_published_revision_id' => $revision->id, 'updated_at' => now()]);
            DB::table('site_branding_publishing_events')->insert([
                'id' => (string) Str::uuid(),
                'company_id' => $companyId,
                'website_id' => $websiteId,
                'revision_id' => $revision->id,
                'previous_revision_id' => $website->branding_published_revision_id,
                'action' => 'publish',
                'actor_id' => $actor->getKey(),
            ]);
            $this->audit($actor, $companyId, $websiteId, 'website.branding_published',
                ['published_revision_id' => $website->branding_published_revision_id],
                ['published_revision_id' => $revision->id, 'draft_revision' => $expectedRevision], $reason);

            return $revision->id;
        }, 3);
    }

    /**
     * Restore only a previously published branding snapshot. Do not rewrite or
     * discard the editor's latest independent draft revision.
     */
    public function restorePublishedBranding(
        User $actor, string $companyId, string $websiteId,
        string $targetRevisionId, string $expectedPublishedRevisionId, string $reason,
    ): string {
        $this->authorize($actor, $companyId, true);
        $this->reason($reason);

        return DB::transaction(function () use (
            $actor, $companyId, $websiteId, $targetRevisionId, $expectedPublishedRevisionId, $reason
        ): string {
            $website = $this->websiteForUpdate($companyId, $websiteId);
            // Publisher delegation can be revoked while awaiting this lock.
            $this->authorize($actor, $companyId, true);
            if (! $website->branding_published_revision_id
                || $website->branding_published_revision_id !== $expectedPublishedRevisionId) {
                throw new ConflictHttpException('Published branding changed; reload before restoring.');
            }

            $revision = DB::table('site_branding_revisions')
                ->where('company_id', $companyId)->where('website_id', $websiteId)
                ->where('id', $targetRevisionId)->first();
            if (! $revision) {
                throw new InvalidArgumentException('Branding snapshot was not found for this website.');
            }
            if (! DB::table('site_branding_publishing_events')
                ->where('company_id', $companyId)->where('website_id', $websiteId)
                ->where('revision_id', $targetRevisionId)->exists()) {
                throw new InvalidArgumentException('Only previously published branding may be restored.');
            }

            $branding = json_decode($revision->content, true, 512, JSON_THROW_ON_ERROR);
            $this->validator->validate($branding);
            $this->assertMedia($companyId, $branding, true);
            if ($revision->id === $website->branding_published_revision_id) {
                return $revision->id; // Idempotent, no duplicate history or audit.
            }

            DB::table('websites')->where('company_id', $companyId)->where('id', $websiteId)
                ->update(['branding_published_revision_id' => $revision->id, 'updated_at' => now()]);
            DB::table('site_branding_publishing_events')->insert([
                'id' => (string) Str::uuid(),
                'company_id' => $companyId,
                'website_id' => $websiteId,
                'revision_id' => $revision->id,
                'previous_revision_id' => $website->branding_published_revision_id,
                'action' => 'restore',
                'actor_id' => $actor->getKey(),
            ]);
            $this->audit($actor, $companyId, $websiteId, 'website.branding_restored',
                ['published_revision_id' => $website->branding_published_revision_id],
                ['published_revision_id' => $revision->id, 'draft_revision' => (int) $website->branding_draft_revision],
                $reason);

            return $revision->id;
        }, 3);
    }

    /** No draft fallback: unapproved branding is never publicly readable. */
    public function publishedBranding(string $companyId, string $websiteId): ?array
    {
        $website = DB::table('websites')->where('company_id', $companyId)
            ->where('id', $websiteId)->where('is_active', true)
            ->first(['branding_published_revision_id']);
        if (! $website || ! $website->branding_published_revision_id) {
            return null;
        }

        $content = DB::table('site_branding_revisions')
            ->where('company_id', $companyId)->where('website_id', $websiteId)
            ->where('id', $website->branding_published_revision_id)->value('content');

        return $content === null ? null : json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }

    private function websiteForUpdate(string $companyId, string $websiteId): object
    {
        $website = DB::table('websites')->where('company_id', $companyId)
            ->where('id', $websiteId)->where('is_active', true)->lockForUpdate()->first();
        if (! $website) {
            throw (new ModelNotFoundException)->setModel('websites');
        }
        return $website;
    }

    private function authorize(User $actor, string $companyId, bool $publishing): void
    {
        if (! Auth::check() || (int) Auth::id() !== (int) $actor->getKey()
            || ! $actor->hasVerifiedEmail()
            // A cached authenticated model may remain active even after an
            // account is disabled or email verification is revoked in SQL.
            || ! DB::table('users')->where('id', $actor->getKey())
                ->where('is_active', true)->whereNotNull('email_verified_at')->exists()
            || ! ($publishing
                ? $this->access->canPublishWebsite($actor, $companyId)
                : $this->access->canEditWebsite($actor, $companyId))) {
            throw new AuthorizationException('Not authorized for company website branding.');
        }
    }

    private function reason(string $reason): void
    {
        if (mb_strlen(trim($reason)) < 3 || mb_strlen($reason) > 500) {
            throw new InvalidArgumentException('Provide a short reason for the branding change.');
        }
    }

    /**
     * Preview drafts may refer to pending company-owned media. Publish demands
     * approved images with intact sanitized, privately stored WebP variants.
     */
    private function assertMedia(string $companyId, array $branding, bool $publishing): void
    {
        $ids = array_unique(array_filter([$branding['logoMediaId'], $branding['faviconMediaId']]));
        foreach ($ids as $id) {
            $asset = DB::table('site_media')->where('company_id', $companyId)
                ->where('id', $id)->first(['status', 'metadata']);
            if (! $asset || ! in_array($asset->status, $publishing ? ['approved'] : ['pending', 'approved'], true)) {
                throw new InvalidArgumentException('Brand image must belong to this company and be approved for publication.');
            }
            if (! $publishing) {
                continue;
            }
            $meta = json_decode($asset->metadata, true, 512, JSON_THROW_ON_ERROR);
            $variant = $meta['variants']['webp1200'] ?? null;
            $key = $variant['object_key'] ?? null;
            $digest = $variant['sha256'] ?? null;
            if (! is_string($key) || ! str_starts_with($key, 'site-media/private/'.$companyId.'/')
                || ! str_ends_with($key, '.webp') || ! is_string($digest) || strlen($digest) !== 64) {
                throw new InvalidArgumentException('Approved branding image is missing its safe private variant.');
            }
            $bytes = Storage::disk('local')->get($key);
            if (! is_string($bytes) || ! hash_equals($digest, hash('sha256', $bytes))) {
                throw new InvalidArgumentException('Approved branding image variant is missing or invalid.');
            }
        }
    }

    private function audit(
        User $actor, string $companyId, string $websiteId, string $action,
        array $before, array $after, string $reason,
    ): void {
        DB::table('audit_events')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $companyId,
            'actor_id' => $actor->getKey(),
            'action' => $action,
            'entity_type' => 'website',
            'entity_id' => $websiteId,
            'before_values' => json_encode($before, JSON_THROW_ON_ERROR),
            'after_values' => json_encode($after, JSON_THROW_ON_ERROR),
            'context' => json_encode(['reason' => trim($reason)], JSON_THROW_ON_ERROR),
        ]);
    }
}
