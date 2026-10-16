<?php

namespace App\Services;

use finfo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Release-gated public snapshot projection. There is intentionally NO route,
 * private object key, staged upload, raw image byte, or signed URL here.
 *
 * A future public renderer may use only these approved immutable pointers
 * and must separately materialize sanitized WebP assets for public delivery.
 */
final class SitePublishedSnapshotService
{
    public function __construct(
        private readonly SiteContentValidator $pages,
        private readonly SiteBrandingValidator $brandingValidator,
    ) {}

    public function project(string $companyId, string $websiteId, string $slug): ?array
    {
        $site = DB::table('websites')
            ->join('companies', 'websites.company_id', '=', 'companies.id')
            ->where('websites.company_id', $companyId)
            ->where('websites.id', $websiteId)
            ->where('websites.is_active', true)
            ->where('companies.is_active', true)
            ->first(['websites.branding_published_revision_id']);
        if (! $site || ! $site->branding_published_revision_id) {
            return null;
        }

        $page = DB::table('site_pages')
            ->where('company_id', $companyId)
            ->where('website_id', $websiteId)
            ->where('slug', $slug)
            ->first(['id', 'slug', 'title', 'published_revision_id']);
        if (! $page || ! $page->published_revision_id) {
            return null;
        }

        // Read immutable approved revisions directly. Never use draft revision numbers.
        $brandingJson = DB::table('site_branding_revisions')
            ->where('company_id', $companyId)
            ->where('website_id', $websiteId)
            ->where('id', $site->branding_published_revision_id)
            ->value('content');
        $pageJson = DB::table('site_page_revisions')
            ->where('company_id', $companyId)
            ->where('page_id', $page->id)
            ->where('id', $page->published_revision_id)
            ->value('content');
        if (! is_string($brandingJson) || ! is_string($pageJson)) {
            throw new InvalidArgumentException('An approved website snapshot is missing.');
        }

        $branding = json_decode($brandingJson, true, 512, JSON_THROW_ON_ERROR);
        $content = json_decode($pageJson, true, 512, JSON_THROW_ON_ERROR);
        $this->brandingValidator->validate($branding);
        $this->pages->validate($content);

        $mediaIds = [];
        foreach (['logoMediaId', 'faviconMediaId'] as $field) {
            if ($branding[$field] !== null) {
                $mediaIds[$branding[$field]] = true;
            }
        }
        $this->collectMedia($content['sections'], $mediaIds);
        $media = $this->approvedMediaManifest($companyId, array_keys($mediaIds));

        // Publish-only navigation: links can never expose drafts, other
        // websites or unpublished pages. No arbitrary CMS URL is accepted.
        $publicSlugs = ['/', '/halls', '/packages', '/services', '/gallery',
            '/about', '/contact', '/privacy', '/terms'];
        $navigation = DB::table('site_pages')
            ->where('company_id', $companyId)->where('website_id', $websiteId)
            ->whereNotNull('published_revision_id')->whereIn('slug', $publicSlugs)
            ->get(['slug', 'title'])->keyBy('slug');
        $links = [];
        $approvedMenuOrder = $branding['navigationOrder'] ?? $publicSlugs;
        foreach ($approvedMenuOrder as $path) {
            if (isset($navigation[$path])) {
                $links[] = ['href' => $path, 'label' => $navigation[$path]->title];
            }
        }

        return [
            'branding' => $branding,
            'page' => ['slug' => $page->slug, 'title' => $page->title, 'sections' => $content['sections']],
            'media' => $media,
            'navigation' => $links,
        ];
    }

    private function collectMedia(array $sections, array &$ids): void
    {
        foreach ($sections as $section) {
            $data = $section['data'] ?? [];
            if (isset($data['imageId']) && $data['imageId'] !== '') {
                $ids[$data['imageId']] = true;
            }
            foreach (($data['mediaIds'] ?? []) as $id) {
                $ids[$id] = true;
            }
            foreach (($section['columns'] ?? []) as $column) {
                $this->collectMedia($column['blocks'], $ids);
            }
        }
    }

    /** Verify private WebP integrity; return descriptors, NEVER private storage paths. */
    private function approvedMediaManifest(string $companyId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        foreach ($ids as $id) {
            if (! is_string($id) || ! Str::isUuid($id)) {
                throw new InvalidArgumentException('Invalid media reference in approved snapshot.');
            }
        }
        $rows = DB::table('site_media')->where('company_id', $companyId)
            ->where('status', 'approved')->whereIn('id', $ids)
            ->get(['id', 'metadata', 'alt_text']);
        if ($rows->count() !== count($ids)) {
            throw new InvalidArgumentException('Approved website references unavailable images.');
        }
        $manifest = [];
        foreach ($rows as $row) {
            $meta = json_decode($row->metadata, true, 512, JSON_THROW_ON_ERROR);
            $variant = $meta['variants']['webp1200'] ?? null;
            $key = is_array($variant) ? ($variant['object_key'] ?? null) : null;
            $digest = is_array($variant) ? ($variant['sha256'] ?? null) : null;
            $size = $variant['bytes'] ?? null;
            $width = $variant['width'] ?? null;
            $height = $variant['height'] ?? null;
            $expectedPath = '~\Asite-media/private/'.preg_quote($companyId, '~').'/[a-f0-9-]{36}-webp1200\.webp\z~';
            if (! is_string($key) || ! preg_match($expectedPath, $key)
                || ! is_string($digest) || ! preg_match('/\A[a-f0-9]{64}\z/', $digest)
                || ($variant['mime_type'] ?? null) !== 'image/webp'
                || ! is_int($size) || $size < 1 || $size > 4_000_000
                || ! is_int($width) || $width < 1 || $width > 1200
                || ! is_int($height) || $height < 1 || $height > 1200
                || ! Storage::disk('local')->exists($key)) {
                throw new InvalidArgumentException('Approved image has no valid private WebP variant.');
            }
            $bytes = Storage::disk('local')->get($key);
            $dimensions = is_string($bytes) ? @getimagesizefromstring($bytes) : false;
            if (! is_string($bytes) || strlen($bytes) !== $size
                || ! hash_equals($digest, hash('sha256', $bytes))
                || (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes) !== 'image/webp'
                || ! is_array($dimensions) || $dimensions[0] !== $width || $dimensions[1] !== $height) {
                throw new InvalidArgumentException('Approved image integrity check failed.');
            }
            $alt = $row->alt_text;
            if (! is_string($alt) || trim($alt) === '' || mb_strlen($alt) > 300 || preg_match('/[<>\x00-\x1F]/', $alt)) {
                throw new InvalidArgumentException('Approved image is missing safe alternative text.');
            }
            $manifest[$row->id] = [
                'id' => $row->id,
                'alt' => $alt,
                'mime' => 'image/webp',
                'width' => $width,
                'height' => $height,
                'bytes' => $size,
                'sha256' => $digest,
            ];
        }

        return $manifest;
    }
}
