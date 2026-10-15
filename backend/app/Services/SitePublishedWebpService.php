<?php

namespace App\Services;

use finfo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Safe server-side read of an approved optimized image referenced by the
 * CURRENT published website snapshot. No HTTP route or public disk writes.
 * This returns only revalidated, metadata-stripped WebP, never original bytes.
 */
final class SitePublishedWebpService
{
    public function __construct(private readonly SitePublishedSnapshotService $snapshots) {}

    public function read(
        string $companyId, string $websiteId, string $pageSlug, string $mediaId,
    ): ?array {
        if (! Str::isUuid($mediaId)) {
            return null;
        }

        return DB::transaction(function () use ($companyId, $websiteId, $pageSlug, $mediaId): ?array {
            $snapshot = $this->snapshots->project($companyId, $websiteId, $pageSlug);
            $approved = $snapshot['media'][$mediaId] ?? null;
            if (! is_array($approved)) {
                return null;
            }

            // A shared DB lock prevents an approval-status change halfway
            // through this read. Revalidate bytes AFTER selecting the asset.
            $asset = DB::table('site_media')->where('company_id', $companyId)
                ->where('id', $mediaId)->where('status', 'approved')
                ->sharedLock()->first(['metadata']);
            if (! $asset) {
                throw new InvalidArgumentException('Published image approval was revoked.');
            }
            $metadata = json_decode($asset->metadata, true, 512, JSON_THROW_ON_ERROR);
            $variant = $metadata['variants']['webp1200'] ?? [];
            $path = is_array($variant) ? ($variant['object_key'] ?? null) : null;
            $digest = is_array($variant) ? ($variant['sha256'] ?? null) : null;
            $validPath = '~\Asite-media/private/'.preg_quote($companyId, '~').'/[a-f0-9-]{36}-webp1200\.webp\z~';

            if (! is_string($path) || ! preg_match($validPath, $path)
                || ! is_string($digest) || ! hash_equals($approved['sha256'], $digest)
                || ($variant['mime_type'] ?? null) !== 'image/webp'
                || ($variant['bytes'] ?? null) !== $approved['bytes']
                || ($variant['width'] ?? null) !== $approved['width']
                || ($variant['height'] ?? null) !== $approved['height']
                || ! Storage::disk('local')->exists($path)) {
                throw new InvalidArgumentException('Published image variant was replaced or removed.');
            }

            $bytes = Storage::disk('local')->get($path);
            $dimensions = is_string($bytes) ? @getimagesizefromstring($bytes) : false;
            if (! is_string($bytes)
                || strlen($bytes) !== $approved['bytes']
                || ! hash_equals($digest, hash('sha256', $bytes))
                || (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes) !== 'image/webp'
                || ! is_array($dimensions)
                || $dimensions[0] !== $approved['width']
                || $dimensions[1] !== $approved['height']) {
                throw new InvalidArgumentException('Published image content has changed.');
            }

            return [
                'bytes' => $bytes,
                'content_type' => 'image/webp',
                'etag' => '"'.$digest.'"',
                'length' => strlen($bytes),
                'width' => $approved['width'],
                'height' => $approved['height'],
            ];
        });
    }
}
