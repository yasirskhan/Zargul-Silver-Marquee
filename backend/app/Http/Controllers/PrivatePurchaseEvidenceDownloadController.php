<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\HallInventoryPurchaseEvidenceFileService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * No public-storage URL. Independent owner/admin reviewer only; integrity
 * rechecked against immutable database digest on actual returned bytes.
 * Never infer supplier authenticity, receiving truth, or a paid invoice.
 */
final class PrivatePurchaseEvidenceDownloadController
{
    public function __invoke(Request $request, string $companyId, string $hallId,
        string $purchaseId, string $fileId)
    {
        $actor=$request->user();
        abort_unless($actor instanceof User,403);
        abort_unless(Str::isUuid($companyId) && Str::isUuid($hallId) &&
            Str::isUuid($purchaseId) && Str::isUuid($fileId),404);
        app(HallInventoryPurchaseEvidenceFileService::class)->verify(
            $actor,$companyId,$hallId,$purchaseId,$fileId);
        $row=DB::table('hall_inventory_purchase_evidence_files')
            ->where('company_id',$companyId)->where('hall_id',$hallId)
            ->where('purchase_request_id',$purchaseId)->where('id',$fileId)->first();
        if (!$row)throw new ConflictHttpException('Private evidence no longer available.');
        $bytes=Storage::disk('local')->get((string)$row->storage_path);
        if (!is_string($bytes) || strlen($bytes)!==(int)$row->size_bytes ||
            !hash_equals((string)$row->sha256,hash('sha256',$bytes))) {
            throw new ConflictHttpException('Private evidence digest mismatch; download denied.');
        }
        // Fixed filenames and attachment disposition prevent untrusted PDFs/
        // images being displayed as trusted same-origin HTML or active content.
        $filename= $row->evidence_kind==='receiving_photo'
            ? 'receiving-evidence.bin' : 'supplier-evidence.bin';
        DB::table('audit_events')->insert([
            'id'=>(string)Str::uuid(),'company_id'=>$companyId,
            'actor_id'=>$actor->getKey(),
            'action'=>'hall_inventory.purchase_evidence_downloaded',
            'entity_type'=>'hall_inventory_purchase_evidence_file',
            'entity_id'=>$fileId,
            'before_values'=>json_encode([],JSON_THROW_ON_ERROR),
            'after_values'=>json_encode(['purchaseId'=>$purchaseId,
                'kind'=>$row->evidence_kind,'sha256'=>$row->sha256],JSON_THROW_ON_ERROR),
            'context'=>json_encode(['hallId'=>$hallId],JSON_THROW_ON_ERROR),
        ]);
        return response($bytes,200,[
            'Content-Type'=>'application/octet-stream',
            'Content-Disposition'=>'attachment; filename="'.$filename.'"',
            'Cache-Control'=>'private, no-store, no-cache, max-age=0',
            'Pragma'=>'no-cache',
            'X-Content-Type-Options'=>'nosniff',
        ]);
    }
}
