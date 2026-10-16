<?php

namespace App\Services;

use App\Models\CompanyMembership;
use App\Models\SitePage;
use App\Models\User;
use App\Models\Website;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class SiteWorkspaceService
{
    public const NEW_PAGE_PATHS = [
        '/halls' => 'Halls',
        '/packages' => 'Packages',
        '/services' => 'Services',
        '/gallery' => 'Gallery',
        '/about' => 'About',
        '/contact' => 'Contact',
        '/privacy' => 'Privacy',
        '/terms' => 'Terms',
    ];

    public function __construct(private readonly TenantAccess $access) {}

    /** Companies listed here are the only companies a verified site editor may inspect. */
    public function companyOptions(User $actor): array
    {
        if (! $actor->is_active || ! $actor->hasVerifiedEmail()
            || ! DB::table('users')->where('id', $actor->getKey())
                ->where('is_active', true)->whereNotNull('email_verified_at')->exists()) {
            return [];
        }

        $memberships = CompanyMembership::query()
            ->where('user_id', $actor->getKey())
            ->where('is_active', true)
            ->whereHas('company', fn ($query) => $query->where('is_active', true))
            ->with('company')
            ->get();

        $options = [];
        foreach ($memberships as $membership) {
            $companyId = $membership->company_id;
            if ($this->access->canEditWebsite($actor, $companyId)
                && Website::query()->forCompany($companyId)->where('is_active', true)->exists()) {
                $options[$companyId] = $membership->company->name;
            }
        }

        asort($options);

        return $options;
    }

    public function pageOptions(User $actor, string $companyId): array
    {
        if (! array_key_exists($companyId, $this->companyOptions($actor))) {
            return [];
        }

        return SitePage::query()
            ->forCompany($companyId)
            ->whereHas('website', fn ($query) => $query
                ->where('company_id', $companyId)
                ->where('is_active', true))
            ->orderBy('title')
            ->pluck('title', 'id')
            ->all();
    }

    /** Never trust a Livewire-supplied tenant or page ID without this fresh scope. */
    public function page(User $actor, string $companyId, string $pageId): ?SitePage
    {
        if (! array_key_exists($companyId, $this->companyOptions($actor))) {
            return null;
        }

        return SitePage::query()
            ->forCompany($companyId)
            ->whereKey($pageId)
            ->whereHas('website', fn ($query) => $query
                ->where('company_id', $companyId)
                ->where('is_active', true))
            ->first();
    }
    /**
     * Create an initially UNPUBLISHED site page. Caller-provided UUID survives
     * browser retry; exact actor/payload/reason are recorded in immutable audit.
     * Only fixed public paths supported. Never publish or expose an empty draft.
     */
    public function createPage(
        User $actor, string $companyId, string $websiteId, string $pageId,
        string $slug, string $title, string $reason,
    ): string {
        if (! Auth::check() || (int) Auth::id() !== (int) $actor->getKey()
            || ! $actor->is_active || ! $actor->hasVerifiedEmail()
            || ! DB::table('users')->where('id', $actor->getKey())
                ->where('is_active', true)->whereNotNull('email_verified_at')->exists()
            || ! $this->access->canEditWebsite($actor, $companyId)) {
            throw new AuthorizationException('Verified website editor access is required.');
        }
        $title = trim($title);
        $reason = trim($reason);
        if (! Str::isUuid($websiteId) || ! Str::isUuid($pageId)
            || ! array_key_exists($slug, self::NEW_PAGE_PATHS)
            || mb_strlen($title) < 3 || mb_strlen($title) > 120
            || mb_strlen($reason) < 3 || mb_strlen($reason) > 500
            || preg_match('/[<>@[:cntrl:]]|https?:\/\/|www\./iu', $title.$reason)) {
            throw new InvalidArgumentException('Use an approved page path and plain descriptive text without contacts.');
        }

        return DB::transaction(function () use ($actor, $companyId, $websiteId, $pageId, $slug, $title, $reason): string {
            // The website lock serializes competing slug creation. Reauthorize
            // after lock acquisition to avoid stale or revoked access.
            $site = DB::table('websites')->where('company_id', $companyId)
                ->where('id', $websiteId)->where('is_active', true)
                ->lockForUpdate()->first(['id']);
            if (! $site || ! Auth::check() || (int) Auth::id() !== (int) $actor->getKey()
                || ! $actor->hasVerifiedEmail()
                // A hydrated editor may be disabled while waiting for the
                // tenant website lock. Query CURRENT account state here.
                || ! DB::table('users')->where('id', $actor->getKey())
                    ->where('is_active', true)->whereNotNull('email_verified_at')->exists()
                || ! $this->access->canEditWebsite($actor, $companyId)) {
                throw new AuthorizationException('Website is not accessible.');
            }
            $existing = DB::table('site_pages')->where('id', $pageId)->first();
            if ($existing) {
                $audit = DB::table('audit_events')->where('company_id', $companyId)
                    ->where('entity_type', 'site_page')->where('entity_id', $pageId)
                    ->where('action', 'website.page_created')
                    ->where('actor_id', $actor->getKey())->first(['context', 'after_values']);
                $details = $audit ? json_decode($audit->after_values, true, 512, JSON_THROW_ON_ERROR) : null;
                $context = $audit ? json_decode($audit->context, true, 512, JSON_THROW_ON_ERROR) : null;
                if ($existing->company_id === $companyId
                    && $existing->website_id === $websiteId
                    && $existing->slug === $slug && $existing->title === $title
                    && ($details['slug'] ?? null) === $slug
                    && ($details['title'] ?? null) === $title
                    && ($context['reason'] ?? null) === $reason) {
                    return $pageId;
                }
                throw new ConflictHttpException('Page request key is assigned to a different operation.');
            }
            if (DB::table('site_pages')->where('website_id', $websiteId)
                ->where('slug', $slug)->exists()) {
                throw new ConflictHttpException('That page path already exists in the selected website.');
            }
            DB::table('site_pages')->insert([
                'id' => $pageId, 'company_id' => $companyId,
                'website_id' => $websiteId, 'slug' => $slug, 'title' => $title,
                'draft_revision' => 0, 'published_revision_id' => null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('audit_events')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $companyId,
                'actor_id' => $actor->getKey(), 'action' => 'website.page_created',
                'entity_type' => 'site_page', 'entity_id' => $pageId,
                'before_values' => null,
                'after_values' => json_encode([
                    'websiteId' => $websiteId, 'slug' => $slug, 'title' => $title,
                    'status' => 'unpublished',
                ], JSON_THROW_ON_ERROR),
                'context' => json_encode(['reason' => $reason], JSON_THROW_ON_ERROR),
            ]);
            return $pageId;
        }, 3);
    }

}
