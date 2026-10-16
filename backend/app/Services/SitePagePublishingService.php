<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class SitePagePublishingService
{
    public function __construct(
        private readonly TenantAccess $access,
        private readonly SiteContentValidator $validator,
    ) {}

    /**
     * Save an immutable draft revision. expectedRevision is the editor's version
     * number, not the published version; stale concurrent editors must fail.
     */
    public function saveDraft(
        User $actor,
        string $companyId,
        string $pageId,
        array $content,
        int $expectedRevision,
        string $reason,
    ): int {
        $this->authorize($actor, $companyId, false);
        $this->reason($reason);
        $this->validator->validate($content);

        return DB::transaction(function () use ($actor, $companyId, $pageId, $content, $expectedRevision, $reason): int {
            $page = $this->pageForUpdate($companyId, $pageId);
            if ((int) $page->draft_revision !== $expectedRevision) {
                throw new ConflictHttpException('Stale website draft; reload before editing.');
            }
            $this->assertCompanyMedia($companyId, $content, false);
            $next = $expectedRevision + 1;
            $id = (string) Str::uuid();
            DB::table('site_page_revisions')->insert([
                'id' => $id,
                'company_id' => $companyId,
                'page_id' => $pageId,
                'revision_number' => $next,
                'content' => json_encode($content, JSON_THROW_ON_ERROR),
                'created_by' => $actor->getKey(),
            ]);
            DB::table('site_pages')->where('id', $pageId)->where('company_id', $companyId)->update([
                'draft_revision' => $next,
                'updated_at' => now(),
            ]);
            $this->audit($companyId, $actor, 'website.draft_saved', $pageId,
                ['revision' => $expectedRevision], ['revision' => $next, 'revision_id' => $id], $reason);

            return $next;
        }, 3);
    }

    /** Publish only the latest validated draft and atomically record its provenance. */
    public function publish(
        User $actor,
        string $companyId,
        string $pageId,
        int $expectedDraftRevision,
        string $reason,
    ): string {
        $this->authorize($actor, $companyId, true);
        $this->reason($reason);

        return DB::transaction(function () use ($actor, $companyId, $pageId, $expectedDraftRevision, $reason): string {
            $page = $this->pageForUpdate($companyId, $pageId);
            $this->authorize($actor, $companyId, true);
            if ($expectedDraftRevision < 1 || (int) $page->draft_revision !== $expectedDraftRevision) {
                throw new ConflictHttpException('Stale website draft; reload before publishing.');
            }
            $revision = DB::table('site_page_revisions')
                ->where('company_id', $companyId)
                ->where('page_id', $pageId)
                ->where('revision_number', $expectedDraftRevision)
                ->first();
            if (! $revision) {
                throw new ConflictHttpException('Draft revision is unavailable.');
            }
            $content = json_decode($revision->content, true, 512, JSON_THROW_ON_ERROR);
            $this->validator->validate($content);
            $this->assertCompanyMedia($companyId, $content, true);
            if ($page->published_revision_id === $revision->id) {
                return $revision->id; // Idempotent retry; no duplicate history entry.
            }

            DB::table('site_pages')->where('id', $pageId)->where('company_id', $companyId)->update([
                'published_revision_id' => $revision->id,
                'updated_at' => now(),
            ]);
            DB::table('site_publishing_events')->insert([
                'id' => (string) Str::uuid(),
                'company_id' => $companyId,
                'page_id' => $pageId,
                'revision_id' => $revision->id,
                'previous_revision_id' => $page->published_revision_id,
                'action' => 'publish',
                'actor_id' => $actor->getKey(),
            ]);
            $this->audit($companyId, $actor, 'website.published', $pageId,
                ['published_revision_id' => $page->published_revision_id],
                ['published_revision_id' => $revision->id, 'draft_revision' => $expectedDraftRevision], $reason);

            return $revision->id;
        }, 3);
    }


    /**
     * Explicitly remove a live published pointer without deleting the page,
     * draft, old snapshot, or immutable history. The UUID is a retry identity.
     * No business/customer data is exposed by this operation.
     */
    public function unpublish(
        User $actor, string $companyId, string $pageId,
        string $requestId, string $expectedPublishedRevisionId, string $reason,
    ): void {
        $this->authorize($actor, $companyId, true);
        $reason = trim($reason);
        $this->reason($reason);
        if (! Str::isUuid($requestId) || ! Str::isUuid($expectedPublishedRevisionId)
            || mb_strlen($reason) < 3
            || preg_match('/[<>@[:cntrl:]]|https?:\/\/|www\./iu', $reason)) {
            throw new InvalidArgumentException('Provide a valid publication request and plain reason.');
        }

        DB::transaction(function () use (
            $actor, $companyId, $pageId, $requestId, $expectedPublishedRevisionId, $reason
        ): void {
            $page = $this->pageForUpdate($companyId, $pageId);
            $this->authorize($actor, $companyId, true);
            $existingEvent = DB::table('site_publishing_events')->where('id', $requestId)->first();
            if ($existingEvent) {
                $latest = DB::table('site_publishing_events')
                    ->where('company_id', $companyId)->where('page_id', $pageId)
                    ->orderByDesc('created_at')->orderByDesc('id')->first();
                $audit = DB::table('audit_events')->where('company_id', $companyId)
                    ->where('entity_type', 'site_page')->where('entity_id', $pageId)
                    ->where('action', 'website.unpublished')
                    ->where('actor_id', $actor->getKey())
                    ->orderByDesc('created_at')->first(['context']);
                $context = $audit
                    ? json_decode($audit->context, true, 512, JSON_THROW_ON_ERROR)
                    : null;
                if ($page->published_revision_id === null
                    && $latest?->id === $requestId
                    && $existingEvent->company_id === $companyId
                    && $existingEvent->page_id === $pageId
                    && $existingEvent->action === 'unpublish'
                    && $existingEvent->revision_id === $expectedPublishedRevisionId
                    && $existingEvent->previous_revision_id === $expectedPublishedRevisionId
                    && (int) $existingEvent->actor_id === (int) $actor->getKey()
                    && ($context['requestId'] ?? null) === $requestId
                    && ($context['reason'] ?? null) === $reason) {
                    return; // Exact same authorized request, no duplicate event or audit.
                }
                throw new ConflictHttpException('Publication request key is already used.');
            }

            if ($page->published_revision_id !== $expectedPublishedRevisionId) {
                throw new ConflictHttpException('Published snapshot changed; reload before unpublishing.');
            }

            DB::table('site_pages')->where('company_id', $companyId)
                ->where('id', $pageId)->update([
                    'published_revision_id' => null, 'updated_at' => now(),
                ]);
            // Revision_id is non-null and FK-proven in the existing event table.
            DB::table('site_publishing_events')->insert([
                'id' => $requestId, 'company_id' => $companyId,
                'page_id' => $pageId, 'revision_id' => $expectedPublishedRevisionId,
                'previous_revision_id' => $expectedPublishedRevisionId,
                'action' => 'unpublish', 'actor_id' => $actor->getKey(),
            ]);
            $this->audit($companyId, $actor, 'website.unpublished', $pageId,
                ['published_revision_id' => $expectedPublishedRevisionId],
                ['published_revision_id' => null, 'draft_revision' => (int) $page->draft_revision],
                $reason, $requestId);
        }, 3);
    }

    /**
     * Restore an earlier immutable snapshot by creating a NEW publishing event.
     * Does not mutate or discard the latest draft; checked CAS avoids stale rolls.
     */
    public function restorePublishedRevision(
        User $actor,
        string $companyId,
        string $pageId,
        string $targetRevisionId,
        ?string $expectedPublishedRevisionId,
        string $reason,
    ): string {
        $this->authorize($actor, $companyId, true);
        $this->reason($reason);

        return DB::transaction(function () use ($actor, $companyId, $pageId, $targetRevisionId, $expectedPublishedRevisionId, $reason): string {
            $page = $this->pageForUpdate($companyId, $pageId);
            $this->authorize($actor, $companyId, true);
            if ($page->published_revision_id !== $expectedPublishedRevisionId) {
                throw new ConflictHttpException('Published snapshot changed; reload before restoring.');
            }

            $revision = DB::table('site_page_revisions')
                ->where('company_id', $companyId)
                ->where('page_id', $pageId)
                ->where('id', $targetRevisionId)
                ->first();
            if (! $revision) {
                throw new InvalidArgumentException('The selected published snapshot is unavailable for this page.');
            }
            // A restore may use only content that was previously published,
            // not any arbitrary draft revision created since publication.
            if (! DB::table('site_publishing_events')
                ->where('company_id', $companyId)
                ->where('page_id', $pageId)
                ->where('revision_id', $targetRevisionId)
                ->exists()) {
                throw new InvalidArgumentException('Only previously published snapshots can be restored.');
            }

            $content = json_decode($revision->content, true, 512, JSON_THROW_ON_ERROR);
            $this->validator->validate($content);
            $this->assertCompanyMedia($companyId, $content, true);

            if ($targetRevisionId === $page->published_revision_id) {
                return $targetRevisionId; // Retry without duplicate history.
            }

            DB::table('site_pages')->where('id', $pageId)->where('company_id', $companyId)->update([
                'published_revision_id' => $targetRevisionId,
                'updated_at' => now(),
            ]);
            DB::table('site_publishing_events')->insert([
                'id' => (string) Str::uuid(),
                'company_id' => $companyId,
                'page_id' => $pageId,
                'revision_id' => $targetRevisionId,
                'previous_revision_id' => $page->published_revision_id,
                'action' => 'restore',
                'actor_id' => $actor->getKey(),
            ]);
            $this->audit($companyId, $actor, 'website.restored', $pageId,
                ['published_revision_id' => $page->published_revision_id],
                ['published_revision_id' => $targetRevisionId, 'draft_revision' => (int) $page->draft_revision], $reason);

            return $targetRevisionId;
        }, 3);
    }

    /** Only published snapshots are eligible for public rendering, never drafts. */
    public function publishedContent(string $companyId, string $pageId): ?array
    {
        $page = DB::table('site_pages')
            ->join('websites', function ($join): void {
                $join->on('site_pages.website_id', '=', 'websites.id')
                    ->on('site_pages.company_id', '=', 'websites.company_id');
            })
            ->where('site_pages.id', $pageId)
            ->where('site_pages.company_id', $companyId)
            ->where('websites.is_active', true)
            ->first(['site_pages.published_revision_id']);
        if (! $page || ! $page->published_revision_id) {
            return null;
        }

        $revision = DB::table('site_page_revisions')
            ->where('company_id', $companyId)
            ->where('page_id', $pageId)
            ->where('id', $page->published_revision_id)
            ->first(['content']);

        return $revision ? json_decode($revision->content, true, 512, JSON_THROW_ON_ERROR) : null;
    }

    private function pageForUpdate(string $companyId, string $pageId): object
    {
        $page = DB::table('site_pages')
            ->where('company_id', $companyId)
            ->where('id', $pageId)
            ->lockForUpdate()
            ->first();
        if (! $page || ! DB::table('websites')
            ->where('company_id', $companyId)
            ->where('id', $page->website_id)
            ->where('is_active', true)->exists()) {
            throw (new ModelNotFoundException)->setModel('site_pages');
        }

        return $page;
    }

    private function authorize(User $actor, string $companyId, bool $publish): void
    {
        if (! Auth::check() || ! $actor->hasVerifiedEmail()
            || (int) Auth::id() !== (int) $actor->getKey()
            || ! ($publish
                ? $this->access->canPublishWebsite($actor, $companyId)
                : $this->access->canEditWebsite($actor, $companyId))) {
            throw new AuthorizationException('Not authorized for this website operation.');
        }
    }

    private function reason(string $reason): void
    {
        if (trim($reason) === '' || mb_strlen($reason) > 500) {
            throw new InvalidArgumentException('A concise change reason is required.');
        }
    }

    /** Media references must be owned by this company; publishing requires approval. */
    private function assertCompanyMedia(string $companyId, array $content, bool $publishing): void
    {
        $ids = [];
        $collect = function (array $blocks) use (&$collect, &$ids): void {
            foreach ($blocks as $block) {
                foreach (($block['data']['mediaIds'] ?? []) as $id) {
                    $ids[$id] = true;
                }
                if (! empty($block['data']['imageId'])) {
                    $ids[$block['data']['imageId']] = true;
                }
                foreach (($block['columns'] ?? []) as $column) {
                    $collect($column['blocks']);
                }
            }
        };
        $collect($content['sections']);
        if (! $ids) {
            return;
        }
        foreach (array_keys($ids) as $id) {
            if (! Str::isUuid($id)) {
                throw new InvalidArgumentException('Website media must reference an uploaded asset.');
            }
        }

        $query = DB::table('site_media')->where('company_id', $companyId)
            ->whereIn('id', array_keys($ids));
        if ($publishing) {
            $query->where('status', 'approved');
        } else {
            $query->whereIn('status', ['pending', 'approved']);
        }
        if ($query->count() !== count($ids)) {
            throw new InvalidArgumentException('Website media is unavailable or not approved for this company.');
        }
    }

    private function audit(
        string $companyId, User $actor, string $action, string $pageId,
        array $before, array $after, string $reason, ?string $requestId = null,
    ): void {
        DB::table('audit_events')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $companyId,
            'actor_id' => $actor->getKey(),
            'action' => $action,
            'entity_type' => 'site_page',
            'entity_id' => $pageId,
            'before_values' => json_encode($before, JSON_THROW_ON_ERROR),
            'after_values' => json_encode($after, JSON_THROW_ON_ERROR),
            'context' => json_encode($requestId === null
                ? ['reason' => trim($reason)]
                : ['reason' => trim($reason), 'requestId' => $requestId], JSON_THROW_ON_ERROR),
        ]);
    }
}
