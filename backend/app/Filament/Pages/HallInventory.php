<?php

namespace App\Filament\Pages;

use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\User;
use App\Services\HallInventoryCatalogService;
use App\Services\HallInventoryLoanDraftService;
use App\Services\HallInventoryInternalLoanReturnService;
use App\Services\HallInventoryInternalLoanReturnAcceptanceService;
use App\Services\HallInventoryInternalLoanReturnReview;
use App\Services\HallInventoryStocktakeService;
use App\Services\TenantAccess;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\WithFileUploads;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class HallInventory extends Page
{
    use WithFileUploads;
    protected string $view = 'filament.pages.hall-inventory';
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;
    protected static ?string $navigationLabel = 'Hall inventory';
    protected static ?string $slug = 'hall-inventory';

    #[Url(as: 'company')]
    public ?string $companyId = null;
    public ?string $hallId = null;

    #[Locked]
    public array $catalog = [];
    #[Locked]
    public array $positionReport = [];
    #[Locked]
    public string $createRequestId = '';
    #[Locked]
    public string $countRequestId = '';
    #[Locked]
    public ?string $lastCountReference = null;
    #[Locked]
    public array $stocktakeHistory = [];
    #[Locked]
    public array $stocktakeVariance = [];
    #[Locked]
    public string $varianceStocktakeId = '';
    public string $varianceReviewReason = '';
    #[Locked]
    public string $varianceReviewRequestId = '';
    #[Locked]
    public ?string $lastVarianceReviewReference = null;

    public string $itemName = '';
    public string $itemCategory = 'Crockery';
    public string $itemUnit = 'piece';
    public string $minimumQuantity = '0';
    public string $createReason = '';
    public string $auditKind = 'ad_hoc';
    public string $auditReason = '';
    public array $countRows = [];

    #[Locked]
    public string $loanRequestId = '';
    #[Locked]
    public ?string $lastLoanReference = null;
    #[Locked]
    public array $loanHistory = [];
    #[Locked]
    public array $purchaseHistory = [];
    #[Locked]
    public string $purchaseRequestId = '';
    #[Locked]
    public ?string $lastPurchaseReference = null;
    public string $purchaseItemId = '';
    public string $purchaseQuantity = '';
    public string $purchaseVendor = '';
    public string $purchaseVendorReference = '';
    public string $purchaseEvidence = '';
    public string $purchaseReason = '';


    public string $purchaseUploadPurchaseId = '';
    public string $purchaseUploadKind = 'supplier_document';
    public $purchaseUploadFile = null;
    #[Locked]
    public string $purchaseUploadId = '';
    #[Locked]
    public ?string $lastPurchaseUploadReference = null;
    #[Locked]
    public ?string $lastPurchaseMovementReference = null;

    public string $receivingPurchaseId = '';
    public string $receivingQuantity = '';
    public string $receivingEvidenceRef = '';
    public string $receivingCondition = '';
    #[Locked]
    public string $receivingId = '';
    public string $reviewReceiptId = '';
    public string $reviewReceiptNotes = '';
    #[Locked]
    public string $receivingReviewId = '';
    #[Locked]
    public ?string $lastReceivingReference = null;
    #[Locked]
    public ?string $lastReceivingReviewReference = null;


    #[Locked]
    public array $openingHistory = [];
    #[Locked]
    public string $openingRequestId = '';
    #[Locked]
    public ?string $lastOpeningReference = null;

    public string $openingItemId = '';
    public string $openingStocktakeId = '';
    public string $openingQuantity = '';
    public string $openingReason = '';

    public string $loanDirection = 'internal';
    public string $loanCounterpartHallId = '';
    public string $loanOutsideName = '';
    public string $loanOutsideContact = '';
    public string $loanOutsidePhone = '';
    public string $loanOutsideAddress = '';
    public string $loanReturnDate = '';
    public string $loanPurpose = '';
    public array $loanRows = [];

    #[Locked]
    public string $loanReceiptLoanId = '';
    #[Locked]
    public string $loanReceiptRequestId = '';
    #[Locked]
    public array $loanReceiptLines = [];
    public array $loanReceiptMapping = [];
    public $loanReceiptFile = null;
    #[Locked]
    public ?string $lastLoanReceiptReference = null;

    #[Locked]
    public string $returnLoanId = '';
    #[Locked]
    public string $returnBatchId = '';
    #[Locked]
    public array $returnEligibleLines = [];
    public array $returnQuantities = [];
    public $returnHandoverFile = null;
    #[Locked]
    public ?string $lastReturnBatchId = null;
    #[Locked]
    public array $returnSourceHistory = [];
    #[Locked]
    public string $returnAcceptBatchId = '';
    #[Locked]
    public string $returnAcceptanceId = '';
    #[Locked]
    public array $returnAcceptanceLines = [];
    public array $returnConditionGood = [];
    public array $returnConditionDamaged = [];
    public $returnAcceptanceFile = null;
    #[Locked]
    public ?string $lastReturnAcceptanceId = null;

    #[Locked]
    public string $incidentLoanId = '';
    #[Locked]
    public string $incidentRequestId = '';
    #[Locked]
    public array $incidentLines = [];
    #[Locked]
    public array $incidentHistory = [];
    public string $incidentItemId = '';
    public string $incidentKind = 'damage';
    public string $incidentQuantity = '';
    public string $incidentReason = '';
    #[Locked]
    public ?string $lastIncidentId = null;
    #[Locked]
    public string $incidentDecisionId = '';
    public string $incidentDecisionReportId = '';
    public string $incidentDecision = 'requires_more_evidence';
    public string $incidentDecisionReason = '';
    #[Locked]
    public array $incidentReviews = [];
    #[Locked]
    public ?string $lastIncidentDecisionId = null;

    #[Locked]
    public array $cleanLoanReceipts = [];
    #[Locked]
    public ?string $lastCleanLoanReceipt = null;

    #[Locked]
    public string $loanInlineRequestId = '';
    public ?string $loanInlineRowKey = null;
    public string $loanInlineName = '';
    public string $loanInlineCategory = 'Crockery';
    public string $loanInlineUnit = 'piece';
    public string $loanInlineMinimumQuantity = '0';

    private static function userAllowed(User $actor): bool
    {
        return DB::table('users')->where('id', $actor->getKey())
            ->where('is_active', true)->whereNotNull('email_verified_at')->exists();
    }

    public static function canAccess(): bool
    {
        $actor = Filament::auth()->user();
        if (! config('inventory_controls.catalog_enabled')
            || ! $actor instanceof User || ! self::userAllowed($actor)) {
            return false;
        }
        foreach (static::companiesFor($actor) as $id => $name) {
            if (static::authorizedHallOptions($actor, $id) !== []) {
                return true;
            }
        }
        return false;
    }

    private static function companiesFor(User $actor): array
    {
        return CompanyMembership::query()->with('company')
            ->where('user_id', $actor->getKey())->where('is_active', true)
            ->whereIn('role', ['owner', 'admin', 'manager'])
            ->whereHas('company', fn ($query) => $query->where('is_active', true))
            ->get()->mapWithKeys(fn ($membership) => [
                $membership->company_id => $membership->company->name,
            ])->all();
    }

    private static function authorizedHallOptions(User $actor, string $companyId): array
    {
        $access = app(TenantAccess::class);
        $halls = [];
        foreach (Hall::query()->where('company_id', $companyId)
            ->where('is_active', true)->orderBy('name')->limit(100)->get() as $hall) {
            if ($access->canManageHallInventory($actor, $hall)) {
                $halls[$hall->id] = $hall->name;
            }
        }
        return $halls;
    }

    public function companyOptions(): array
    {
        $actor = Filament::auth()->user();
        return $actor instanceof User && self::userAllowed($actor)
            ? static::companiesFor($actor) : [];
    }

    public function hallOptions(): array
    {
        $actor = Filament::auth()->user();
        return $actor instanceof User && $this->companyId !== null
            && array_key_exists($this->companyId, $this->companyOptions())
            ? static::authorizedHallOptions($actor, $this->companyId) : [];
    }

    public function otherHallOptions(): array
    {
        $this->authorizeSelection();
        return Hall::query()->where('company_id', $this->companyId)
            ->where('is_active', true)->where('id', '<>', $this->hallId)
            ->orderBy('name')->limit(100)->pluck('name', 'id')->all();
    }

    private function authorizeSelection(): User
    {
        abort_unless(static::canAccess() && $this->companyId !== null
            && $this->hallId !== null && array_key_exists($this->hallId, $this->hallOptions()), 403);
        $actor = Filament::auth()->user();
        abort_unless($actor instanceof User, 403);
        return $actor;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $companies = $this->companyOptions();
        abort_unless($this->companyId === null
            || array_key_exists($this->companyId, $companies), 403);
        if ($this->companyId === null) {
            foreach ($companies as $id => $label) {
                if (static::authorizedHallOptions(Filament::auth()->user(), $id) !== []) {
                    $this->companyId = $id;
                    break;
                }
            }
        }
        $this->hallId = array_key_first($this->hallOptions());
        $this->createRequestId = (string) Str::uuid();
        $this->countRequestId = (string) Str::uuid();
        $this->loanRequestId = (string) Str::uuid();
        $this->loanInlineRequestId = (string) Str::uuid();
        $this->openingRequestId = (string) Str::uuid();
        $this->purchaseRequestId = (string) Str::uuid();
        $this->purchaseUploadId=(string)Str::uuid();
        $this->receivingId=(string)Str::uuid();
        $this->receivingReviewId=(string)Str::uuid();
        $this->reloadCatalog();
    }

    public function hydrate(): void
    {
        abort_unless(static::canAccess(), 403);
        abort_unless($this->companyId !== null
            && array_key_exists($this->companyId, $this->companyOptions()), 403);
    }

    public function updatedCompanyId(): void
    {
        abort_unless($this->companyId !== null
            && array_key_exists($this->companyId, $this->companyOptions()), 403);
        $this->hallId = array_key_first($this->hallOptions());
        $this->resetForms();
        $this->reloadCatalog();
    }

    public function updatedHallId(): void
    {
        $this->resetForms();
        $this->reloadCatalog();
    }

    private function resetForms(): void
    {
        $this->itemName = '';
        $this->itemCategory = 'Crockery';
        $this->itemUnit = 'piece';
        $this->minimumQuantity = '0';
        $this->createReason = '';
        $this->auditReason = '';
        $this->countRows = [];
        $this->stocktakeVariance = [];
        $this->varianceStocktakeId = '';
        $this->lastCountReference = null;
        $this->loanRows = [];
        $this->loanDirection = 'internal';
        $this->loanCounterpartHallId = '';
        $this->loanOutsideName = '';
        $this->loanOutsideContact = '';
        $this->loanOutsidePhone = '';
        $this->loanOutsideAddress = '';
        $this->loanReturnDate = '';
        $this->loanPurpose = '';
        $this->lastLoanReference = null;
        $this->incidentLoanId = '';
        $this->incidentRequestId = (string) Str::uuid();
        $this->incidentLines = [];
        $this->incidentHistory = [];
        $this->incidentItemId = '';
        $this->incidentKind = 'damage';
        $this->incidentQuantity = '';
        $this->incidentReason = '';
        $this->lastIncidentId = null;
        $this->incidentDecisionId = (string) Str::uuid();
        $this->incidentDecisionReportId = '';
        $this->incidentDecision = 'requires_more_evidence';
        $this->incidentDecisionReason = '';
        $this->incidentReviews = [];
        $this->lastIncidentDecisionId = null;
        $this->cleanLoanReceipts = [];
        $this->lastCleanLoanReceipt = null;
        $this->returnLoanId = '';
        $this->returnBatchId = (string) Str::uuid();
        $this->returnEligibleLines = [];
        $this->returnQuantities = [];
        $this->returnHandoverFile = null;
        $this->lastReturnBatchId = null;
        $this->returnSourceHistory = [];
        $this->returnAcceptBatchId = '';
        $this->returnAcceptanceId = (string) Str::uuid();
        $this->returnAcceptanceLines = [];
        $this->returnConditionGood = [];
        $this->returnConditionDamaged = [];
        $this->returnAcceptanceFile = null;
        $this->lastReturnAcceptanceId = null;

        $this->loanReceiptLoanId = '';
        $this->loanReceiptRequestId = (string) Str::uuid();
        $this->loanReceiptLines = [];
        $this->loanReceiptMapping = [];
        $this->loanReceiptFile = null;
        $this->lastLoanReceiptReference = null;
        $this->loanInlineRowKey = null;
        $this->loanInlineName = '';
        $this->loanInlineCategory = 'Crockery';
        $this->loanInlineUnit = 'piece';
        $this->loanInlineMinimumQuantity = '0';
        $this->loanInlineRequestId = (string) Str::uuid();
        $this->openingItemId = '';
        $this->openingStocktakeId = '';
        $this->openingQuantity = '';
        $this->openingReason = '';
        $this->lastOpeningReference = null;
        $this->openingHistory = [];
        $this->purchaseHistory = [];
        $this->purchaseItemId = '';
        $this->purchaseQuantity = '';
        $this->purchaseVendor = '';
        $this->purchaseVendorReference = '';
        $this->purchaseEvidence = '';
        $this->purchaseReason = '';
        $this->lastPurchaseReference = null;
        $this->lastPurchaseUploadReference = null;
        $this->lastPurchaseMovementReference = null;
        $this->purchaseUploadPurchaseId = '';
        $this->purchaseUploadKind = 'supplier_document';
        $this->purchaseUploadFile = null;
        $this->purchaseUploadId = (string) Str::uuid();
        $this->receivingPurchaseId = '';
        $this->receivingQuantity = '';
        $this->receivingEvidenceRef = '';
        $this->receivingCondition = '';
        $this->reviewReceiptId = '';
        $this->reviewReceiptNotes = '';
        $this->lastReceivingReference = null;
        $this->lastReceivingReviewReference = null;
        $this->receivingId = (string)Str::uuid();
        $this->receivingReviewId = (string)Str::uuid();
        $this->purchaseRequestId = (string) Str::uuid();
        $this->openingRequestId = (string) Str::uuid();
        $this->createRequestId = (string) Str::uuid();
        $this->countRequestId = (string) Str::uuid();
        $this->loanRequestId = (string) Str::uuid();
    }

    public function reloadStocktakeHistory(): void
    {
        $this->stocktakeHistory = [];
        $actor = $this->authorizeSelection();
        if (! config('inventory_controls.stocktakes_enabled')) {
            return;
        }
        $this->stocktakeHistory = app(\App\Services\HallInventoryStocktakeReview::class)
            ->recent($actor, $this->companyId, $this->hallId);
    }

    public function canApproveOpening(): bool
    {
        $actor = Filament::auth()->user();
        if (! config('inventory_controls.opening_stock_posting_enabled')
            || ! $actor instanceof User || $this->companyId === null
            || $this->hallId === null || ! static::canAccess()
            || ! array_key_exists($this->hallId,$this->hallOptions())) {
            return false;
        }
        $hall = Hall::query()->where('company_id',$this->companyId)
            ->where('id',$this->hallId)->where('is_active',true)->first();
        return $hall !== null &&
            app(TenantAccess::class)->canConfigureHall($actor,$hall);
    }

    public function submitOpeningRequest(): void
    {
        $actor = $this->authorizeSelection();
        abort_unless(config('inventory_controls.opening_stock_posting_enabled'),403);
        $this->validate([
            'openingItemId'=>'required|uuid',
            'openingStocktakeId'=>'required|uuid',
            'openingQuantity'=>['required','regex:/\\A[1-9][0-9]{0,8}\\z/D'],
            'openingReason'=>'required|string|min:5|max:500',
        ]);
        try {
            $this->lastOpeningReference=app(\App\Services\HallInventoryOpeningStockService::class)
                ->request($actor,$this->companyId,$this->hallId,
                    $this->openingItemId,$this->openingStocktakeId,
                    $this->openingRequestId,(int)$this->openingQuantity,
                    $this->openingReason);
        }catch(InvalidArgumentException|ConflictHttpException $e){
            throw ValidationException::withMessages([
                'openingItemId'=>$e->getMessage(),
            ]);
        }
        $this->openingRequestId=(string)Str::uuid();
        $this->openingItemId='';
        $this->openingStocktakeId='';
        $this->openingQuantity='';
        $this->openingReason='';
        $this->reloadOpeningHistory();
    }

    public function acceptOpeningRequest(string $requestId): void
    {
        $actor = $this->authorizeSelection();
        abort_unless($this->canApproveOpening() &&
            config('inventory_controls.opening_stock_posting_enabled') &&
            Str::isUuid($requestId),403);
        try {
            app(\App\Services\HallInventoryOpeningStockService::class)
                ->accept($actor,$this->companyId,$this->hallId,$requestId);
        }catch(InvalidArgumentException|ConflictHttpException $e){
            throw ValidationException::withMessages([
                'openingHistory'=>$e->getMessage(),
            ]);
        }
        $this->reloadOpeningHistory();
    }

    public function submitPurchaseRequest():void
    {
        $actor=$this->authorizeSelection();
        abort_unless(config('inventory_controls.purchase_receipts_enabled'),403);
        $this->validate([
            'purchaseItemId'=>'required|uuid',
            'purchaseQuantity'=>['required','regex:/\A[1-9][0-9]{0,8}\z/D'],
            'purchaseVendor'=>'required|string|min:2|max:200',
            'purchaseVendorReference'=>'required|string|min:2|max:160',
            'purchaseEvidence'=>'required|string|min:8|max:300',
            'purchaseReason'=>'required|string|min:5|max:500',
        ]);
        try {
            $this->lastPurchaseReference=app(\App\Services\HallInventoryPurchaseRequestService::class)
                ->request($actor,$this->companyId,$this->hallId,
                    $this->purchaseItemId,$this->purchaseRequestId,
                    (int)$this->purchaseQuantity,$this->purchaseVendor,
                    $this->purchaseVendorReference,$this->purchaseEvidence,
                    $this->purchaseReason);
        }catch(InvalidArgumentException|ConflictHttpException $e){
            throw ValidationException::withMessages(['purchaseItemId'=>$e->getMessage()]);
        }
        $this->purchaseRequestId=(string)Str::uuid();
        $this->purchaseItemId='';
        $this->purchaseQuantity='';
        $this->purchaseVendor='';
        $this->purchaseVendorReference='';
        $this->purchaseEvidence='';
        $this->purchaseReason='';
        $this->reloadPurchaseHistory();
    }


    public function recordPhysicalPurchaseReceiving():void
    {
        $actor=$this->authorizeSelection();
        abort_unless(config('inventory_controls.purchase_receipts_enabled'),403);
        $this->validate([
            'receivingPurchaseId'=>'required|uuid',
            'receivingQuantity'=>['required','regex:/\A[1-9][0-9]{0,8}\z/D'],
            'receivingEvidenceRef'=>'required|string|min:8|max:300',
            'receivingCondition'=>'required|string|min:5|max:500',
        ]);
        try {
            $this->lastReceivingReference=app(\App\Services\HallInventoryPurchaseReceivingService::class)
                ->record($actor,$this->companyId,$this->hallId,
                    $this->receivingPurchaseId,$this->receivingId,
                    (int)$this->receivingQuantity,$this->receivingEvidenceRef,
                    $this->receivingCondition);
        }catch(InvalidArgumentException|ConflictHttpException $e){
            throw ValidationException::withMessages(['receivingPurchaseId'=>$e->getMessage()]);
        }
        $this->receivingId=(string)Str::uuid();
        $this->receivingPurchaseId='';
        $this->receivingQuantity='';
        $this->receivingEvidenceRef='';
        $this->receivingCondition='';
        $this->reloadPurchaseHistory();
    }

    public function canReviewPurchaseReceiving():bool
    {
        $actor=Filament::auth()->user();
        if (!config('inventory_controls.purchase_receipts_enabled')
            || !$actor instanceof User || $this->companyId===null
            || $this->hallId===null || !static::canAccess()
            || !array_key_exists($this->hallId,$this->hallOptions())) return false;
        $hall=Hall::query()->where('company_id',$this->companyId)
            ->where('id',$this->hallId)->where('is_active',true)->first();
        return $hall!==null && app(TenantAccess::class)->canConfigureHall($actor,$hall);
    }

    public function independentlyReviewPurchaseReceiving():void
    {
        $actor=$this->authorizeSelection();
        abort_unless($this->canReviewPurchaseReceiving(),403);
        $this->validate([
            'reviewReceiptId'=>'required|uuid',
            'reviewReceiptNotes'=>'required|string|min:10|max:500',
        ]);
        try {
            $this->lastReceivingReviewReference=app(\App\Services\HallInventoryPurchaseReceivingReviewService::class)
                ->attest($actor,$this->companyId,$this->hallId,
                    $this->reviewReceiptId,$this->receivingReviewId,
                    $this->reviewReceiptNotes);
        }catch(InvalidArgumentException|ConflictHttpException $e){
            throw ValidationException::withMessages(['reviewReceiptId'=>$e->getMessage()]);
        }
        $this->receivingReviewId=(string)Str::uuid();
        $this->reviewReceiptId='';
        $this->reviewReceiptNotes='';
        $this->reloadPurchaseHistory();
    }


    public function uploadPurchaseEvidence():void
    {
        $actor=$this->authorizeSelection();
        abort_unless(config('inventory_controls.purchase_receipts_enabled') &&
            config('inventory_controls.purchase_evidence_upload_enabled'),403);
        $this->validate([
            'purchaseUploadPurchaseId'=>'required|uuid',
            'purchaseUploadKind'=>'required|in:supplier_document,receiving_photo',
            'purchaseUploadFile'=>'required|file|max:5120|mimetypes:application/pdf,image/jpeg,image/png',
        ]);
        try {
            $this->lastPurchaseUploadReference=app(\App\Services\HallInventoryPurchaseEvidenceFileService::class)
                ->attach($actor,$this->companyId,$this->hallId,$this->purchaseUploadPurchaseId,
                    $this->purchaseUploadId,$this->purchaseUploadKind,$this->purchaseUploadFile);
        }catch(InvalidArgumentException|ConflictHttpException $e){
            throw ValidationException::withMessages(['purchaseUploadFile'=>$e->getMessage()]);
        }
        $this->purchaseUploadId=(string)Str::uuid();
        $this->purchaseUploadPurchaseId='';
        $this->purchaseUploadFile=null;
        $this->reloadPurchaseHistory();
    }

    public function acceptPurchasedStock(string $purchaseId,int $expectedRevision):void
    {
        $actor=$this->authorizeSelection();
        abort_unless($this->canReviewPurchaseReceiving() &&
            config('inventory_controls.purchase_stock_posting_enabled') &&
            Str::isUuid($purchaseId),403);
        try {
            $this->lastPurchaseMovementReference=app(\App\Services\HallInventoryPurchaseStockAcceptanceService::class)
                ->accept($actor,$this->companyId,$this->hallId,$purchaseId,$expectedRevision);
        }catch(InvalidArgumentException|ConflictHttpException $e){
            throw ValidationException::withMessages(['purchaseHistory'=>$e->getMessage()]);
        }
        $this->reloadPurchaseHistory();
    }

    public function reloadPurchaseHistory():void
    {
        $this->purchaseHistory=[];
        $actor=$this->authorizeSelection();
        if(!config('inventory_controls.purchase_receipts_enabled'))return;
        $this->purchaseHistory=app(\App\Services\HallInventoryPurchaseReview::class)
            ->recent($actor,$this->companyId,$this->hallId);
    }

    public function reloadOpeningHistory(): void
    {
        $this->openingHistory = [];
        $actor = $this->authorizeSelection();
        if (! config('inventory_controls.opening_stock_posting_enabled')) {
            return;
        }
        $this->openingHistory = app(\App\Services\HallInventoryOpeningReview::class)
            ->recent($actor,$this->companyId,$this->hallId);
    }

    public function reloadLoanHistory(): void
    {
        $this->loanHistory = [];
        $actor = $this->authorizeSelection();
        if (! config('inventory_controls.loan_drafts_enabled')) {
            return;
        }
        $this->loanHistory = app(\App\Services\HallInventoryLoanDraftReview::class)
            ->recent($actor, $this->companyId, $this->hallId);
        $this->cleanLoanReceipts=[];
        if(config('inventory_controls.loan_clean_closure_enabled')) {
            $hall=Hall::query()->where('company_id',$this->companyId)->where('id',$this->hallId)->first();
            if($hall && app(TenantAccess::class)->canConfigureHall($actor,$hall)) {
                $closure=app(\App\Services\HallInventoryCleanLoanClosureService::class);
                foreach($this->loanHistory as $loan) {
                    if($loan['direction']==='internal' && $loan['lendingHallId']===$this->hallId) {
                        $entry=$closure->summaryForLoan($actor,$this->companyId,$loan['id']);
                        if($entry!==null)$this->cleanLoanReceipts[$loan['id']]=$entry;
                    }
                }
            }
        }
        $this->returnSourceHistory=[];
        if(config('inventory_controls.loan_returns_enabled') &&
            config('inventory_controls.loan_return_acceptance_enabled')) {
            $hall=Hall::query()->where('company_id',$this->companyId)
                ->where('id',$this->hallId)->where('is_active',true)->first();
            if($hall && app(TenantAccess::class)->canConfigureHall($actor,$hall))
                $this->returnSourceHistory=app(HallInventoryInternalLoanReturnReview::class)
                    ->sourceBatches($actor,$this->companyId,$this->hallId);
        }
    }

    public function viewStocktakeVariance(string $stocktakeId):void
    {
        $actor=$this->authorizeSelection();
        abort_unless(config('inventory_controls.stocktake_variance_enabled') &&
            config('inventory_controls.position_reports_enabled'),403);
        $this->stocktakeVariance=app(\App\Services\HallInventoryStocktakeVariancePreview::class)
            ->forStocktake($actor,$this->companyId,$this->hallId,$stocktakeId);
        $this->varianceStocktakeId=$stocktakeId;
        $this->varianceReviewRequestId=(string)Str::uuid();
        $this->varianceReviewReason='';
        $this->lastVarianceReviewReference=null;
    }

    public function canIndependentlyReviewSelectedVariance():bool
    {
        if(!config('inventory_controls.stocktake_variance_enabled') ||
            $this->varianceStocktakeId==='')return false;
        $actor=auth()->user();
        $hall=Hall::query()->where('company_id',$this->companyId)
            ->where('id',$this->hallId)->where('is_active',true)->first();
        return $actor instanceof \App\Models\User && $hall!==null &&
            app(TenantAccess::class)->canConfigureHall($actor,$hall);
    }

    public function acknowledgeStocktakeVariance():void
    {
        $actor=$this->authorizeSelection();
        abort_unless(config('inventory_controls.stocktake_variance_enabled') &&
            config('inventory_controls.position_reports_enabled'),403);
        $hall=Hall::query()->where('company_id',$this->companyId)
            ->where('id',$this->hallId)->where('is_active',true)->first();
        abort_unless($hall && app(TenantAccess::class)->canConfigureHall($actor,$hall),403);
        try {
            $this->lastVarianceReviewReference=app(
                \App\Services\HallInventoryVarianceReviewService::class
            )->acknowledge($actor,$this->companyId,$this->hallId,
                $this->varianceStocktakeId,$this->varianceReviewRequestId,
                $this->varianceReviewReason);
        }catch(InvalidArgumentException|ConflictHttpException $e) {
            throw ValidationException::withMessages(['varianceReviewReason'=>$e->getMessage()]);
        }
        $this->varianceReviewReason='';
        $this->stocktakeVariance=app(\App\Services\HallInventoryStocktakeVariancePreview::class)
            ->forStocktake($actor,$this->companyId,$this->hallId,$this->varianceStocktakeId);
    }

    public function reloadCatalog(): void
    {
        $this->stocktakeVariance = [];
        $this->varianceStocktakeId = '';
        $this->varianceReviewReason = '';
        $this->lastVarianceReviewReference = null;
        $this->varianceReviewRequestId = '';
        $this->catalog = [];
        $this->authorizeSelection();
        $this->catalog = DB::table('hall_inventory_items')
            ->where('company_id', $this->companyId)->where('hall_id', $this->hallId)
            ->where('is_active', true)->orderBy('name')->limit(100)
            ->get(['id', 'name', 'category', 'unit', 'minimum_quantity'])
            ->map(fn ($item) => [
                'id' => $item->id, 'name' => $item->name, 'category' => $item->category,
                'unit' => $item->unit, 'minimumQuantity' => (int) $item->minimum_quantity,
            ])->all();
        $this->positionReport = [];
        if(config('inventory_controls.position_reports_enabled'))
            $this->reloadPositionReport();
        $this->reloadStocktakeHistory();
        $this->reloadLoanHistory();
        $this->reloadOpeningHistory();
        $this->reloadPurchaseHistory();
    }

    public function reloadPositionReport():void
    {
        $actor=$this->authorizeSelection();
        abort_unless(config('inventory_controls.position_reports_enabled'),403);
        $this->positionReport=app(\App\Services\HallInventoryPositionReport::class)
            ->recent($actor,$this->companyId,$this->hallId);
    }

    public function downloadPositionReportCsv():\Symfony\Component\HttpFoundation\StreamedResponse
    {
        $actor=$this->authorizeSelection();
        abort_unless(config('inventory_controls.position_reports_enabled'),403);
        $csv=app(\App\Services\HallInventoryPositionReport::class)
            ->csv($actor,$this->companyId,$this->hallId);
        DB::table('audit_events')->insert([
            'id'=>(string)Str::uuid(),'company_id'=>$this->companyId,
            'actor_id'=>$actor->getKey(),
            'action'=>'hall_inventory.book_report_csv_exported',
            'entity_type'=>'hall_inventory_hall','entity_id'=>$this->hallId,
            'before_values'=>json_encode([],JSON_THROW_ON_ERROR),
            'after_values'=>json_encode(['format'=>'csv','reportOnly'=>true],JSON_THROW_ON_ERROR),
            'context'=>json_encode(['scope'=>'selected_hall','financialPosting'=>false],JSON_THROW_ON_ERROR),
        ]);
        return response()->streamDownload(
            static function()use($csv):void {echo $csv;},
            'hall-inventory-book-'.substr($this->hallId,0,8).'.csv',
            ['Content-Type'=>'text/csv; charset=UTF-8',
             'X-Content-Type-Options'=>'nosniff','Cache-Control'=>'private, no-store']
        );
    }

    public function createItem(): void
    {
        $actor = $this->authorizeSelection();
        $this->validate([
            'itemName' => 'required|string|min:1|max:160',
            'itemCategory' => 'required|string|max:80',
            'itemUnit' => 'required|in:piece,set,pair,kg,liter',
            'minimumQuantity' => ['required', 'regex:/\\A[0-9]{1,9}\\z/'],
            'createReason' => 'required|string|min:3|max:500',
        ]);
        try {
            app(HallInventoryCatalogService::class)->create(
                $actor, $this->companyId, $this->hallId, $this->createRequestId,
                $this->itemName, $this->itemCategory, $this->itemUnit,
                (int) $this->minimumQuantity, $this->createReason,
            );
        } catch (InvalidArgumentException|ConflictHttpException $e) {
            throw ValidationException::withMessages(['itemName' => $e->getMessage()]);
        }
        $this->itemName = '';
        $this->createReason = '';
        $this->minimumQuantity = '0';
        $this->createRequestId = (string) Str::uuid();
        $this->reloadCatalog();
    }

    public function addCountRow(): void
    {
        $this->authorizeSelection();
        abort_unless(config('inventory_controls.stocktakes_enabled'), 403);
        abort_unless(count($this->countRows) < 100, 403);
        $this->countRows[] = [
            'key' => (string) Str::uuid(), 'itemId' => '',
            'ownership' => 'owned', 'usableCount' => '', 'damagedCount' => '0',
        ];
    }

    public function removeCountRow(string $rowKey): void
    {
        $this->authorizeSelection();
        $this->countRows = array_values(array_filter(
            $this->countRows, fn ($row) => ($row['key'] ?? '') !== $rowKey));
    }

    public function openLoanInlineItem(string $rowKey): void
    {
        $this->authorizeSelection();
        abort_unless(config('inventory_controls.loan_drafts_enabled'), 403);
        abort_unless(collect($this->loanRows)->contains(fn ($row) =>
            ($row['key'] ?? null) === $rowKey), 403);
        $this->loanInlineRowKey = $rowKey;
    }

    public function saveLoanInlineItem(): void
    {
        $actor = $this->authorizeSelection();
        abort_unless(config('inventory_controls.loan_drafts_enabled'), 403);
        $rowIndex = null;
        foreach ($this->loanRows as $index => $row) {
            if (($row['key'] ?? null) === $this->loanInlineRowKey) {
                $rowIndex = $index;
                break;
            }
        }
        abort_unless($rowIndex !== null, 403);
        $this->validate([
            'loanInlineName' => 'required|string|min:1|max:160',
            'loanInlineCategory' => 'required|string|max:80',
            'loanInlineUnit' => 'required|in:piece,set,pair,kg,liter',
            'loanInlineMinimumQuantity' => ['required', 'regex:/\\A[0-9]{1,9}\\z/'],
        ]);
        try {
            $itemId = app(HallInventoryCatalogService::class)->create(
                $actor, $this->companyId, $this->hallId, $this->loanInlineRequestId,
                $this->loanInlineName, $this->loanInlineCategory, $this->loanInlineUnit,
                (int) $this->loanInlineMinimumQuantity,
                'Created from multi-item borrowing request',
            );
        } catch (InvalidArgumentException|ConflictHttpException $e) {
            throw ValidationException::withMessages(['loanInlineName' => $e->getMessage()]);
        }
        $this->loanRows[$rowIndex]['itemId'] = $itemId;
        $this->loanInlineRowKey = null;
        $this->loanInlineName = '';
        $this->loanInlineMinimumQuantity = '0';
        $this->loanInlineRequestId = (string) Str::uuid();
        $this->reloadCatalog();
    }

    public function addLoanRow(): void
    {
        $this->authorizeSelection();
        abort_unless(config('inventory_controls.loan_drafts_enabled'), 403);
        abort_unless(count($this->loanRows) < 100, 403);
        $this->loanRows[] = [
            'key' => (string) Str::uuid(), 'itemId' => '', 'quantity' => '',
        ];
    }

    public function removeLoanRow(string $rowKey): void
    {
        $this->authorizeSelection();
        abort_unless(config('inventory_controls.loan_drafts_enabled'), 403);
        $this->loanRows = array_values(array_filter(
            $this->loanRows, fn ($row) => ($row['key'] ?? '') !== $rowKey));
    }

    public function submitLoanDraft(): void
    {
        $actor = $this->authorizeSelection();
        abort_unless(config('inventory_controls.loan_drafts_enabled'), 403);
        if (count($this->loanRows) < 1 || count($this->loanRows) > 100) {
            throw ValidationException::withMessages(['loanRows' => 'Add 1 to 100 items before saving a loan request.']);
        }
        $lines = [];
        foreach ($this->loanRows as $row) {
            if (! is_string($row['quantity'] ?? null)
                || ! preg_match('/\\A[1-9][0-9]{0,8}\\z/D', $row['quantity'])) {
                throw ValidationException::withMessages(['loanRows' => 'Every loan row needs a positive whole-number quantity.']);
            }
            $lines[] = [
                'itemId' => $row['itemId'] ?? '',
                'quantity' => (int) $row['quantity'],
            ];
        }
        $lending = $this->loanDirection === 'external_in' ? null : $this->hallId;
        $borrowing = $this->loanDirection === 'internal' ? $this->loanCounterpartHallId
            : ($this->loanDirection === 'external_in' ? $this->hallId : null);
        try {
            $this->lastLoanReference = app(HallInventoryLoanDraftService::class)->create(
                $actor, $this->companyId, $this->loanRequestId, $this->loanDirection,
                $lending, $borrowing, $lines, $this->loanReturnDate, $this->loanPurpose,
                $this->loanDirection === 'internal' ? null : $this->loanOutsideName,
                $this->loanDirection === 'internal' ? null : $this->loanOutsideContact,
                $this->loanDirection === 'internal' ? null : $this->loanOutsidePhone,
                $this->loanDirection === 'internal' ? null : $this->loanOutsideAddress,
            );
        } catch (InvalidArgumentException|ConflictHttpException $e) {
            throw ValidationException::withMessages(['loanRows' => $e->getMessage()]);
        }
        $this->loanRows = [];
        $this->loanPurpose = '';
        $this->loanOutsideContact = '';
        $this->loanOutsidePhone = '';
        $this->loanOutsideAddress = '';
        $this->loanRequestId = (string) Str::uuid();
        $this->reloadLoanHistory();
    }

    public function startLoanReceipt(string $loanId): void
    {
        $actor=$this->authorizeSelection();
        abort_unless(config('inventory_controls.loan_drafts_enabled') &&
            config('inventory_controls.loan_dispatch_enabled') &&
            config('inventory_controls.loan_receipt_enabled'),403);
        $record=collect(app(\App\Services\HallInventoryLoanDraftReview::class)
            ->recent($actor,$this->companyId,$this->hallId))
            ->first(fn($loan):bool => $loan['id']===$loanId &&
                ($loan['viewRole']??'')==='receiving' &&
                ($loan['status']??'')==='source_dispatched_receipt_pending');
        abort_unless($record!==null,403);
        $this->loanReceiptLoanId=$loanId;
        $this->loanReceiptRequestId=(string)Str::uuid();
        $this->loanReceiptLines=$record['lines'];
        $this->loanReceiptMapping=[];
        $this->loanReceiptFile=null;
        $this->lastLoanReceiptReference=null;
    }

    public function submitLoanReceipt(): void
    {
        $actor=$this->authorizeSelection();
        abort_unless(config('inventory_controls.loan_drafts_enabled') &&
            config('inventory_controls.loan_dispatch_enabled') &&
            config('inventory_controls.loan_receipt_enabled'),403);
        $record=collect(app(\App\Services\HallInventoryLoanDraftReview::class)
            ->recent($actor,$this->companyId,$this->hallId))
            ->first(fn($loan):bool => $loan['id']===$this->loanReceiptLoanId &&
                ($loan['viewRole']??'')==='receiving' &&
                ($loan['status']??'')==='source_dispatched_receipt_pending');
        abort_unless($record!==null,403);
        $this->validate([
            'loanReceiptFile'=>'required|file|max:5120|mimes:jpg,jpeg,png,pdf',
            'loanReceiptMapping'=>'required|array',
        ]);
        $expected=[];
        $map=[];
        $lines=$record['lines'];
        if(count($lines)<1 || count($this->loanReceiptMapping)!==count($lines))
            throw ValidationException::withMessages([
                'loanReceiptMapping'=>'Map every dispatched item to a receiving-hall catalog item.'
            ]);
        foreach($lines as $line) {
            $sourceId=$line['itemId'];
            $destId=$this->loanReceiptMapping[$sourceId]??null;
            if(!is_string($destId)||!Str::isUuid($destId))
                throw ValidationException::withMessages([
                    'loanReceiptMapping'=>'Select an active receiving-hall item for every source item.'
                ]);
            $map[$sourceId]=$destId;
            $position=DB::table('hall_inventory_positions')
                ->where('company_id',$this->companyId)->where('hall_id',$this->hallId)
                ->where('item_id',$destId)->first(['revision']);
            $expected[$destId]=$position?(int)$position->revision:0;
        }
        try {
            $this->lastLoanReceiptReference=app(
                \App\Services\HallInventoryInternalLoanReceiptService::class
            )->receive($actor,$this->companyId,$this->loanReceiptLoanId,
                $this->loanReceiptRequestId,$map,$expected,$this->loanReceiptFile);
        }catch(InvalidArgumentException|ConflictHttpException $e) {
            throw ValidationException::withMessages(['loanReceiptMapping'=>$e->getMessage()]);
        }
        $this->loanReceiptLoanId='';
        $this->loanReceiptRequestId=(string)Str::uuid();
        $this->loanReceiptLines=[];
        $this->loanReceiptMapping=[];
        $this->loanReceiptFile=null;
        $this->reloadLoanHistory();
    }


    public function startInternalReturn(string $loanId):void
    {
        $actor=$this->authorizeSelection();
        abort_unless(config('inventory_controls.loan_returns_enabled'),403);
        $record=collect(app(\App\Services\HallInventoryLoanDraftReview::class)
            ->recent($actor,$this->companyId,$this->hallId))
            ->first(fn($row):bool=>$row['id']===$loanId &&
                ($row['viewRole']??'')==='receiving' &&
                in_array(($row['status']??''),[
                    'destination_received_return_pending',
                    'partial_return_in_transit_source_ack_pending',
                    'return_acknowledged_outstanding_reconciliation_required'
                ],true));
        abort_unless($record!==null,403);
        $lines=app(HallInventoryInternalLoanReturnReview::class)->destinationLines(
            $actor,$this->companyId,$this->hallId,$loanId);
        abort_unless(collect($lines)->contains(fn($row)=>$row['returnableQuantity']>0),403);
        $this->returnLoanId=$loanId;
        $this->returnBatchId=(string)Str::uuid();
        $this->returnEligibleLines=$lines;
        $this->returnQuantities=[];
        $this->returnHandoverFile=null;
        $this->lastReturnBatchId=null;
    }

    public function sendInternalReturn():void
    {
        $actor=$this->authorizeSelection();
        abort_unless(config('inventory_controls.loan_returns_enabled'),403);
        $this->validate([
            'returnHandoverFile'=>'required|file|max:5120|mimes:jpg,jpeg,png,pdf',
            'returnQuantities'=>'required|array'
        ]);
        $lines=app(HallInventoryInternalLoanReturnReview::class)->destinationLines(
            $actor,$this->companyId,$this->hallId,$this->returnLoanId);
        $bySource=collect($lines)->keyBy('sourceItemId');
        $quantities=[];$revisions=[];
        foreach($this->returnQuantities as $sourceId=>$raw) {
            if(!is_string($sourceId)||!$bySource->has($sourceId)||
                !is_string($raw)||!preg_match('/\A[1-9][0-9]{0,8}\z/D',$raw))
                throw ValidationException::withMessages([
                    'returnQuantities'=>'Select valid source items and positive whole quantities.'
                ]);
            $line=$bySource->get($sourceId);
            $qty=(int)$raw;
            if($qty>$line['returnableQuantity'])
                throw ValidationException::withMessages([
                    'returnQuantities'=>'Return quantity exceeds independently received usable stock.'
                ]);
            $quantities[$sourceId]=$qty;
            $revisions[$line['destinationItemId']]=$line['revision'];
        }
        if(count($quantities)<1)
            throw ValidationException::withMessages(['returnQuantities'=>'Select at least one item to return.']);
        try {
            $this->lastReturnBatchId=app(HallInventoryInternalLoanReturnService::class)
                ->send($actor,$this->companyId,$this->returnLoanId,
                    $this->returnBatchId,$quantities,$revisions,$this->returnHandoverFile);
        }catch(InvalidArgumentException|ConflictHttpException $e) {
            throw ValidationException::withMessages(['returnQuantities'=>$e->getMessage()]);
        }
        $this->returnLoanId='';
        $this->returnBatchId=(string)Str::uuid();
        $this->returnEligibleLines=[];
        $this->returnQuantities=[];
        $this->returnHandoverFile=null;
        $this->reloadLoanHistory();
    }

    public function startReturnAcceptance(string $batchId):void
    {
        $actor=$this->authorizeSelection();
        abort_unless(config('inventory_controls.loan_return_acceptance_enabled'),403);
        $row=collect(app(HallInventoryInternalLoanReturnReview::class)->sourceBatches(
            $actor,$this->companyId,$this->hallId))
            ->first(fn($b):bool=>$b['batchId']===$batchId && !$b['accepted']);
        abort_unless($row!==null,403);
        $this->returnAcceptBatchId=$batchId;
        $this->returnAcceptanceId=(string)Str::uuid();
        $this->returnAcceptanceLines=$row['lines'];
        $this->returnConditionGood=[];
        $this->returnConditionDamaged=[];
        $this->returnAcceptanceFile=null;
        $this->lastReturnAcceptanceId=null;
    }

    public function submitReturnAcceptance():void
    {
        $actor=$this->authorizeSelection();
        abort_unless(config('inventory_controls.loan_return_acceptance_enabled'),403);
        $batch=collect(app(HallInventoryInternalLoanReturnReview::class)->sourceBatches(
            $actor,$this->companyId,$this->hallId))
            ->first(fn($b):bool=>$b['batchId']===$this->returnAcceptBatchId && !$b['accepted']);
        abort_unless($batch!==null,403);
        $this->validate([
            'returnAcceptanceFile'=>'required|file|max:5120|mimes:jpg,jpeg,png,pdf',
            'returnConditionGood'=>'required|array',
            'returnConditionDamaged'=>'required|array',
        ]);
        $conditions=[];$sourceRevisions=[];$destRevisions=[];
        if(count($batch['lines'])<1)abort(403);
        foreach($batch['lines'] as $line) {
            $src=$line['sourceItemId'];$dest=$line['destinationItemId'];
            $good=$this->returnConditionGood[$src]??null;
            $damaged=$this->returnConditionDamaged[$src]??null;
            foreach([$good,$damaged] as $value) {
                if(!is_string($value)||!preg_match('/\A[0-9]{1,9}\z/D',$value))
                    throw ValidationException::withMessages([
                        'returnConditionGood'=>'Enter nonnegative whole physical counts for all return lines.'
                    ]);
            }
            if((int)$good+(int)$damaged>$line['sentQuantity'])
                throw ValidationException::withMessages([
                    'returnConditionGood'=>'Accepted counts exceed returned physical shipment.'
                ]);
            $conditions[$src]=['good'=>(int)$good,'damaged'=>(int)$damaged];
            $srcPos=DB::table('hall_inventory_positions')
                ->where('company_id',$this->companyId)->where('hall_id',$this->hallId)
                ->where('item_id',$src)->first(['revision']);
            $dstPos=DB::table('hall_inventory_positions')
                ->where('company_id',$this->companyId)
                ->where('hall_id',$batch['destinationHallId'])
                ->where('item_id',$dest)->first(['revision']);
            $sourceRevisions[$src]=$srcPos?(int)$srcPos->revision:-1;
            $destRevisions[$dest]=$dstPos?(int)$dstPos->revision:-1;
        }
        try {
            $this->lastReturnAcceptanceId=app(HallInventoryInternalLoanReturnAcceptanceService::class)
                ->accept($actor,$this->companyId,$this->returnAcceptBatchId,
                    $this->returnAcceptanceId,$conditions,
                    $sourceRevisions,$destRevisions,$this->returnAcceptanceFile);
        }catch(InvalidArgumentException|ConflictHttpException $e) {
            throw ValidationException::withMessages(['returnConditionGood'=>$e->getMessage()]);
        }
        $this->returnAcceptBatchId='';
        $this->returnAcceptanceId=(string)Str::uuid();
        $this->returnAcceptanceLines=[];
        $this->returnConditionGood=[];
        $this->returnConditionDamaged=[];
        $this->returnAcceptanceFile=null;
        $this->reloadLoanHistory();
    }

    public function startLoanIncident(string $loanId): void
    {
        $actor=$this->authorizeSelection();
        abort_unless(config('inventory_controls.loan_incidents_enabled'),403);
        $hall=Hall::query()->where('company_id',$this->companyId)
            ->where('id',$this->hallId)->where('is_active',true)->first();
        abort_unless($hall && app(TenantAccess::class)->canConfigureHall($actor,$hall),403);
        $loan=collect(app(\App\Services\HallInventoryLoanDraftReview::class)
            ->recent($actor,$this->companyId,$this->hallId))
            ->first(fn($row):bool=>$row['id']===$loanId &&
                $row['direction']==='internal' &&
                $row['lendingHallId']===$this->hallId &&
                $row['destinationReceiptConfirmed']);
        abort_unless($loan!==null,403);
        $this->incidentLoanId=$loanId;
        $this->incidentRequestId=(string) Str::uuid();
        $this->incidentLines=$loan['lines'];
        $this->incidentItemId='';
        $this->incidentKind='damage';
        $this->incidentQuantity='';
        $this->incidentReason='';
        $this->incidentHistory=app(\App\Services\HallInventoryLoanIncidentService::class)
            ->history($actor,$this->companyId,$this->hallId,$loanId);
        $this->incidentReviews=[];
        if(config('inventory_controls.loan_incident_reviews_enabled')) {
            $this->incidentReviews=app(\App\Services\HallInventoryLoanIncidentDecisionService::class)
                ->reviews($actor,$this->companyId,$this->hallId,$loanId);
        }
    }

    public function submitLoanIncident(): void
    {
        $actor=$this->authorizeSelection();
        abort_unless(config('inventory_controls.loan_incidents_enabled'),403);
        $this->validate([
            'incidentItemId'=>'required|uuid',
            'incidentKind'=>'required|in:damage,missing,dispute',
            'incidentQuantity'=>'required|integer|min:1|max:100000000',
            'incidentReason'=>'required|string|min:10|max:500',
        ]);
        if(!collect($this->incidentLines)->contains(
            fn($row):bool=>$row['itemId']===$this->incidentItemId))
            abort(403);
        try {
            $this->lastIncidentId=app(\App\Services\HallInventoryLoanIncidentService::class)
                ->report($actor,$this->companyId,$this->incidentLoanId,
                    $this->incidentRequestId,$this->incidentItemId,
                    $this->incidentKind,(int)$this->incidentQuantity,$this->incidentReason);
        }catch(InvalidArgumentException|ConflictHttpException $e) {
            throw ValidationException::withMessages(['incidentQuantity'=>$e->getMessage()]);
        }
        $this->incidentRequestId=(string)Str::uuid();
        $this->incidentReason='';
        $this->incidentQuantity='';
        $this->incidentHistory=app(\App\Services\HallInventoryLoanIncidentService::class)
            ->history($actor,$this->companyId,$this->hallId,$this->incidentLoanId);
        $this->reloadLoanHistory();
    }

    public function submitLoanIncidentReview():void
    {
        $actor=$this->authorizeSelection();
        abort_unless(config('inventory_controls.loan_incident_reviews_enabled') &&
            $this->incidentLoanId !== '',403);
        $this->validate([
            'incidentDecisionReportId'=>'required|uuid',
            'incidentDecision'=>'required|in:substantiated,unsubstantiated,requires_more_evidence',
            'incidentDecisionReason'=>'required|string|min:10|max:500',
        ]);
        $reports=app(\App\Services\HallInventoryLoanIncidentDecisionService::class)
            ->reviews($actor,$this->companyId,$this->hallId,$this->incidentLoanId);
        abort_unless(collect($reports)->contains(fn($row):bool=>
            $row['reportId']===$this->incidentDecisionReportId && $row['decision']===null),403);
        try {
            $this->lastIncidentDecisionId=app(\App\Services\HallInventoryLoanIncidentDecisionService::class)
                ->decide($actor,$this->companyId,$this->incidentDecisionReportId,
                    $this->incidentDecisionId,$this->incidentDecision,$this->incidentDecisionReason);
        } catch(InvalidArgumentException|ConflictHttpException $e) {
            throw ValidationException::withMessages(['incidentDecisionReason'=>$e->getMessage()]);
        }
        $this->incidentDecisionId=(string)Str::uuid();
        $this->incidentDecisionReportId='';
        $this->incidentDecisionReason='';
        $this->incidentReviews=app(\App\Services\HallInventoryLoanIncidentDecisionService::class)
            ->reviews($actor,$this->companyId,$this->hallId,$this->incidentLoanId);
    }

    public function closeCleanInternalLoan(string $loanId):void
    {
        $actor=$this->authorizeSelection();
        abort_unless(config('inventory_controls.loan_clean_closure_enabled'),403);
        $loan=collect(app(\App\Services\HallInventoryLoanDraftReview::class)
            ->recent($actor,$this->companyId,$this->hallId))
            ->first(fn($row):bool=>$row['id']===$loanId && $row['direction']==='internal'
                && $row['lendingHallId']===$this->hallId && $row['destinationReceiptConfirmed']);
        abort_unless($loan!==null,403);
        try {
            $summary=app(\App\Services\HallInventoryCleanLoanClosureService::class)
                ->close($actor,$this->companyId,$loanId,(string)Str::uuid());
        }catch(InvalidArgumentException|ConflictHttpException $e) {
            throw ValidationException::withMessages(['cleanLoanClosure'=>$e->getMessage()]);
        }
        $this->lastCleanLoanReceipt=(string)$summary['receiptNumber'];
        $this->reloadLoanHistory();
    }

    public function downloadCleanLoanReceipt(string $loanId):\Symfony\Component\HttpFoundation\StreamedResponse
    {
        $actor=$this->authorizeSelection();
        abort_unless(DB::table('hall_inventory_loan_drafts')
            ->where('company_id',$this->companyId)->where('id',$loanId)
            ->where('lending_hall_id',$this->hallId)->where('direction','internal')
            ->exists(),403);
        $bytes=app(\App\Services\HallInventoryCleanLoanClosureService::class)
            ->receiptPdf($actor,$this->companyId,$loanId);
        // Audit only after authorization and the pinned private PDF hash pass.
        // An unsuccessful/tampered read must not be recorded as a request.
        DB::table('audit_events')->insert([
            'id'=>(string)Str::uuid(),'company_id'=>$this->companyId,
            'actor_id'=>$actor->getKey(),
            'action'=>'hall_inventory.clean_loan_receipt_download_requested',
            'entity_type'=>'hall_inventory_loan_draft','entity_id'=>$loanId,
            'before_values'=>json_encode([],JSON_THROW_ON_ERROR),
            'after_values'=>json_encode(['read'=>'verified_pdf','stockMoved'=>false],JSON_THROW_ON_ERROR),
            'context'=>json_encode(['hallId'=>$this->hallId,'receiptOnly'=>true],JSON_THROW_ON_ERROR),
        ]);
        return response()->streamDownload(
            static function()use($bytes):void {echo $bytes;},
            'venue-inventory-clean-loan-'.substr($loanId,0,8).'.pdf',
            ['Content-Type'=>'application/pdf','X-Content-Type-Options'=>'nosniff',
             'Cache-Control'=>'private, no-store']
        );
    }

    public function submitCount(): void
    {
        $actor = $this->authorizeSelection();
        abort_unless(config('inventory_controls.stocktakes_enabled'), 403);
        if (count($this->countRows) === 0 || count($this->countRows) > 100) {
            throw ValidationException::withMessages(['countRows' => 'Add between 1 and 100 item rows.']);
        }
        $lines = [];
        foreach ($this->countRows as $index => $row) {
            foreach (['usableCount', 'damagedCount'] as $field) {
                if (! is_string($row[$field] ?? null)
                    || ! preg_match('/\\A[0-9]{1,9}\\z/D', $row[$field])) {
                    throw ValidationException::withMessages([
                        'countRows' => 'Enter a nonnegative whole number for each item count.',
                    ]);
                }
            }
            $lines[] = [
                'itemId' => $row['itemId'] ?? '',
                'ownership' => $row['ownership'] ?? '',
                'usableCount' => (int) $row['usableCount'],
                'damagedCount' => (int) $row['damagedCount'],
            ];
        }
        try {
            $this->lastCountReference = app(HallInventoryStocktakeService::class)->submit(
                $actor, $this->companyId, $this->hallId, $this->countRequestId,
                $this->auditKind, $lines, $this->auditReason);
        } catch (InvalidArgumentException|ConflictHttpException $e) {
            throw ValidationException::withMessages(['countRows' => $e->getMessage()]);
        }
        $this->countRows = [];
        $this->auditReason = '';
        $this->countRequestId = (string) Str::uuid();
        $this->reloadStocktakeHistory();
    }
}
