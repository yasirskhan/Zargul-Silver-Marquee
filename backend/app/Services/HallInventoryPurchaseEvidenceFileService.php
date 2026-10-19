<?php
namespace App\Services;

use App\Models\Hall;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Private, bounded, hash-pinned evidence custody. Upload is proof of received
 * BYTES ONLY: it does not authenticate a supplier, payment, or physical count.
 */
final class HallInventoryPurchaseEvidenceFileService
{
    public function __construct(private readonly TenantAccess $access) {}

    public function attach(User $actor,string $companyId,string $hallId,
        string $purchaseId,string $fileId,string $kind,UploadedFile $file):string
    {
        if (!config('inventory_controls.purchase_receipts_enabled') ||
            !config('inventory_controls.purchase_evidence_upload_enabled'))
            throw new ConflictHttpException('Purchase evidence upload is disabled.');
        if (!Str::isUuid($companyId)||!Str::isUuid($hallId)||
            !Str::isUuid($purchaseId)||!Str::isUuid($fileId)||
            !in_array($kind,['supplier_document','receiving_photo'],true))
            throw new InvalidArgumentException('Invalid scoped evidence reference.');
        $path=$file->getRealPath();
        $size=$file->getSize();
        if (!$file->isValid() || !$path || !is_int($size) || $size<1 ||
            $size>5242880 || !is_file($path))
            throw new InvalidArgumentException('Invalid evidence file.');
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!in_array($mime,['image/jpeg','image/png','application/pdf'],true))
            throw new InvalidArgumentException('Unsupported evidence content.');
        $sha=hash_file('sha256',$path);
        if (!is_string($sha))throw new ConflictHttpException('Cannot hash evidence.');
        $storage=Storage::disk('local');
        $key='private/hall-purchase-evidence/'.$companyId.'/'.$hallId.'/'.$purchaseId.'/'.$fileId;
        return DB::transaction(function()use($actor,$companyId,$hallId,$purchaseId,$fileId,$kind,$file,$size,$sha,$key,$storage):string {
            $hall=Hall::query()->where('company_id',$companyId)->where('id',$hallId)
                ->where('is_active',true)->lockForUpdate()->first();
            if (!$hall || !Auth::check() || (int)Auth::id()!==(int)$actor->getKey() ||
                !$this->access->canManageHallInventory($actor,$hall))
                throw new AuthorizationException('Evidence custody denied.');
            $claim=DB::table('hall_inventory_purchase_requests')
                ->where('company_id',$companyId)->where('hall_id',$hallId)
                ->where('id',$purchaseId)->lockForUpdate()->first();
            if (!$claim)throw new ConflictHttpException('Purchase claim does not belong to hall.');
            if ($kind==='supplier_document' && (int)$claim->requested_by!==(int)$actor->getKey())
                throw new AuthorizationException('Only original requester may submit supplier document.');
            if ($kind==='receiving_photo') {
                $receipt=DB::table('hall_inventory_purchase_receiving_evidence')
                    ->where('company_id',$companyId)->where('hall_id',$hallId)
                    ->where('purchase_request_id',$purchaseId)->first();
                if (!$receipt || (int)$receipt->received_by!==(int)$actor->getKey())
                    throw new AuthorizationException('Only independent receiver may upload receiving photo.');
            }
            $old=DB::table('hall_inventory_purchase_evidence_files')
                ->where('purchase_request_id',$purchaseId)->where('evidence_kind',$kind)->first();
            if ($old) {
                if ((string)$old->id!==$fileId || (int)$old->uploaded_by!==(int)$actor->getKey() ||
                    !hash_equals((string)$old->sha256,$sha) || (int)$old->size_bytes!==$size ||
                    !$storage->exists($old->storage_path) ||
                    !hash_equals($sha,hash('sha256',$storage->get($old->storage_path))))
                    throw new ConflictHttpException('Evidence already attached or bytes are missing.');
                return $fileId;
            }
            if (DB::table('hall_inventory_purchase_evidence_files')->where('id',$fileId)->exists())
                throw new ConflictHttpException('Evidence file ID reused.');
            if (!$storage->put($key,file_get_contents($file->getRealPath())))
                throw new ConflictHttpException('Private evidence write failed.');
            try {
                DB::table('hall_inventory_purchase_evidence_files')->insert([
                    'id'=>$fileId,'company_id'=>$companyId,'hall_id'=>$hallId,
                    'purchase_request_id'=>$purchaseId,'evidence_kind'=>$kind,
                    'sha256'=>$sha,'size_bytes'=>$size,'storage_path'=>$key,'uploaded_by'=>$actor->getKey()
                ]);
                DB::table('audit_events')->insert([
                    'id'=>(string)Str::uuid(),'company_id'=>$companyId,
                    'actor_id'=>$actor->getKey(),'action'=>'hall_inventory.purchase_evidence_uploaded',
                    'entity_type'=>'hall_inventory_purchase_evidence_file','entity_id'=>$fileId,
                    'before_values'=>json_encode([],JSON_THROW_ON_ERROR),
                    'after_values'=>json_encode(['purchaseId'=>$purchaseId,'kind'=>$kind,
                        'sha256'=>$sha,'size'=>$size,'state'=>'UNVERIFIED_PRIVATE_EVIDENCE'],JSON_THROW_ON_ERROR),
                    'context'=>json_encode(['hallId'=>$hallId],JSON_THROW_ON_ERROR),
                ]);
            }catch(\Throwable $e) {
                $storage->delete($key);
                throw $e;
            }
            return $fileId;
        },3);
    }
    /**
     * Reviewer-facing integrity check. Returns metadata, never a public URL or
     * raw bytes. A passing hash proves retained bytes match their upload only.
     */
    public function verify(User $reviewer,string $companyId,string $hallId,
        string $purchaseId,string $fileId):array
    {
        if (!config('inventory_controls.purchase_receipts_enabled') ||
            !config('inventory_controls.purchase_evidence_upload_enabled'))
            throw new ConflictHttpException('Private purchase evidence is disabled.');
        if (!Str::isUuid($companyId)||!Str::isUuid($hallId)||
            !Str::isUuid($purchaseId)||!Str::isUuid($fileId))
            throw new InvalidArgumentException('Invalid evidence lookup scope.');
        $hall=Hall::query()->where('company_id',$companyId)->where('id',$hallId)
            ->where('is_active',true)->first();
        if (!$hall || !Auth::check() || (int)Auth::id()!==(int)$reviewer->getKey() ||
            !$this->access->canConfigureHall($reviewer,$hall))
            throw new AuthorizationException('Private evidence verification denied.');
        $row=DB::table('hall_inventory_purchase_evidence_files')
            ->where('company_id',$companyId)->where('hall_id',$hallId)
            ->where('purchase_request_id',$purchaseId)->where('id',$fileId)->first();
        if (!$row)throw new ConflictHttpException('Scoped private evidence not found.');
        $disk=Storage::disk('local');
        if (!$disk->exists($row->storage_path))
            throw new ConflictHttpException('Private evidence missing; do not accept stock.');
        $bytes=$disk->get($row->storage_path);
        if (!is_string($bytes) || strlen($bytes)!==(int)$row->size_bytes ||
            !hash_equals((string)$row->sha256,hash('sha256',$bytes)))
            throw new ConflictHttpException('Private evidence integrity failure; do not accept stock.');
        return [
            'id'=>(string)$row->id,
            'kind'=>(string)$row->evidence_kind,
            'sha256'=>(string)$row->sha256,
            'sizeBytes'=>(int)$row->size_bytes,
            'bytesIntact'=>true,
            'supplierAuthenticityVerified'=>false,
            'physicalReceiptVerified'=>false,
            'paymentVerified'=>false,
            'stockPosted'=>false,
        ];
    }

}
