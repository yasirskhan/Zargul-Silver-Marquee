<?php

namespace App\Services;

use App\Models\User;
use finfo;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Staging and approval only. Do not serve raw staged uploads on public routes.
 * Publishing of optimized, safe variants is a separate release-gated feature.
 */
final class SiteMediaService
{
    private const ALLOWED = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(private readonly TenantAccess $access) {}

    public function stageImage(
        User $actor,
        string $companyId,
        UploadedFile $file,
        string $altText,
        string $reason,
    ): string {
        $this->authorize($actor, $companyId, false);
        $this->validateReason($reason);
        $altText = trim($altText);
        if ($altText === '' || mb_strlen($altText) > 300 || preg_match('/[<>\x00-\x08\x0B\x0C\x0E-\x1F]/', $altText)) {
            throw new InvalidArgumentException('Approved alternative text is required for image accessibility.');
        }

        if (! $file->isValid() || $file->getSize() < 1 || $file->getSize() > 4_000_000) {
            throw new InvalidArgumentException('Image must be a valid upload no larger than 4 MB.');
        }
        $path = $file->getRealPath();
        if (! $path || ! is_file($path)) {
            throw new InvalidArgumentException('Image upload is unavailable.');
        }

        // Sniff real bytes and dimensions, never the client filename/extension.
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        $dimensions = @getimagesize($path);
        if (! is_string($mime) || ! isset(self::ALLOWED[$mime])
            || ! is_array($dimensions)
            || ($dimensions['mime'] ?? '') !== $mime
            || $dimensions[0] < 1 || $dimensions[1] < 1
            || $dimensions[0] > 6000 || $dimensions[1] > 6000
            || $dimensions[0] * $dimensions[1] > 20_000_000) {
            throw new InvalidArgumentException('Only valid JPEG, PNG or WebP images within pixel limits can be staged.');
        }

        $mediaId = (string) Str::uuid();
        $objectKey = 'site-media/private/'.$companyId.'/'.$mediaId.'.'.self::ALLOWED[$mime];
        $sha256 = hash_file('sha256', $path);
        if (! is_string($sha256)) {
            throw new RuntimeException('Cannot calculate image checksum.');
        }

        try {
            return DB::transaction(function () use (
                $actor, $companyId, $file, $altText, $reason, $dimensions, $mime, $mediaId, $objectKey, $sha256
            ): string {
                // Reauthorize after expensive binary inspection and before private persistence.
                $this->authorize($actor, $companyId, false);
                $stored = Storage::disk('local')->putFileAs(
                    'site-media/private/'.$companyId,
                    $file,
                    basename($objectKey),
                    ['visibility' => 'private'],
                );
                if ($stored !== $objectKey) {
                    throw new RuntimeException('Cannot safely stage the image in private storage.');
                }

                DB::table('site_media')->insert([
                    'id' => $mediaId,
                    'company_id' => $companyId,
                    'object_key' => $objectKey,
                    'mime_type' => $mime,
                    'sha256' => $sha256,
                    'alt_text' => $altText,
                    'metadata' => json_encode([
                        'width' => $dimensions[0],
                        'height' => $dimensions[1],
                        'bytes' => $file->getSize(),
                    ], JSON_THROW_ON_ERROR),
                    'status' => 'pending',
                    'uploaded_by' => $actor->getKey(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->audit($companyId, $actor, 'website.media_staged', $mediaId,
                    null, ['status' => 'pending', 'sha256' => $sha256], $reason);

                return $mediaId;
            }, 3);
        } catch (Throwable $exception) {
            // No untracked private upload must survive a database/audit failure.
            Storage::disk('local')->delete($objectKey);
            throw $exception;
        }
    }

    /** Approval is a distinct audited publisher decision, never implied by upload. */
    public function approveImage(User $actor, string $companyId, string $mediaId, string $reason): void
    {
        $this->authorize($actor, $companyId, true);
        $this->validateReason($reason);

        DB::transaction(function () use ($actor, $companyId, $mediaId, $reason): void {
            $this->authorize($actor, $companyId, true);
            $asset = DB::table('site_media')
                ->where('company_id', $companyId)
                ->where('id', $mediaId)
                ->lockForUpdate()
                ->first();
            if (! $asset || ! in_array($asset->status, ['pending', 'approved'], true)) {
                throw new InvalidArgumentException('The image is not available for approval in this company.');
            }
            if ($asset->status === 'approved') {
                return; // Safe idempotent retry without duplicate audit.
            }
            if (! Storage::disk('local')->exists($asset->object_key)) {
                throw new RuntimeException('Private staged image is missing.');
            }
            DB::table('site_media')->where('company_id', $companyId)->where('id', $mediaId)
                ->update(['status' => 'approved', 'updated_at' => now()]);
            $this->audit($companyId, $actor, 'website.media_approved', $mediaId,
                ['status' => 'pending'], ['status' => 'approved'], $reason);
        }, 3);
    }

    private function authorize(User $actor, string $companyId, bool $approving): void
    {
        if (! Auth::check() || (int) Auth::id() !== (int) $actor->getKey()
            || ! $actor->hasVerifiedEmail()
            || ! ($approving
                ? $this->access->canPublishWebsite($actor, $companyId)
                : $this->access->canEditWebsite($actor, $companyId))
            || ! DB::table('websites')->where('company_id', $companyId)->where('is_active', true)->exists()) {
            throw new AuthorizationException('Not authorized to manage this company website media.');
        }
    }

    private function validateReason(string $reason): void
    {
        if (mb_strlen(trim($reason)) < 3 || mb_strlen($reason) > 500) {
            throw new InvalidArgumentException('A concise change reason is required.');
        }
    }

    private function audit(
        string $companyId, User $actor, string $action, string $mediaId,
        ?array $before, array $after, string $reason,
    ): void {
        DB::table('audit_events')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $companyId,
            'actor_id' => $actor->getKey(),
            'action' => $action,
            'entity_type' => 'site_media',
            'entity_id' => $mediaId,
            'before_values' => $before ? json_encode($before, JSON_THROW_ON_ERROR) : null,
            'after_values' => json_encode($after, JSON_THROW_ON_ERROR),
            'context' => json_encode(['reason' => trim($reason)], JSON_THROW_ON_ERROR),
        ]);
    }
}
