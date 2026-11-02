<?php

namespace App\Services;

use App\Models\Hall;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Company-scoped, owner/admin-managed hall photography. */
final class HallMediaService
{
    public function __construct(private readonly TenantAccess $access) {}

    /** @return array<int,array{id:string,alt:string}> */
    public function availableMedia(User $actor, string $companyId): array
    {
        return DB::table('site_media')->where('company_id', $companyId)
            ->where('status', 'approved')->orderByDesc('created_at')->limit(100)->get()
            ->map(fn ($row): array => ['id' => $row->id,
                'alt' => (string) ($row->alt_text ?? 'Approved venue image')])->all();
    }

    /** @return array<int,string> */
    public function selectedMedia(string $companyId, string $hallId): array
    {
        return DB::table('hall_media')->where('company_id', $companyId)
            ->where('hall_id', $hallId)->orderBy('sort_order')->pluck('media_id')->all();
    }

    /** @param array<int,string> $mediaIds */
    public function replace(User $actor, string $companyId, string $hallId,
        array $mediaIds, string $reason): void
    {
        $hall = Hall::query()->where('company_id', $companyId)->where('id', $hallId)->first();
        if (! $hall || ! $this->access->canConfigureHall($actor, $hall)) {
            abort(403);
        }
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new InvalidArgumentException('A short internal reason is required.');
        }
        $mediaIds = array_values(array_unique(array_filter($mediaIds, 'is_string')));
        if (count($mediaIds) > 10) {
            throw new InvalidArgumentException('A hall can show at most 10 approved photographs.');
        }
        $owned = DB::table('site_media')->where('company_id', $companyId)
            ->whereIn('id', $mediaIds)->where('status', 'approved')->count();
        if ($owned !== count($mediaIds)) {
            throw new InvalidArgumentException('Every selected image must be approved for this company.');
        }
        DB::transaction(function () use ($actor, $companyId, $hallId, $mediaIds, $reason): void {
            $before = $this->selectedMedia($companyId, $hallId);
            DB::table('hall_media')->where('company_id', $companyId)->where('hall_id', $hallId)->delete();
            foreach ($mediaIds as $index => $mediaId) {
                DB::table('hall_media')->insert([
                    'id' => (string) Str::uuid(), 'company_id' => $companyId,
                    'hall_id' => $hallId, 'media_id' => $mediaId,
                    'sort_order' => $index, 'created_by' => $actor->getKey(),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('audit_events')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $companyId,
                'actor_id' => $actor->getKey(), 'action' => 'website.hall_photos_updated',
                'entity_type' => 'hall', 'entity_id' => $hallId,
                'before_values' => json_encode(['mediaIds' => $before], JSON_THROW_ON_ERROR),
                'after_values' => json_encode(['mediaIds' => $mediaIds], JSON_THROW_ON_ERROR),
                'context' => json_encode(['reason' => $reason], JSON_THROW_ON_ERROR),
            ]);
        }, 3);
    }
}