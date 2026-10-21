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
    public string $varianceAdjudicationReason = '';
    #[Locked]
    public ?string $lastVarianceReclassificationReference = null;
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
    public string $externalIntakeLoanId = '';
    #[Locked]
    public string $externalIntakeRequestId = '';
    public $externalIntakeFile = null;
    public array $externalIntakeCounts = [];
    #[Locked]
    public ?string $lastExternalIntakeReference = null;

    #[Locked]
    public string $externalOutLoanId = '';
    #[Locked]
    public string $externalOutRequestId = '';
    public array $externalOutCounts = [];
    public $externalOutFile = null;
    #[Locked]
    public ?string $lastExternalOutReference = null;
    #[Locked]
    public array $externalOutCleanReceipts = [];
    #[Locked]
    public string $externalOutCleanRequestId = '';
    #[Locked]
    public ?array $lastExternalOutCleanReceipt = null;
    #[Locked]
    public array $externalOutReturnHistory = [];
    #[Locked]
    public string $externalOutReturnLoanId = '';
    #[Locked]
    public string $externalOutReturnRequestId = '';
    public array $externalOutReturnQuantities = [];
    public $externalOutReturnFile = null;
    #[Locked]
    public string $externalOutReviewReceiptId = '';
    #[Locked]
    public string $externalOutReviewRequestId = '';
    public array $externalOutReviewGood = [];
    public array $externalOutReviewDamaged = [];
    public array $externalOutReviewUnresolved = [];
    #[Locked]
    public ?string $lastExternalOutReturnReference = null;

    #[Locked]
    public array $externalCleanReceipts = [];
    #[Locked]
    public string $externalCleanClosureRequestId = '';
    #[Locked]
    public ?array $lastExternalCleanReceipt = null;

    #[Locked]
    public array $externalReturnHistory = [];
    #[Locked]
    public string $externalReturnLoanId = '';
    #[Locked]
    public string $externalReturnBatchId = '';
    public array $externalReturnQuantities = [];
    public $externalReturnFile = null;
    #[Locked]
    public string $externalConfirmBatchId = '';
    #[Locked]
    public string $externalConfirmRequestId = '';
    public array $externalConfirmCounts = [];
    public $externalConfirmFile = null;
    #[Locked]
    public ?string $lastExternalReturnReference = null;

    #[Locked]
    public string $loanDispatchLoanId = '';
    #[Locked]
    public string $loanDispatchRequestId = '';
    #[Locked]
    public array $loanDispatchLines = [];
    public $loanDispatchFile = null;
    #[Locked]
    public ?string $lastLoanDispatchReference = null;

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
    #[Locked]
    public array $internalAbnormalCustody = [];
    #[Locked]
    public array $internalDamageWriteoffs = [];
    #[Locked]
    public array $internalDamageEligible = [];
    #[Locked]
    public string $internalDamageProposalId = '';
    #[Locked]
    public string $internalDamageDecisionId = '';
    public string $internalDamageIncidentId = '';
    public string $internalDamageQuantity = '';
    public string $internalDamageReason = '';
    public string $internalDamageReviewProposalId = '';
    public string $internalDamageReviewDecision = 'rejected';
    public string $internalDamageReviewReason = '';
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

    // Provisional financial assessments only. Never represent payment or receivable posting.
    #[Locked]
    public string $financialClaimLoanId = '';
    #[Locked]
    public array $financialClaimHistory = [];
    #[Locked]
    public array $financialClaimEligibleReports = [];
    #[Locked]
    public string $financialClaimRequestId = '';
    public string $financialClaimReportId = '';
    public string $financialClaimCurrency = 'USD';
    public string $financialClaimAmountMinor = '';
    public string $financialClaimReason = '';
    #[Locked]
    public string $financialClaimDecisionId = '';
    public string $financialClaimDecisionClaimId = '';
    public string $financialClaimDecision = 'disputed';
    public string $financialClaimDecisionReason = '';
    #[Locked]
    public ?string $lastFinancialClaimReference = null;
    #[Locked]
    public array $financialPartnerHistory = [];
    #[Locked]
    public string $financialPartnerRequestId = '';
    public string $financialPartnerClaimId = '';
    public string $financialPartnerPosition = 'document_says_disputes';
    public string $financialPartnerCounterofferMinor = '';
    public $financialPartnerFile = null;
    #[Locked]
    public string $financialPartnerReviewId = '';
    public string $financialPartnerReviewEvidenceId = '';
    public string $financialPartnerAssessment = 'insufficient_partner_proof';
    public string $financialPartnerReviewReason = '';
    #[Locked]
    public ?string $lastFinancialPartnerReference = null;

    #[Locked]
    public array $financialObligationHistory = [];
    #[Locked]
    public string $financialObligationRequestId = '';
    public string $financialObligationEvidenceId = '';
    public string $financialObligationAmountMinor = '';
    public string $financialObligationIdentityMethod = 'verified_callback';
    public string $financialObligationIdentityReference = '';
    public string $financialObligationReason = '';
    #[Locked]
    public string $financialObligationDecisionId = '';
    public string $financialObligationDecisionProposalId = '';
    public string $financialObligationDecision = 'disputed';
    public string $financialObligationDecisionReason = '';
    #[Locked]
    public ?string $lastFinancialObligationReference = null;

    #[Locked]
    public array $financialRecoveryHistory = [];
    #[Locked]
    public array $financialRecoveryBalances = [];
    #[Locked]
    public array $financialWaiverHistory = [];
    #[Locked]
    public string $financialWaiverProposalId = '';
    #[Locked]
    public string $financialWaiverDecisionId = '';
    public string $financialWaiverObligationId = '';
    public string $financialWaiverAmountMinor = '';
    public string $financialWaiverReason = '';
    public string $financialWaiverDecisionProposalId = '';
    public string $financialWaiverDecision = 'rejected';
    public string $financialWaiverDecisionReason = '';
    #[Locked]
    public array $noncleanReconciliation = [];
    #[Locked]
    public array $noncleanWriteoffHistory = [];
    #[Locked]
    public array $noncleanWriteoffEligible = [];
    #[Locked]
    public string $noncleanWriteoffProposalId = '';
    #[Locked]
    public string $noncleanWriteoffDecisionId = '';
    public string $noncleanWriteoffIncidentId = '';
    public string $noncleanWriteoffQuantity = '';
    public string $noncleanWriteoffReason = '';
    public string $noncleanWriteoffDecisionProposalId = '';
    public string $noncleanWriteoffDecision = 'rejected';
    public string $noncleanWriteoffDecisionReason = '';
    #[Locked]
    public string $financialRecoveryRequestId = '';
    public string $financialRecoveryObligationId = '';
    public string $financialRecoveryAmountMinor = '';
    public string $financialRecoveryMethod = 'bank';
    public string $financialRecoveryBankReference = '';
    public $financialRecoveryFile = null;
    #[Locked]
    public string $financialRecoveryReviewId = '';
    public string $financialRecoveryReceiptId = '';
    public string $financialRecoveryDecision = 'rejected';
    public string $financialRecoveryVerificationMethod = 'verified_bank_statement';
    public string $financialRecoveryVerificationReference = '';
    public string $financialRecoveryReviewReason = '';
    #[Locked]
    public string $financialRecoveryReversalId = '';
    public string $financialRecoveryReversalReceiptId = '';
    public string $financialRecoveryReversalReason = '';
    #[Locked]
    public ?string $lastFinancialRecoveryReference = null;

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
        $this->varianceAdjudicationReason = '';
        $this->lastVarianceReclassificationReference = null;
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
        $this->internalDamageWriteoffs=[];
        $this->internalDamageEligible=[];
        $this->incidentRequestId = (string) Str::uuid();
        $this->incidentLines = [];
        $this->incidentHistory = [];
        $this->internalAbnormalCustody = [];
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
        $this->financialClaimLoanId = '';
        $this->financialRecoveryHistory = [];
        $this->financialRecoveryBalances = [];
        $this->noncleanReconciliation = [];
        $this->financialRecoveryRequestId = (string)Str::uuid();
        $this->financialRecoveryObligationId = '';
        $this->financialRecoveryAmountMinor = '';
        $this->financialRecoveryMethod = 'bank';
        $this->financialRecoveryBankReference = '';
        $this->financialRecoveryFile = null;
        $this->financialRecoveryReviewId = (string)Str::uuid();
        $this->financialRecoveryReceiptId = '';
        $this->financialRecoveryDecision = 'rejected';
        $this->financialRecoveryVerificationMethod = 'verified_bank_statement';
        $this->financialRecoveryVerificationReference = '';
        $this->financialRecoveryReviewReason = '';
        $this->financialRecoveryReversalId = (string)Str::uuid();
        $this->financialRecoveryReversalReceiptId = '';
        $this->financialRecoveryReversalReason = '';
        $this->lastFinancialRecoveryReference = null;
        $this->financialObligationHistory = [];
        $this->financialObligationRequestId = (string)Str::uuid();
        $this->financialObligationEvidenceId = '';
        $this->financialObligationAmountMinor = '';
        $this->financialObligationIdentityMethod = 'verified_callback';
        $this->financialObligationIdentityReference = '';
        $this->financialObligationReason = '';
        $this->financialObligationDecisionId = (string)Str::uuid();
        $this->financialObligationDecisionProposalId = '';
        $this->financialObligationDecision = 'disputed';
        $this->financialObligationDecisionReason = '';
        $this->lastFinancialObligationReference = null;
        $this->financialPartnerHistory = [];
        $this->financialPartnerRequestId = (string)Str::uuid();
        $this->financialPartnerClaimId = '';
        $this->financialPartnerPosition = 'document_says_disputes';
        $this->financialPartnerCounterofferMinor = '';
        $this->financialPartnerFile = null;
        $this->financialPartnerReviewId = (string)Str::uuid();
        $this->financialPartnerReviewEvidenceId = '';
        $this->financialPartnerAssessment = 'insufficient_partner_proof';
        $this->financialPartnerReviewReason = '';
        $this->lastFinancialPartnerReference = null;
        $this->financialClaimHistory = [];
        $this->financialClaimEligibleReports = [];
        $this->financialClaimRequestId = (string)Str::uuid();
        $this->financialClaimReportId = '';
        $this->financialClaimCurrency = 'USD';
        $this->financialClaimAmountMinor = '';
        $this->financialClaimReason = '';
        $this->financialClaimDecisionId = (string)Str::uuid();
        $this->financialClaimDecisionClaimId = '';
        $this->financialClaimDecision = 'disputed';
        $this->financialClaimDecisionReason = '';
        $this->lastFinancialClaimReference = null;
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

        $this->externalOutLoanId = '';
        $this->externalOutRequestId = (string)Str::uuid();
        $this->externalOutCounts = [];
        $this->externalOutFile = null;
        $this->lastExternalOutReference = null;
        $this->externalOutCleanReceipts = [];
        $this->externalOutCleanRequestId = (string)Str::uuid();
        $this->lastExternalOutCleanReceipt = null;
        $this->externalOutReturnHistory = [];
        $this->externalOutReturnLoanId = '';
        $this->externalOutReturnRequestId = (string)Str::uuid();
        $this->externalOutReturnQuantities = [];
        $this->externalOutReturnFile = null;
        $this->externalOutReviewReceiptId = '';
        $this->externalOutReviewRequestId = (string)Str::uuid();
        $this->externalOutReviewGood = [];
        $this->externalOutReviewDamaged = [];
        $this->externalOutReviewUnresolved = [];
        $this->lastExternalOutReturnReference = null;
        $this->externalCleanReceipts = [];
        $this->externalCleanClosureRequestId = (string)Str::uuid();
        $this->lastExternalCleanReceipt = null;
        $this->externalReturnHistory = [];
        $this->externalReturnLoanId = '';
        $this->externalReturnBatchId = (string)Str::uuid();
        $this->externalReturnQuantities = [];
        $this->externalReturnFile = null;
        $this->externalConfirmBatchId = '';
        $this->externalConfirmRequestId = (string)Str::uuid();
        $this->externalConfirmCounts = [];
        $this->externalConfirmFile = null;
        $this->lastExternalReturnReference = null;
        $this->externalIntakeLoanId = '';
        $this->externalIntakeRequestId = (string)Str::uuid();
        $this->externalIntakeFile = null;
        $this->externalIntakeCounts = [];
        $this->lastExternalIntakeReference = null;
        $this->loanDispatchLoanId = '';
        $this->loanDispatchRequestId = (string) Str::uuid();
        $this->loanDispatchLines = [];
        $this->loanDispatchFile = null;
        $this->lastLoanDispatchReference = null;

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
        $this->externalOutCleanReceipts=[];
        if(config('inventory_controls.external_out_clean_closure_enabled')) {
            $selectedHall=Hall::query()->where('company_id',$this->companyId)
                ->where('id',$this->hallId)->where('is_active',true)->first();
            if($selectedHall && app(TenantAccess::class)->canConfigureHall($actor,$selectedHall)){
                $closureService=app(\App\Services\HallInventoryExternalOutCleanClosureService::class);
                foreach($this->loanHistory as $loan){
                    if($loan['direction']==='external_out' &&
                        $loan['lendingHallId']===$this->hallId){
                        $row=$closureService->summaryForLoan($actor,$this->companyId,$loan['id']);
                        if($row!==null)$this->externalOutCleanReceipts[$loan['id']]=$row;
                    }
                }
            }
        }
        $this->externalOutReturnHistory = [];
        if(config('inventory_controls.external_out_return_enabled') &&
            config('inventory_controls.external_out_dispatch_enabled'))
            $this->externalOutReturnHistory=app(\App\Services\HallInventoryExternalOutReturnService::class)
                ->recent($actor,$this->companyId,$this->hallId);
        $this->externalReturnHistory = [];
        if(config('inventory_controls.external_borrowed_return_enabled') &&
            config('inventory_controls.external_borrowed_intake_enabled'))
            $this->externalReturnHistory = app(\App\Services\HallInventoryExternalBorrowedReturnService::class)
                ->recent($actor,$this->companyId,$this->hallId);
        $this->externalCleanReceipts=[];
        if(config('inventory_controls.external_borrowed_clean_closure_enabled')) {
            $hall=Hall::query()->where('company_id',$this->companyId)
                ->where('id',$this->hallId)->where('is_active',true)->first();
            if($hall && app(TenantAccess::class)->canConfigureHall($actor,$hall)) {
                $service=app(\App\Services\HallInventoryExternalCleanLoanClosureService::class);
                foreach($this->loanHistory as $loan) {
                    if($loan['direction']==='external_in' && $loan['borrowingHallId']===$this->hallId) {
                        $info=$service->summaryForLoan($actor,$this->companyId,$loan['id']);
                        if($info!==null)$this->externalCleanReceipts[$loan['id']]=$info;
                    }
                }
            }
        }
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
        $this->varianceAdjudicationReason='';
        $this->lastVarianceReclassificationReference=null;
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


    public function reclassifyStocktakeDamage(string $lineId):void
    {
        $actor=$this->authorizeSelection();
        abort_unless(config('inventory_controls.variance_reclassification_enabled') &&
            config('inventory_controls.stocktake_variance_enabled') &&
            config('inventory_controls.position_reports_enabled'),403);
        try {
            $this->lastVarianceReclassificationReference=app(
                \App\Services\HallInventoryVarianceReclassificationService::class
            )->reconcile($actor,$this->companyId,$this->hallId,$this->varianceStocktakeId,
                $lineId,$this->varianceAdjudicationReason);
        }catch(InvalidArgumentException|ConflictHttpException $e) {
            throw ValidationException::withMessages(['varianceAdjudicationReason'=>$e->getMessage()]);
        }
        $this->varianceAdjudicationReason='';
        $this->stocktakeVariance=app(\App\Services\HallInventoryStocktakeVariancePreview::class)
            ->forStocktake($actor,$this->companyId,$this->hallId,$this->varianceStocktakeId);
    }

    public function reloadCatalog(): void
    {
        $this->stocktakeVariance = [];
        $this->varianceStocktakeId = '';
        $this->varianceReviewReason = '';
        $this->varianceAdjudicationReason = '';
        $this->lastVarianceReclassificationReference = null;
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

    /**
     * Independently witnessed source handover. A saved draft never moves stock.
     * This UI remains OFF until the existing source dispatch release gate.
     */
    public function canManageSourceInventoryDispatch():bool
    {
        if(!config('inventory_controls.loan_dispatch_enabled'))return false;
        $actor=auth()->user();
        $hall=Hall::query()->where('company_id',$this->companyId)
            ->where('id',$this->hallId)->where('is_active',true)->first();
        return $actor instanceof User && $hall!==null &&
            app(TenantAccess::class)->canConfigureHall($actor,$hall);
    }

    public function canIndependentlyAcceptExternalBorrowing():bool
    {
        if(!config('inventory_controls.external_borrowed_intake_enabled'))return false;
        $actor=auth()->user();
        $hall=Hall::query()->where('company_id',$this->companyId)
            ->where('id',$this->hallId)->where('is_active',true)->first();
        return $actor instanceof User && $hall!==null &&
            app(TenantAccess::class)->canConfigureHall($actor,$hall);
    }


    public function canApproveExternalOutDispatch():bool
    {
        if(!config('inventory_controls.external_out_dispatch_enabled'))return false;
        $actor=auth()->user();
        $hall=Hall::query()->where('company_id',$this->companyId)
            ->where('id',$this->hallId)->where('is_active',true)->first();
        return $actor instanceof User && $hall!==null &&
            app(TenantAccess::class)->canConfigureHall($actor,$hall);
    }

    public function startExternalOutHandover(string $loanId):void
    {
        $actor=$this->authorizeSelection();
        abort_unless(config('inventory_controls.external_out_dispatch_enabled'),403);
        $loan=collect(app(\App\Services\HallInventoryLoanDraftReview::class)
            ->recent($actor,$this->companyId,$this->hallId))
            ->first(fn($l):bool=>$l['id']===$loanId &&
                $l['direction']==='external_out' &&
                $l['lendingHallId']===$this->hallId &&
                !$l['externalOutHandoverRecorded'] &&
                $l['createdBy']!==(int)$actor->getKey());
        abort_unless($loan!==null,403);
        $this->externalOutLoanId=$loanId;
        $this->externalOutRequestId=(string)Str::uuid();
        $this->externalOutCounts=array_fill_keys(
            array_map(static fn(array $l):string=>$l['itemId'],$loan['lines']),'');
        $this->externalOutFile=null;
        $this->lastExternalOutReference=null;
    }

    public function submitExternalOutHandover():void
    {
        $actor=$this->authorizeSelection();
        abort_unless(config('inventory_controls.external_out_dispatch_enabled'),403);
        $this->validate(['externalOutFile'=>'required|file|max:5120|mimes:jpg,jpeg,png,pdf']);
        $loan=collect(app(\App\Services\HallInventoryLoanDraftReview::class)
            ->recent($actor,$this->companyId,$this->hallId))
            ->first(fn($l):bool=>$l['id']===$this->externalOutLoanId &&
                $l['direction']==='external_out' &&
                $l['lendingHallId']===$this->hallId &&
                !$l['externalOutHandoverRecorded']);
        abort_unless($loan!==null,403);
        $counts=[];
        foreach($loan['lines'] as $line) {
            $raw=$this->externalOutCounts[$line['itemId']]??null;
            if(!is_string($raw)||!preg_match('/^(0|[1-9][0-9]*)$/',$raw)||
                (float)$raw>100000000)
                throw ValidationException::withMessages([
                    'externalOutCounts'=>'Enter each actual witnessed physical handover quantity, including zero.']);
            $counts[$line['itemId']]=(int)$raw;
        }
        try {
            $this->lastExternalOutReference=app(
                \App\Services\HallInventoryExternalOutDispatchService::class
            )->record($actor,$this->companyId,$this->hallId,
                $this->externalOutLoanId,$this->externalOutRequestId,
                $this->externalOutFile,$counts);
        }catch(InvalidArgumentException|ConflictHttpException $e){
            throw ValidationException::withMessages(['externalOutFile'=>$e->getMessage()]);
        }
        $this->externalOutLoanId='';
        $this->externalOutRequestId=(string)Str::uuid();
        $this->externalOutFile=null;
        $this->externalOutCounts=[];
        $this->reloadLoanHistory();
    }

    public function approveExternalOutDispatch(string $loanId):void
    {
        $actor=$this->authorizeSelection();
        abort_unless($this->canApproveExternalOutDispatch(),403);
        $loan=collect(app(\App\Services\HallInventoryLoanDraftReview::class)
            ->recent($actor,$this->companyId,$this->hallId))
            ->first(fn($l):bool=>$l['id']===$loanId &&
                $l['direction']==='external_out' &&
                $l['lendingHallId']===$this->hallId &&
                $l['externalOutHandoverRecorded'] &&
                !$l['externalOutApproved']);
        abort_unless($loan!==null,403);
        try {
            $this->lastExternalOutReference=app(
                \App\Services\HallInventoryExternalOutDispatchService::class
            )->approve($actor,$this->companyId,$this->hallId,$loanId,(string)Str::uuid());
        }catch(InvalidArgumentException|ConflictHttpException $e){
            throw ValidationException::withMessages(['externalOutFile'=>$e->getMessage()]);
        }
        $this->reloadLoanHistory();
    }


    public function startExternalOutReturn(string $loanId):void
    {
        $actor=$this->authorizeSelection();
        abort_unless(config('inventory_controls.external_out_return_enabled') &&
            config('inventory_controls.external_out_dispatch_enabled'),403);
        $loan=collect(app(\App\Services\HallInventoryLoanDraftReview::class)
            ->recent($actor,$this->companyId,$this->hallId))
            ->first(fn($l):bool=>$l['id']===$loanId &&
                $l['direction']==='external_out' &&
                $l['lendingHallId']===$this->hallId &&
                $l['externalOutApproved'] &&
                $l['createdBy']!==(int)$actor->getKey());
        abort_unless($loan!==null && !$this->canApproveExternalOutDispatch(),403);
        $this->externalOutReturnLoanId=$loanId;
        $this->externalOutReturnRequestId=(string)Str::uuid();
        $this->externalOutReturnQuantities=[];
        $this->externalOutReturnFile=null;
        $this->lastExternalOutReturnReference=null;
    }

    public function submitExternalOutReturn():void
    {
        $actor=$this->authorizeSelection();
        abort_unless(config('inventory_controls.external_out_return_enabled') &&
            config('inventory_controls.external_out_dispatch_enabled'),403);
        $this->validate(['externalOutReturnFile'=>'required|file|max:5120|mimes:jpg,jpeg,png,pdf']);
        $loan=collect(app(\App\Services\HallInventoryLoanDraftReview::class)
            ->recent($actor,$this->companyId,$this->hallId))
            ->first(fn($l):bool=>$l['id']===$this->externalOutReturnLoanId &&
                $l['direction']==='external_out' &&
                $l['lendingHallId']===$this->hallId && $l['externalOutApproved']);
        abort_unless($loan!==null && !$this->canApproveExternalOutDispatch(),403);
        $qty=[];
        foreach($loan['lines'] as $line){
            $raw=$this->externalOutReturnQuantities[$line['itemId']]??'';
            if($raw==='')continue;
            if(!is_string($raw)||!preg_match('/^[1-9][0-9]*$/',$raw)||
                (float)$raw>100000000)
                throw ValidationException::withMessages([
                    'externalOutReturnQuantities'=>'Enter positive whole physically returned quantities or leave blank.']);
            $qty[$line['itemId']]=(int)$raw;
        }
        if(!$qty)throw ValidationException::withMessages([
            'externalOutReturnQuantities'=>'Select at least one physically returned outside-loan item.']);
        try {
            $this->lastExternalOutReturnReference=app(
                \App\Services\HallInventoryExternalOutReturnService::class
            )->receive($actor,$this->companyId,$this->hallId,
                $this->externalOutReturnLoanId,$this->externalOutReturnRequestId,
                $qty,$this->externalOutReturnFile);
        }catch(InvalidArgumentException|ConflictHttpException $e){
            throw ValidationException::withMessages(['externalOutReturnFile'=>$e->getMessage()]);
        }
        $this->externalOutReturnLoanId='';
        $this->externalOutReturnRequestId=(string)Str::uuid();
        $this->externalOutReturnQuantities=[];
        $this->externalOutReturnFile=null;
        $this->reloadLoanHistory();
    }

    public function startExternalOutReturnReview(string $receiptId):void
    {
        $this->authorizeSelection();
        abort_unless(config('inventory_controls.external_out_return_enabled') &&
            $this->canApproveExternalOutDispatch(),403);
        $receipt=collect($this->externalOutReturnHistory)
            ->first(fn($x):bool=>$x['receiptId']===$receiptId &&
                !$x['reviewed'] && $x['receivedBy']!==(int)auth()->id());
        abort_unless($receipt!==null,403);
        $this->externalOutReviewReceiptId=$receiptId;
        $this->externalOutReviewRequestId=(string)Str::uuid();
        $this->externalOutReviewGood=[];
        $this->externalOutReviewDamaged=[];
        $this->externalOutReviewUnresolved=[];
        foreach($receipt['lines'] as $line) {
            $this->externalOutReviewGood[$line['itemId']]='';
            $this->externalOutReviewDamaged[$line['itemId']]='';
            $this->externalOutReviewUnresolved[$line['itemId']]='';
        }
    }

    public function submitExternalOutReturnReview():void
    {
        $actor=$this->authorizeSelection();
        abort_unless(config('inventory_controls.external_out_return_enabled') &&
            $this->canApproveExternalOutDispatch(),403);
        $receipt=collect(app(\App\Services\HallInventoryExternalOutReturnService::class)
            ->recent($actor,$this->companyId,$this->hallId))
            ->first(fn($x):bool=>$x['receiptId']===$this->externalOutReviewReceiptId &&
                !$x['reviewed'] && $x['receivedBy']!==(int)$actor->getKey());
        abort_unless($receipt!==null,403);
        $counts=[];
        foreach($receipt['lines'] as $line) {
            $q=[];
            foreach(['good'=>'externalOutReviewGood',
                     'damaged'=>'externalOutReviewDamaged',
                     'unresolved'=>'externalOutReviewUnresolved'] as $type=>$field) {
                $raw=$this->{$field}[$line['itemId']]??null;
                if(!is_string($raw)||!preg_match('/^(0|[1-9][0-9]*)$/',$raw)||
                    (float)$raw>100000000)
                    throw ValidationException::withMessages([
                        'externalOutReviewGood'=>'Independently enter good, damaged, and unresolved as whole counts including zero.']);
                $q[$type]=(int)$raw;
            }
            $counts[$line['itemId']]=$q;
        }
        try {
            $this->lastExternalOutReturnReference=app(
                \App\Services\HallInventoryExternalOutReturnService::class
            )->review($actor,$this->companyId,$this->hallId,
                $this->externalOutReviewReceiptId,$this->externalOutReviewRequestId,$counts);
        }catch(InvalidArgumentException|ConflictHttpException $e){
            throw ValidationException::withMessages(['externalOutReviewGood'=>$e->getMessage()]);
        }
        $this->externalOutReviewReceiptId='';
        $this->externalOutReviewRequestId=(string)Str::uuid();
        $this->externalOutReviewGood=[];
        $this->externalOutReviewDamaged=[];
        $this->externalOutReviewUnresolved=[];
        $this->reloadLoanHistory();
    }

    public function startExternalBorrowedReceipt(string $loanId):void
    {
        $actor=$this->authorizeSelection();
        abort_unless(config('inventory_controls.external_borrowed_intake_enabled') &&
            config('inventory_controls.loan_drafts_enabled'),403);
        $loan=collect(app(\App\Services\HallInventoryLoanDraftReview::class)
            ->recent($actor,$this->companyId,$this->hallId))
            ->first(fn($l):bool=>$l['id']===$loanId && $l['direction']==='external_in' &&
                $l['borrowingHallId']===$this->hallId &&
                !$l['externalReceiptRecorded'] && !$l['externalReceiptAccepted'] &&
                $l['createdBy']!==(int)$actor->getKey());
        abort_unless($loan!==null,403);
        $this->externalIntakeLoanId=$loanId;
        $this->externalIntakeRequestId=(string)Str::uuid();
        $this->externalIntakeFile=null;
        $this->externalIntakeCounts=array_fill_keys(
            array_map(static fn(array $l):string=>$l['itemId'],$loan['lines']), '');
        $this->lastExternalIntakeReference=null;
    }

    public function submitExternalBorrowedReceipt():void
    {
        $actor=$this->authorizeSelection();
        abort_unless(config('inventory_controls.external_borrowed_intake_enabled') &&
            config('inventory_controls.loan_drafts_enabled'),403);
        $this->validate(['externalIntakeFile'=>'required|file|max:5120|mimes:jpg,jpeg,png,pdf']);
        $loan=collect(app(\App\Services\HallInventoryLoanDraftReview::class)
            ->recent($actor,$this->companyId,$this->hallId))
            ->first(fn($l):bool=>$l['id']===$this->externalIntakeLoanId &&
                $l['direction']==='external_in' && $l['borrowingHallId']===$this->hallId);
        abort_unless($loan!==null,403);
        $counts=[];
        foreach($loan['lines'] as $line) {
            $raw=$this->externalIntakeCounts[$line['itemId']]??null;
            if(!is_string($raw) || !preg_match('/^(0|[1-9][0-9]*)$/',$raw) ||
                (float)$raw>100000000 || (float)$raw<0)
                throw ValidationException::withMessages([
                    'externalIntakeCounts'=>'Enter each actual physically counted quantity, including zero.']);
            $counts[$line['itemId']]=(int)$raw;
        }
        try {
            $this->lastExternalIntakeReference=app(
                \App\Services\HallInventoryExternalBorrowedIntakeService::class
            )->record($actor,$this->companyId,$this->hallId,
                $this->externalIntakeLoanId,$this->externalIntakeRequestId,
                $this->externalIntakeFile,$counts);
        }catch(InvalidArgumentException|ConflictHttpException $e) {
            throw ValidationException::withMessages(['externalIntakeFile'=>$e->getMessage()]);
        }
        $this->externalIntakeLoanId='';
        $this->externalIntakeRequestId=(string)Str::uuid();
        $this->externalIntakeFile=null;
        $this->externalIntakeCounts=[];
        $this->reloadLoanHistory();
    }

    public function approveExternalBorrowedReceipt(string $loanId):void
    {
        $actor=$this->authorizeSelection();
        abort_unless(config('inventory_controls.external_borrowed_intake_enabled') &&
            config('inventory_controls.loan_drafts_enabled'),403);
        $loan=collect(app(\App\Services\HallInventoryLoanDraftReview::class)
            ->recent($actor,$this->companyId,$this->hallId))
            ->first(fn($l):bool=>$l['id']===$loanId &&
                $l['direction']==='external_in' &&
                $l['borrowingHallId']===$this->hallId &&
                $l['externalReceiptRecorded'] && !$l['externalReceiptAccepted']);
        abort_unless($loan!==null && $this->canIndependentlyAcceptExternalBorrowing(),403);
        try {
            $this->lastExternalIntakeReference=app(
                \App\Services\HallInventoryExternalBorrowedIntakeService::class
            )->accept($actor,$this->companyId,$this->hallId,$loanId,(string)Str::uuid());
        }catch(InvalidArgumentException|ConflictHttpException $e) {
            throw ValidationException::withMessages(['externalIntakeFile'=>$e->getMessage()]);
        }
        $this->reloadLoanHistory();
    }


    public function startExternalBorrowedReturn(string $loanId):void
    {
        $actor=$this->authorizeSelection();
        abort_unless(config('inventory_controls.external_borrowed_return_enabled') &&
            config('inventory_controls.external_borrowed_intake_enabled'),403);
        $loan=collect(app(\App\Services\HallInventoryLoanDraftReview::class)
            ->recent($actor,$this->companyId,$this->hallId))
            ->first(fn($l):bool=>$l['id']===$loanId && $l['direction']==='external_in' &&
                $l['borrowingHallId']===$this->hallId && $l['externalReceiptAccepted'] &&
                !($l['externalCleanClosed']??false) &&
                $l['createdBy']!==(int)$actor->getKey());
        abort_unless($loan!==null,403);
        $this->externalReturnLoanId=$loanId;
        $this->externalReturnBatchId=(string)Str::uuid();
        $this->externalReturnQuantities=array_fill_keys(
            array_map(static fn(array $l):string=>$l['itemId'],$loan['lines']), '');
        $this->externalReturnFile=null;
        $this->lastExternalReturnReference=null;
    }

    public function submitExternalBorrowedReturn():void
    {
        $actor=$this->authorizeSelection();
        abort_unless(config('inventory_controls.external_borrowed_return_enabled') &&
            config('inventory_controls.external_borrowed_intake_enabled'),403);
        $this->validate(['externalReturnFile'=>'required|file|max:5120|mimes:jpg,jpeg,png,pdf']);
        $loan=collect(app(\App\Services\HallInventoryLoanDraftReview::class)
            ->recent($actor,$this->companyId,$this->hallId))
            ->first(fn($l):bool=>$l['id']===$this->externalReturnLoanId &&
                $l['direction']==='external_in' && $l['borrowingHallId']===$this->hallId &&
                $l['externalReceiptAccepted']);
        abort_unless($loan!==null,403);
        $quantities=[];
        foreach($loan['lines'] as $line) {
            $raw=$this->externalReturnQuantities[$line['itemId']]??'';
            if($raw==='')continue;
            if(!is_string($raw)||!preg_match('/\A[1-9][0-9]{0,8}\z/D',$raw)||
                (int)$raw>100000000)
                throw ValidationException::withMessages([
                    'externalReturnQuantities'=>'Enter positive whole return quantities; leave other lines blank.'
                ]);
            $quantities[$line['itemId']]=(int)$raw;
        }
        if(!$quantities)
            throw ValidationException::withMessages(['externalReturnQuantities'=>'Choose a physical return quantity.']);
        try {
            $this->lastExternalReturnReference=app(\App\Services\HallInventoryExternalBorrowedReturnService::class)
                ->dispatch($actor,$this->companyId,$this->hallId,$loan['id'],
                    $this->externalReturnBatchId,$quantities,$this->externalReturnFile);
        }catch(InvalidArgumentException|ConflictHttpException $e) {
            throw ValidationException::withMessages(['externalReturnQuantities'=>$e->getMessage()]);
        }
        $this->externalReturnLoanId='';
        $this->externalReturnBatchId=(string)Str::uuid();
        $this->externalReturnFile=null;
        $this->externalReturnQuantities=[];
        $this->reloadLoanHistory();
    }

    public function startExternalReturnConfirmation(string $batchId):void
    {
        $actor=$this->authorizeSelection();
        abort_unless(config('inventory_controls.external_borrowed_return_enabled') &&
            config('inventory_controls.external_borrowed_intake_enabled') &&
            $this->canIndependentlyAcceptExternalBorrowing(),403);
        $batch=collect(app(\App\Services\HallInventoryExternalBorrowedReturnService::class)
            ->recent($actor,$this->companyId,$this->hallId))
            ->first(fn($b):bool=>$b['batchId']===$batchId && !$b['confirmed'] &&
                $b['dispatchedBy']!==(int)$actor->getKey());
        abort_unless($batch!==null,403);
        $this->externalConfirmBatchId=$batchId;
        $this->externalConfirmRequestId=(string)Str::uuid();
        $this->externalConfirmCounts=array_fill_keys(
            array_map(static fn(array $l):string=>$l['itemId'],$batch['lines']), '');
        $this->externalConfirmFile=null;
    }

    public function submitExternalReturnConfirmation():void
    {
        $actor=$this->authorizeSelection();
        abort_unless(config('inventory_controls.external_borrowed_return_enabled') &&
            config('inventory_controls.external_borrowed_intake_enabled') &&
            $this->canIndependentlyAcceptExternalBorrowing(),403);
        $this->validate(['externalConfirmFile'=>'required|file|max:5120|mimes:jpg,jpeg,png,pdf']);
        $batch=collect(app(\App\Services\HallInventoryExternalBorrowedReturnService::class)
            ->recent($actor,$this->companyId,$this->hallId))
            ->first(fn($b):bool=>$b['batchId']===$this->externalConfirmBatchId &&
                !$b['confirmed'] && $b['dispatchedBy']!==(int)$actor->getKey());
        abort_unless($batch!==null,403);
        $counts=[];
        foreach($batch['lines'] as $line) {
            $raw=$this->externalConfirmCounts[$line['itemId']]??null;
            if(!is_string($raw)||!preg_match('/\A(0|[1-9][0-9]{0,8})\z/D',$raw)||
                (int)$raw>100000000)
                throw ValidationException::withMessages([
                    'externalConfirmCounts'=>'Independently enter every outside partner received quantity.']);
            $counts[$line['itemId']]=(int)$raw;
        }
        try {
            $this->lastExternalReturnReference=app(\App\Services\HallInventoryExternalBorrowedReturnService::class)
                ->confirm($actor,$this->companyId,$this->hallId,$batch['batchId'],
                    $this->externalConfirmRequestId,$counts,$this->externalConfirmFile);
        }catch(InvalidArgumentException|ConflictHttpException $e) {
            throw ValidationException::withMessages(['externalConfirmCounts'=>$e->getMessage()]);
        }
        $this->externalConfirmBatchId='';
        $this->externalConfirmRequestId=(string)Str::uuid();
        $this->externalConfirmCounts=[];
        $this->externalConfirmFile=null;
        $this->reloadLoanHistory();
    }



    public function canCloseExternalOutClean():bool
    {
        if(!config('inventory_controls.external_out_clean_closure_enabled'))return false;
        $actor=auth()->user();
        $hall=Hall::query()->where('company_id',$this->companyId)
            ->where('id',$this->hallId)->where('is_active',true)->first();
        return $actor instanceof User && $hall!==null &&
            app(TenantAccess::class)->canConfigureHall($actor,$hall);
    }
    public function closeExternalOutCleanLoan(string $loanId):void
    {
        $actor=$this->authorizeSelection();
        abort_unless($this->canCloseExternalOutClean(),403);
        $loan=collect(app(\App\Services\HallInventoryLoanDraftReview::class)
            ->recent($actor,$this->companyId,$this->hallId))
            ->first(fn($l):bool=>$l['id']===$loanId &&
                $l['direction']==='external_out' &&
                $l['lendingHallId']===$this->hallId &&
                !$l['externalOutClosed'] &&
                ($l['externalOutReturnReconciliation']['allFullyReturnedClean']??false) &&
                $l['createdBy']!==(int)$actor->getKey());
        abort_unless($loan!==null,403);
        try {
            $this->lastExternalOutCleanReceipt=app(
                \App\Services\HallInventoryExternalOutCleanClosureService::class
            )->close($actor,$this->companyId,$loanId,$this->externalOutCleanRequestId);
        }catch(InvalidArgumentException|ConflictHttpException $e){
            throw ValidationException::withMessages(['externalOutCleanClosure'=>$e->getMessage()]);
        }
        $this->externalOutCleanRequestId=(string)Str::uuid();
        $this->reloadLoanHistory();
    }
    public function downloadExternalOutCleanLoanPdf(string $loanId):\Symfony\Component\HttpFoundation\StreamedResponse
    {
        $actor=$this->authorizeSelection();
        abort_unless($this->canCloseExternalOutClean(),403);
        $loan=collect(app(\App\Services\HallInventoryLoanDraftReview::class)
            ->recent($actor,$this->companyId,$this->hallId))
            ->first(fn($l):bool=>$l['id']===$loanId &&
                $l['direction']==='external_out' &&
                $l['lendingHallId']===$this->hallId);
        abort_unless($loan!==null,403);
        $bytes=app(\App\Services\HallInventoryExternalOutCleanClosureService::class)
            ->receiptPdf($actor,$this->companyId,$loanId);
        DB::table('audit_events')->insert([
            'id'=>(string)Str::uuid(),'company_id'=>$this->companyId,
            'actor_id'=>$actor->getKey(),
            'action'=>'hall_inventory.external_out_clean_receipt_pdf_downloaded',
            'entity_type'=>'hall_inventory_loan_draft','entity_id'=>$loanId,
            'before_values'=>json_encode([],JSON_THROW_ON_ERROR),
            'after_values'=>json_encode(['read'=>'hash_verified_eol_pdf','stockMoved'=>false],JSON_THROW_ON_ERROR),
            'context'=>json_encode(['hallId'=>$this->hallId,
                'receiptOnly'=>true,'financialPosting'=>false],JSON_THROW_ON_ERROR)
        ]);
        return response()->streamDownload(
            static function()use($bytes):void{echo $bytes;},
            'venue-inventory-external-out-clean-'.substr($loanId,0,8).'.pdf',
            ['Content-Type'=>'application/pdf','X-Content-Type-Options'=>'nosniff',
             'Cache-Control'=>'private, no-store']
        );
    }

    public function canCloseCleanExternalBorrowing():bool
    {
        if(!config('inventory_controls.external_borrowed_clean_closure_enabled'))return false;
        $actor=auth()->user();
        $hall=Hall::query()->where('company_id',$this->companyId)
            ->where('id',$this->hallId)->where('is_active',true)->first();
        return $actor instanceof User && $hall!==null &&
            app(TenantAccess::class)->canConfigureHall($actor,$hall);
    }

    public function closeCleanExternalBorrowing(string $loanId):void
    {
        $actor=$this->authorizeSelection();
        abort_unless(config('inventory_controls.external_borrowed_clean_closure_enabled') &&
            $this->canCloseCleanExternalBorrowing(),403);
        $loan=collect(app(\App\Services\HallInventoryLoanDraftReview::class)
            ->recent($actor,$this->companyId,$this->hallId))
            ->first(fn(array $l):bool=>$l['id']===$loanId && $l['direction']==='external_in' &&
                $l['borrowingHallId']===$this->hallId &&
                ($l['externalReturnReconciliation']['allPhysicalReturnsConfirmed']??false) &&
                $l['createdBy']!==(int)$actor->getKey());
        abort_unless($loan!==null,403);
        try {
            $summary=app(\App\Services\HallInventoryExternalCleanLoanClosureService::class)
                ->close($actor,$this->companyId,$loanId,$this->externalCleanClosureRequestId);
            $this->lastExternalCleanReceipt=$summary;
        }catch(InvalidArgumentException|ConflictHttpException $e) {
            throw ValidationException::withMessages(['externalCleanClosure'=>$e->getMessage()]);
        }
        $this->externalCleanClosureRequestId=(string)Str::uuid();
        $this->reloadLoanHistory();
    }

    public function downloadExternalCleanReceipt(string $loanId):\Symfony\Component\HttpFoundation\StreamedResponse
    {
        $actor=$this->authorizeSelection();
        abort_unless(config('inventory_controls.external_borrowed_clean_closure_enabled') &&
            $this->canCloseCleanExternalBorrowing(),403);
        $loan=collect(app(\App\Services\HallInventoryLoanDraftReview::class)
            ->recent($actor,$this->companyId,$this->hallId))
            ->first(fn(array $l):bool=>$l['id']===$loanId &&
                $l['direction']==='external_in' && $l['borrowingHallId']===$this->hallId);
        abort_unless($loan!==null,403);
        $bytes=app(\App\Services\HallInventoryExternalCleanLoanClosureService::class)
            ->receiptPdf($actor,$this->companyId,$loanId);
        DB::table('audit_events')->insert([
            'id'=>(string)Str::uuid(),'company_id'=>$this->companyId,
            'actor_id'=>$actor->getKey(),
            'action'=>'hall_inventory.external_clean_loan_receipt_pdf_viewed',
            'entity_type'=>'hall_inventory_loan_draft','entity_id'=>$loanId,
            'before_values'=>json_encode([],JSON_THROW_ON_ERROR),
            'after_values'=>json_encode(['read'=>'verified_pdf','stockMoved'=>false],JSON_THROW_ON_ERROR),
            'context'=>json_encode(['hallId'=>$this->hallId,
                'receiptOnly'=>true,'financialPosting'=>false],JSON_THROW_ON_ERROR)
        ]);
        return response()->streamDownload(
            static function()use($bytes):void{echo $bytes;},
            'venue-inventory-external-clean-loan-'.substr($loanId,0,8).'.pdf',
            ['Content-Type'=>'application/pdf','X-Content-Type-Options'=>'nosniff',
             'Cache-Control'=>'private, no-store']
        );
    }

    public function startInternalDispatch(string $loanId):void
    {
        $actor=$this->authorizeSelection();
        abort_unless(config('inventory_controls.loan_drafts_enabled') &&
            config('inventory_controls.loan_dispatch_enabled'),403);
        $hall=Hall::query()->where('company_id',$this->companyId)
            ->where('id',$this->hallId)->where('is_active',true)->first();
        abort_unless($hall && app(TenantAccess::class)->canConfigureHall($actor,$hall),403);
        $loan=collect(app(\App\Services\HallInventoryLoanDraftReview::class)
            ->recent($actor,$this->companyId,$this->hallId))
            ->first(fn($row):bool => $row['id']===$loanId &&
                $row['direction']==='internal' &&
                $row['lendingHallId']===$this->hallId &&
                ($row['status']??'')==='draft_only');
        abort_unless($loan!==null,403);
        $this->loanDispatchLoanId=$loanId;
        $this->loanDispatchRequestId=(string)Str::uuid();
        $this->loanDispatchLines=$loan['lines'];
        $this->loanDispatchFile=null;
        $this->lastLoanDispatchReference=null;
    }

    public function submitInternalDispatch():void
    {
        $actor=$this->authorizeSelection();
        abort_unless(config('inventory_controls.loan_drafts_enabled') &&
            config('inventory_controls.loan_dispatch_enabled'),403);
        $hall=Hall::query()->where('company_id',$this->companyId)
            ->where('id',$this->hallId)->where('is_active',true)->first();
        abort_unless($hall && app(TenantAccess::class)->canConfigureHall($actor,$hall),403);
        $loan=collect(app(\App\Services\HallInventoryLoanDraftReview::class)
            ->recent($actor,$this->companyId,$this->hallId))
            ->first(fn($row):bool => $row['id']===$this->loanDispatchLoanId &&
                $row['direction']==='internal' &&
                $row['lendingHallId']===$this->hallId &&
                ($row['status']??'')==='draft_only');
        abort_unless($loan!==null,403);
        $this->validate(['loanDispatchFile'=>'required|file|max:5120|mimes:jpg,jpeg,png,pdf']);
        $revisions=[];
        foreach($loan['lines'] as $line) {
            $position=DB::table('hall_inventory_positions')
                ->where('company_id',$this->companyId)
                ->where('hall_id',$this->hallId)
                ->where('item_id',$line['itemId'])
                ->first(['revision','owned_usable']);
            if(!$position || (int)$position->owned_usable<(int)$line['quantity'])
                throw ValidationException::withMessages([
                    'loanDispatchFile'=>'Physical owned stock is insufficient for the full loan.']);
            $revisions[$line['itemId']]=(int)$position->revision;
        }
        try {
            $this->lastLoanDispatchReference=app(
                \App\Services\HallInventoryInternalLoanDispatchService::class
            )->dispatch($actor,$this->companyId,$this->loanDispatchLoanId,
                $this->loanDispatchRequestId,$revisions,$this->loanDispatchFile);
        }catch(InvalidArgumentException|ConflictHttpException $e) {
            throw ValidationException::withMessages(['loanDispatchFile'=>$e->getMessage()]);
        }
        $this->loanDispatchLoanId='';
        $this->loanDispatchRequestId=(string)Str::uuid();
        $this->loanDispatchLines=[];
        $this->loanDispatchFile=null;
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
                $row['lendingHallId']===$this->hallId &&
                (($row['direction']==='internal' && $row['destinationReceiptConfirmed']) ||
                 ($row['direction']==='external_out' &&
                  config('inventory_controls.external_out_return_enabled') &&
                  $row['externalOutApproved'])));
        abort_unless($loan!==null,403);
        $this->internalAbnormalCustody=[];
        $this->internalDamageWriteoffs=[];
        $this->internalDamageEligible=[];
        if($loan['direction']==='internal')
            $this->internalAbnormalCustody=app(\App\Services\HallInventoryInternalLoanReturnReview::class)
                ->abnormalSourceLines($actor,$this->companyId,$this->hallId,$loanId);
        if($loan['direction']==='internal'&&
            config('inventory_controls.loan_nonclean_writeoffs_enabled')&&
            config('inventory_controls.loan_nonclean_reconciliation_enabled')) {
            $this->reloadInternalDamageDisposition($actor,$loanId);
        }
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

    private function reloadInternalDamageDisposition(User $actor,string $loanId):void {
        $svc=app(\\App\\Services\\HallInventoryNoncleanWriteoffService::class);
        $this->internalDamageWriteoffs=$svc->history($actor,$this->companyId,$this->hallId,$loanId);
        $this->internalDamageEligible=$svc->eligible($actor,$this->companyId,$this->hallId,$loanId);
    }
    public function proposeInternalDamageDisposition():void {
        $actor=$this->authorizeSelection();
        abort_unless($this->incidentLoanId!==''&&count($this->internalAbnormalCustody)>0,403);
        $this->validate([
            'internalDamageIncidentId'=>'required|uuid',
            'internalDamageQuantity'=>'required|integer|min:1|max:100000000',
            'internalDamageReason'=>'required|string|min:10|max:500'
        ]);
        if($this->internalDamageProposalId==='')$this->internalDamageProposalId=(string)Str::uuid();
        app(\\App\\Services\\HallInventoryNoncleanWriteoffService::class)
            ->propose($actor,$this->companyId,$this->hallId,$this->internalDamageIncidentId,
                $this->internalDamageProposalId,(int)$this->internalDamageQuantity,$this->internalDamageReason);
        $this->internalDamageProposalId=(string)Str::uuid();
        $this->reloadInternalDamageDisposition($actor,$this->incidentLoanId);
    }
    public function decideInternalDamageDisposition():void {
        $actor=$this->authorizeSelection();
        abort_unless($this->incidentLoanId!==''&&count($this->internalAbnormalCustody)>0,403);
        $this->validate([
            'internalDamageReviewProposalId'=>'required|uuid',
            'internalDamageReviewDecision'=>'required|in:approved,rejected',
            'internalDamageReviewReason'=>'required|string|min:10|max:500'
        ]);
        if($this->internalDamageDecisionId==='')$this->internalDamageDecisionId=(string)Str::uuid();
        app(\\App\\Services\\HallInventoryNoncleanWriteoffService::class)
            ->decide($actor,$this->companyId,$this->hallId,$this->internalDamageReviewProposalId,
                $this->internalDamageDecisionId,$this->internalDamageReviewDecision,$this->internalDamageReviewReason);
        $this->internalDamageDecisionId=(string)Str::uuid();
        $this->reloadInternalDamageDisposition($actor,$this->incidentLoanId);
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


    /**
     * Owner/admin provisional claim desk. Every actionable lookup is scoped again
     * on the server; public Livewire selections never grant financial authority.
     */
    public function canManageFinancialClaims():bool
    {
        return config('inventory_controls.loan_financial_claims_enabled') &&
            config('inventory_controls.loan_incidents_enabled') &&
            config('inventory_controls.loan_incident_reviews_enabled') &&
            config('inventory_controls.external_out_return_enabled') &&
            $this->canApproveExternalOutDispatch();
    }

    public function startFinancialClaims(string $loanId):void
    {
        $actor=$this->authorizeSelection();
        abort_unless($this->canManageFinancialClaims() && Str::isUuid($loanId),403);
        $loan=collect(app(\App\Services\HallInventoryLoanDraftReview::class)
            ->recent($actor,$this->companyId,$this->hallId))
            ->first(fn($row):bool=>$row['id']===$loanId &&
                $row['direction']==='external_out' &&
                $row['lendingHallId']===$this->hallId &&
                $row['externalOutApproved']);
        abort_unless($loan!==null,403);
        $this->financialClaimLoanId=$loanId;
        $this->noncleanWriteoffProposalId=(string)Str::uuid();
        $this->noncleanWriteoffDecisionId=(string)Str::uuid();
        $this->noncleanWriteoffIncidentId='';
        $this->noncleanWriteoffQuantity='';
        $this->noncleanWriteoffReason='';
        $this->noncleanWriteoffDecisionProposalId='';
        $this->noncleanWriteoffDecisionReason='';
        $this->financialWaiverProposalId=(string)Str::uuid();
        $this->financialWaiverDecisionId=(string)Str::uuid();
        $this->financialWaiverObligationId='';
        $this->financialWaiverAmountMinor='';
        $this->financialWaiverReason='';
        $this->financialWaiverDecisionProposalId='';
        $this->financialWaiverDecisionReason='';
        $this->financialRecoveryRequestId=(string)Str::uuid();
        $this->financialRecoveryReviewId=(string)Str::uuid();
        $this->financialRecoveryReversalId=(string)Str::uuid();
        $this->financialRecoveryObligationId='';
        $this->financialRecoveryAmountMinor='';
        $this->financialRecoveryBankReference='';
        $this->financialRecoveryReceiptId='';
        $this->financialRecoveryFile=null;
        $this->financialRecoveryVerificationReference='';
        $this->financialRecoveryReviewReason='';
        $this->financialRecoveryReversalReceiptId='';
        $this->financialRecoveryReversalReason='';
        $this->financialObligationRequestId=(string)Str::uuid();
        $this->financialObligationDecisionId=(string)Str::uuid();
        $this->financialObligationEvidenceId='';
        $this->financialObligationAmountMinor='';
        $this->financialObligationIdentityReference='';
        $this->financialObligationReason='';
        $this->financialObligationDecisionProposalId='';
        $this->financialObligationDecisionReason='';
        $this->financialPartnerRequestId=(string)Str::uuid();
        $this->financialPartnerReviewId=(string)Str::uuid();
        $this->financialPartnerClaimId='';
        $this->financialPartnerReviewEvidenceId='';
        $this->financialPartnerFile=null;
        $this->financialPartnerCounterofferMinor='';
        $this->financialPartnerReviewReason='';
        $this->financialClaimReportId='';
        $this->financialClaimReason='';
        $this->financialClaimAmountMinor='';
        $this->financialClaimDecisionClaimId='';
        $this->financialClaimDecisionReason='';
        $this->financialClaimRequestId=(string)Str::uuid();
        $this->financialClaimDecisionId=(string)Str::uuid();
        $this->reloadFinancialClaims($actor);
    }

    private function reloadFinancialClaims(User $actor):void
    {
        abort_unless($this->canManageFinancialClaims() &&
            $this->financialClaimLoanId!=='',403);
        $this->noncleanReconciliation = [];
        $this->noncleanWriteoffHistory=[];
        $this->noncleanWriteoffEligible=[];
        if(config('inventory_controls.loan_nonclean_writeoffs_enabled') &&
            config('inventory_controls.loan_nonclean_reconciliation_enabled')) {
            $writer=app(\App\Services\HallInventoryNoncleanWriteoffService::class);
            $this->noncleanWriteoffHistory=$writer->history($actor,$this->companyId,
                $this->hallId,$this->financialClaimLoanId);
            $this->noncleanWriteoffEligible=$writer->eligible($actor,$this->companyId,
                $this->hallId,$this->financialClaimLoanId);
        }
        if(config('inventory_controls.loan_nonclean_reconciliation_enabled')) {
            $this->noncleanReconciliation=app(\App\Services\HallInventoryNoncleanReconciliationService::class)
                ->report($actor,$this->companyId,$this->hallId,$this->financialClaimLoanId);
        }
        $this->financialClaimHistory=app(\App\Services\HallInventoryFinancialClaimService::class)
            ->history($actor,$this->companyId,$this->hallId,$this->financialClaimLoanId);
        $this->financialWaiverHistory=[];
        $this->financialRecoveryHistory=[];
        $this->financialRecoveryBalances=[];
        if(config('inventory_controls.loan_financial_waivers_enabled')) {
            $this->financialWaiverHistory=app(\App\Services\HallInventoryFinancialWaiverService::class)
                ->history($actor,$this->companyId,$this->hallId,$this->financialClaimLoanId);
        }
        if(config('inventory_controls.loan_recoveries_enabled') &&
            config('inventory_controls.loan_authorized_obligations_enabled') &&
            config('inventory_controls.loan_partner_responses_enabled')) {
            $recoveries=app(\App\Services\HallInventoryFinancialRecoveryService::class);
            $this->financialRecoveryHistory=$recoveries->history(
                $actor,$this->companyId,$this->hallId,$this->financialClaimLoanId);
            $this->financialRecoveryBalances=$recoveries->outstanding(
                $actor,$this->companyId,$this->hallId,$this->financialClaimLoanId);
        }
        $this->financialObligationHistory=[];
        if(config('inventory_controls.loan_authorized_obligations_enabled') &&
            config('inventory_controls.loan_partner_responses_enabled')) {
            $this->financialObligationHistory=app(\App\Services\HallInventoryFinancialObligationService::class)
                ->history($actor,$this->companyId,$this->hallId,$this->financialClaimLoanId);
        }
        $this->financialPartnerHistory=[];
        if(config('inventory_controls.loan_partner_responses_enabled')) {
            $this->financialPartnerHistory=app(\App\Services\HallInventoryFinancialCounterpartyEvidenceService::class)
                ->history($actor,$this->companyId,$this->hallId,$this->financialClaimLoanId);
        }
        // A substantiated decision is evidence, not an authorization for money.
        // Only reports not already claimed are selectable for a proposal.
        $this->financialClaimEligibleReports=DB::table('hall_inventory_loan_incident_reports as r')
            ->join('hall_inventory_loan_incident_decisions as d',function($j):void {
                $j->on('d.company_id','=','r.company_id')
                    ->on('d.report_id','=','r.id');
            })
            ->where('r.company_id',$this->companyId)
            ->where('r.source_hall_id',$this->hallId)
            ->where('r.loan_id',$this->financialClaimLoanId)
            ->where('d.decision','substantiated')
            ->whereNotExists(function($q):void {
                $q->selectRaw('1')->from('hall_inventory_financial_claims as c')
                    ->whereColumn('c.company_id','r.company_id')
                    ->whereColumn('c.report_id','r.id');
            })
            ->orderBy('r.created_at')->limit(100)
            ->get(['r.id','r.kind','r.quantity'])
            ->map(static fn($row):array=>[
                'reportId'=>(string)$row->id,
                'kind'=>(string)$row->kind,
                'quantity'=>(int)$row->quantity
            ])->all();
    }

    public function submitNoncleanWriteoff():void {
        $actor=$this->authorizeSelection();
        abort_unless($this->canManageFinancialClaims() &&
            config('inventory_controls.loan_nonclean_writeoffs_enabled') &&
            $this->financialClaimLoanId!=='',403);
        $this->validate([
            'noncleanWriteoffIncidentId'=>'required|uuid',
            'noncleanWriteoffQuantity'=>['required','regex:/\A[1-9][0-9]{0,8}\z/D'],
            'noncleanWriteoffReason'=>'required|string|min:10|max:500'
        ]);
        app(\App\Services\HallInventoryNoncleanWriteoffService::class)->propose(
            $actor,$this->companyId,$this->hallId,$this->noncleanWriteoffIncidentId,
            $this->noncleanWriteoffProposalId,(int)$this->noncleanWriteoffQuantity,
            $this->noncleanWriteoffReason);
        $this->noncleanWriteoffProposalId=(string)Str::uuid();
        $this->noncleanWriteoffIncidentId='';
        $this->noncleanWriteoffQuantity='';
        $this->noncleanWriteoffReason='';
        $this->reloadFinancialClaims($actor);
    }
    public function decideNoncleanWriteoff():void {
        $actor=$this->authorizeSelection();
        abort_unless($this->canManageFinancialClaims() &&
            config('inventory_controls.loan_nonclean_writeoffs_enabled') &&
            $this->financialClaimLoanId!=='',403);
        $this->validate([
            'noncleanWriteoffDecisionProposalId'=>'required|uuid',
            'noncleanWriteoffDecision'=>'required|in:approved,rejected',
            'noncleanWriteoffDecisionReason'=>'required|string|min:10|max:500'
        ]);
        app(\App\Services\HallInventoryNoncleanWriteoffService::class)->decide(
            $actor,$this->companyId,$this->hallId,$this->noncleanWriteoffDecisionProposalId,
            $this->noncleanWriteoffDecisionId,$this->noncleanWriteoffDecision,
            $this->noncleanWriteoffDecisionReason);
        $this->noncleanWriteoffDecisionId=(string)Str::uuid();
        $this->noncleanWriteoffDecisionProposalId='';
        $this->noncleanWriteoffDecisionReason='';
        $this->reloadFinancialClaims($actor);
    }

    public function submitFinancialClaim():void
    {
        $actor=$this->authorizeSelection();
        abort_unless($this->canManageFinancialClaims() &&
            $this->financialClaimLoanId!=='',403);
        $this->validate([
            'financialClaimReportId'=>'required|uuid',
            'financialClaimCurrency'=>['required','regex:/\A[A-Z]{3}\z/D'],
            'financialClaimAmountMinor'=>['required','regex:/\A[1-9][0-9]{0,11}\z/D'],
            'financialClaimReason'=>'required|string|min:10|max:500',
        ]);
        // Do not trust a stale or tampered client-side report selector.
        $this->reloadFinancialClaims($actor);
        abort_unless(collect($this->financialClaimEligibleReports)->contains(
            fn($r):bool=>$r['reportId']===$this->financialClaimReportId),403);
        try {
            $this->lastFinancialClaimReference=app(\App\Services\HallInventoryFinancialClaimService::class)
                ->propose($actor,$this->companyId,$this->financialClaimReportId,
                    $this->financialClaimRequestId,$this->financialClaimCurrency,
                    (int)$this->financialClaimAmountMinor,$this->financialClaimReason);
        }catch(InvalidArgumentException|ConflictHttpException $e){
            throw ValidationException::withMessages(['financialClaimReason'=>$e->getMessage()]);
        }
        $this->financialClaimRequestId=(string)Str::uuid();
        $this->financialClaimReportId='';
        $this->financialClaimAmountMinor='';
        $this->financialClaimReason='';
        $this->reloadFinancialClaims($actor);
    }

    public function submitFinancialClaimDecision():void
    {
        $actor=$this->authorizeSelection();
        abort_unless($this->canManageFinancialClaims() &&
            $this->financialClaimLoanId!=='',403);
        $this->validate([
            'financialClaimDecisionClaimId'=>'required|uuid',
            'financialClaimDecision'=>'required|in:approved_for_collection,rejected,disputed',
            'financialClaimDecisionReason'=>'required|string|min:10|max:500',
        ]);
        $this->reloadFinancialClaims($actor);
        abort_unless(collect($this->financialClaimHistory)->contains(
            fn($row):bool=>$row['id']===$this->financialClaimDecisionClaimId &&
                $row['decision']===null &&
                $row['proposedBy']!==(int)$actor->getKey()),403);
        try {
            $this->lastFinancialClaimReference=app(\App\Services\HallInventoryFinancialClaimService::class)
                ->decide($actor,$this->companyId,$this->financialClaimDecisionClaimId,
                    $this->financialClaimDecisionId,$this->financialClaimDecision,
                    $this->financialClaimDecisionReason);
        }catch(InvalidArgumentException|ConflictHttpException $e){
            throw ValidationException::withMessages(['financialClaimDecisionReason'=>$e->getMessage()]);
        }
        $this->financialClaimDecisionId=(string)Str::uuid();
        $this->financialClaimDecisionClaimId='';
        $this->financialClaimDecisionReason='';
        $this->reloadFinancialClaims($actor);
    }


    /** Owner-recorded documentation is never automatically a recognized obligation. */
    public function submitFinancialPartnerEvidence():void
    {
        $actor=$this->authorizeSelection();
        abort_unless($this->canManageFinancialClaims() &&
            config('inventory_controls.loan_partner_responses_enabled') &&
            $this->financialClaimLoanId!=='',403);
        $this->validate([
            'financialPartnerClaimId'=>'required|uuid',
            'financialPartnerPosition'=>'required|in:document_says_accepts,document_says_disputes,counteroffer,other_document',
            'financialPartnerFile'=>'required|file|max:5120|mimes:jpg,jpeg,png,pdf',
        ]);
        $this->reloadFinancialClaims($actor);
        abort_unless(collect($this->financialClaimHistory)->contains(
            fn($c):bool=>$c['id']===$this->financialPartnerClaimId &&
                in_array($c['decision'],['approved_for_collection','disputed'],true)),403);
        $offer=null;
        if($this->financialPartnerPosition==='counteroffer'){
            $this->validate([
                'financialPartnerCounterofferMinor'=>['required','regex:/\A[1-9][0-9]{0,11}\z/D']
            ]);
            $offer=(int)$this->financialPartnerCounterofferMinor;
        }elseif($this->financialPartnerCounterofferMinor!==''){
            throw ValidationException::withMessages([
                'financialPartnerCounterofferMinor'=>'Only a documentary counteroffer may include an offered monetary amount.'
            ]);
        }
        try {
            $this->lastFinancialPartnerReference=app(\App\Services\HallInventoryFinancialCounterpartyEvidenceService::class)
                ->record($actor,$this->companyId,$this->financialPartnerClaimId,
                    $this->financialPartnerRequestId,$this->financialPartnerPosition,
                    $offer,$this->financialPartnerFile);
        }catch(InvalidArgumentException|ConflictHttpException $e){
            throw ValidationException::withMessages(['financialPartnerFile'=>$e->getMessage()]);
        }
        $this->financialPartnerRequestId=(string)Str::uuid();
        $this->financialPartnerClaimId='';
        $this->financialPartnerCounterofferMinor='';
        $this->financialPartnerFile=null;
        $this->reloadFinancialClaims($actor);
    }

    public function submitFinancialPartnerReview():void
    {
        $actor=$this->authorizeSelection();
        abort_unless($this->canManageFinancialClaims() &&
            config('inventory_controls.loan_partner_responses_enabled') &&
            $this->financialClaimLoanId!=='',403);
        $this->validate([
            'financialPartnerReviewEvidenceId'=>'required|uuid',
            'financialPartnerAssessment'=>'required|in:document_integrity_confirmed,insufficient_partner_proof',
            'financialPartnerReviewReason'=>'required|string|min:10|max:500',
        ]);
        $this->reloadFinancialClaims($actor);
        abort_unless(collect($this->financialPartnerHistory)->contains(
            fn($p):bool=>$p['id']===$this->financialPartnerReviewEvidenceId &&
                $p['assessment']===null &&
                $p['recordedBy']!==(int)$actor->getKey() &&
                $p['proposedBy']!==(int)$actor->getKey()),403);
        try {
            $this->lastFinancialPartnerReference=app(\App\Services\HallInventoryFinancialCounterpartyEvidenceService::class)
                ->review($actor,$this->companyId,$this->financialPartnerReviewEvidenceId,
                    $this->financialPartnerReviewId,$this->financialPartnerAssessment,
                    $this->financialPartnerReviewReason);
        }catch(InvalidArgumentException|ConflictHttpException $e) {
            throw ValidationException::withMessages(['financialPartnerReviewReason'=>$e->getMessage()]);
        }
        $this->financialPartnerReviewId=(string)Str::uuid();
        $this->financialPartnerReviewEvidenceId='';
        $this->financialPartnerReviewReason='';
        $this->reloadFinancialClaims($actor);
    }

    public function downloadFinancialPartnerDocument(string $evidenceId):\Symfony\Component\HttpFoundation\StreamedResponse
    {
        $actor=$this->authorizeSelection();
        abort_unless($this->canManageFinancialClaims() &&
            config('inventory_controls.loan_partner_responses_enabled') &&
            $this->financialClaimLoanId!=='' && Str::isUuid($evidenceId),403);
        return app(\App\Services\HallInventoryFinancialCounterpartyEvidenceService::class)
            ->download($actor,$this->companyId,$this->hallId,
                $this->financialClaimLoanId,$evidenceId);
    }


    public function submitFinancialObligation():void
    {
        $actor=$this->authorizeSelection();
        abort_unless($this->canManageFinancialClaims() &&
            config('inventory_controls.loan_authorized_obligations_enabled') &&
            config('inventory_controls.loan_partner_responses_enabled') &&
            $this->financialClaimLoanId!=='',403);
        $this->validate([
            'financialObligationEvidenceId'=>'required|uuid',
            'financialObligationAmountMinor'=>['required','regex:/\A[1-9][0-9]{0,11}\z/D'],
            'financialObligationIdentityMethod'=>'required|in:verified_callback,in_person_signed,trusted_contract_record',
            'financialObligationIdentityReference'=>'required|string|min:12|max:200',
            'financialObligationReason'=>'required|string|min:10|max:500',
        ]);
        $this->reloadFinancialClaims($actor);
        $partnerDoc=collect($this->financialPartnerHistory)->first(fn($d):bool=>
            $d['id']===$this->financialObligationEvidenceId &&
            in_array($d['position'],['document_says_accepts','counteroffer'],true) &&
            $d['assessment']==='document_integrity_confirmed');
        abort_unless($partnerDoc!==null,403);
        $claim=collect($this->financialClaimHistory)->first(fn($c):bool=>
            $c['id']===$partnerDoc['claimId'] &&
            $c['decision']==='approved_for_collection');
        abort_unless($claim!==null,403);
        try {
            $this->lastFinancialObligationReference=app(\App\Services\HallInventoryFinancialObligationService::class)
                ->propose($actor,$this->companyId,$claim['id'],
                    $this->financialObligationEvidenceId,$this->financialObligationRequestId,
                    (int)$this->financialObligationAmountMinor,
                    $this->financialObligationIdentityMethod,
                    $this->financialObligationIdentityReference,
                    $this->financialObligationReason);
        }catch(InvalidArgumentException|ConflictHttpException $e){
            throw ValidationException::withMessages(['financialObligationReason'=>$e->getMessage()]);
        }
        $this->financialObligationRequestId=(string)Str::uuid();
        $this->financialObligationEvidenceId='';
        $this->financialObligationAmountMinor='';
        $this->financialObligationIdentityReference='';
        $this->financialObligationReason='';
        $this->reloadFinancialClaims($actor);
    }

    public function submitFinancialObligationDecision():void
    {
        $actor=$this->authorizeSelection();
        abort_unless($this->canManageFinancialClaims() &&
            config('inventory_controls.loan_authorized_obligations_enabled') &&
            config('inventory_controls.loan_partner_responses_enabled') &&
            $this->financialClaimLoanId!=='',403);
        $this->validate([
            'financialObligationDecisionProposalId'=>'required|uuid',
            'financialObligationDecision'=>'required|in:authorized,rejected,disputed',
            'financialObligationDecisionReason'=>'required|string|min:10|max:500',
        ]);
        $this->reloadFinancialClaims($actor);
        abort_unless(collect($this->financialObligationHistory)->contains(
            fn($row):bool=>$row['id']===$this->financialObligationDecisionProposalId &&
                $row['decision']===null &&
                $row['proposedBy']!==(int)$actor->getKey()),403);
        try {
            $this->lastFinancialObligationReference=app(\App\Services\HallInventoryFinancialObligationService::class)
                ->decide($actor,$this->companyId,$this->financialObligationDecisionProposalId,
                    $this->financialObligationDecisionId,$this->financialObligationDecision,
                    $this->financialObligationDecisionReason);
        }catch(InvalidArgumentException|ConflictHttpException $e){
            throw ValidationException::withMessages(['financialObligationDecisionReason'=>$e->getMessage()]);
        }
        $this->financialObligationDecisionId=(string)Str::uuid();
        $this->financialObligationDecisionProposalId='';
        $this->financialObligationDecisionReason='';
        $this->reloadFinancialClaims($actor);
    }


    public function submitFinancialWaiver():void {
        $actor=$this->authorizeSelection();
        abort_unless($this->canManageFinancialClaims() &&
            config('inventory_controls.loan_financial_waivers_enabled') &&
            $this->financialClaimLoanId!=='',403);
        $this->validate([
            'financialWaiverObligationId'=>'required|uuid',
            'financialWaiverAmountMinor'=>['required','regex:/\A[1-9][0-9]{0,11}\z/D'],
            'financialWaiverReason'=>'required|string|min:10|max:500'
        ]);
        app(\App\Services\HallInventoryFinancialWaiverService::class)->propose(
            $actor,$this->companyId,$this->hallId,$this->financialWaiverObligationId,
            $this->financialWaiverProposalId,(int)$this->financialWaiverAmountMinor,
            $this->financialWaiverReason);
        $this->financialWaiverProposalId=(string)Str::uuid();
        $this->financialWaiverObligationId='';
        $this->financialWaiverAmountMinor='';
        $this->financialWaiverReason='';
        $this->reloadFinancialClaims($actor);
    }
    public function decideFinancialWaiver():void {
        $actor=$this->authorizeSelection();
        abort_unless($this->canManageFinancialClaims() &&
            config('inventory_controls.loan_financial_waivers_enabled') &&
            $this->financialClaimLoanId!=='',403);
        $this->validate([
            'financialWaiverDecisionProposalId'=>'required|uuid',
            'financialWaiverDecision'=>'required|in:approved,rejected',
            'financialWaiverDecisionReason'=>'required|string|min:10|max:500'
        ]);
        app(\App\Services\HallInventoryFinancialWaiverService::class)->decide(
            $actor,$this->companyId,$this->hallId,$this->financialWaiverDecisionProposalId,
            $this->financialWaiverDecisionId,$this->financialWaiverDecision,
            $this->financialWaiverDecisionReason);
        $this->financialWaiverDecisionId=(string)Str::uuid();
        $this->financialWaiverDecisionProposalId='';
        $this->financialWaiverDecisionReason='';
        $this->reloadFinancialClaims($actor);
    }

    public function submitFinancialRecovery():void
    {
        $actor=$this->authorizeSelection();
        abort_unless($this->canManageFinancialClaims() &&
            config('inventory_controls.loan_recoveries_enabled') &&
            $this->financialClaimLoanId!=='',403);
        $this->validate([
            'financialRecoveryObligationId'=>'required|uuid',
            'financialRecoveryAmountMinor'=>['required','regex:/\A[1-9][0-9]{0,11}\z/D'],
            'financialRecoveryMethod'=>'required|in:cash,bank',
            'financialRecoveryFile'=>'required|file|max:5120|mimes:jpg,jpeg,png,pdf',
        ]);
        $this->reloadFinancialClaims($actor);
        abort_unless(collect($this->financialObligationHistory)->contains(
            fn($o):bool=>$o['id']===$this->financialRecoveryObligationId &&
                $o['decision']==='authorized'),403);
        $ref=$this->financialRecoveryMethod==='bank'?trim($this->financialRecoveryBankReference):null;
        if($this->financialRecoveryMethod==='cash'&&$this->financialRecoveryBankReference!=='')
            throw ValidationException::withMessages([
                'financialRecoveryBankReference'=>'Cash evidence cannot be assigned a bank reference.'
            ]);
        try {
            $this->lastFinancialRecoveryReference=app(\App\Services\HallInventoryFinancialRecoveryService::class)
                ->record($actor,$this->companyId,$this->financialRecoveryObligationId,
                    $this->financialRecoveryRequestId,$this->financialRecoveryMethod,
                    (int)$this->financialRecoveryAmountMinor,$ref,$this->financialRecoveryFile);
        }catch(InvalidArgumentException|ConflictHttpException $e) {
            throw ValidationException::withMessages(['financialRecoveryFile'=>$e->getMessage()]);
        }
        $this->financialRecoveryRequestId=(string)Str::uuid();
        $this->financialRecoveryObligationId='';
        $this->financialRecoveryAmountMinor='';
        $this->financialRecoveryBankReference='';
        $this->financialRecoveryFile=null;
        $this->reloadFinancialClaims($actor);
    }

    public function submitFinancialRecoveryReview():void
    {
        $actor=$this->authorizeSelection();
        abort_unless($this->canManageFinancialClaims() &&
            config('inventory_controls.loan_recoveries_enabled') &&
            $this->financialClaimLoanId!=='',403);
        $this->validate([
            'financialRecoveryReceiptId'=>'required|uuid',
            'financialRecoveryDecision'=>'required|in:confirmed,rejected',
            'financialRecoveryVerificationMethod'=>'required|in:verified_bank_statement,witnessed_cash_count',
            'financialRecoveryVerificationReference'=>'required|string|min:12|max:160',
            'financialRecoveryReviewReason'=>'required|string|min:10|max:500',
        ]);
        $this->reloadFinancialClaims($actor);
        abort_unless(collect($this->financialRecoveryHistory)->contains(
            fn($row):bool=>$row['id']===$this->financialRecoveryReceiptId &&
                $row['decision']===null &&
                $row['recordedBy']!==(int)$actor->getKey()),403);
        try {
            $this->lastFinancialRecoveryReference=app(\App\Services\HallInventoryFinancialRecoveryService::class)
                ->verify($actor,$this->companyId,$this->financialRecoveryReceiptId,
                    $this->financialRecoveryReviewId,$this->financialRecoveryDecision,
                    $this->financialRecoveryVerificationMethod,
                    $this->financialRecoveryVerificationReference,
                    $this->financialRecoveryReviewReason);
        }catch(InvalidArgumentException|ConflictHttpException $e) {
            throw ValidationException::withMessages(['financialRecoveryReviewReason'=>$e->getMessage()]);
        }
        $this->financialRecoveryReviewId=(string)Str::uuid();
        $this->financialRecoveryReceiptId='';
        $this->financialRecoveryVerificationReference='';
        $this->financialRecoveryReviewReason='';
        $this->reloadFinancialClaims($actor);
    }


    public function downloadFinancialRecoveryEvidence(string $receiptId):\Symfony\Component\HttpFoundation\StreamedResponse
    {
        $actor=$this->authorizeSelection();
        abort_unless($this->canManageFinancialClaims() &&
            config('inventory_controls.loan_recoveries_enabled') &&
            $this->financialClaimLoanId!=='' && Str::isUuid($receiptId),403);
        return app(\App\Services\HallInventoryFinancialRecoveryService::class)
            ->download($actor,$this->companyId,$this->hallId,$this->financialClaimLoanId,$receiptId);
    }

    public function reverseFinancialRecovery():void
    {
        $actor=$this->authorizeSelection();
        abort_unless($this->canManageFinancialClaims() &&
            config('inventory_controls.loan_recoveries_enabled') &&
            $this->financialClaimLoanId!=='',403);
        $this->validate([
            'financialRecoveryReversalReceiptId'=>'required|uuid',
            'financialRecoveryReversalReason'=>'required|string|min:15|max:500',
        ]);
        $this->reloadFinancialClaims($actor);
        abort_unless(collect($this->financialRecoveryHistory)->contains(
            fn($row):bool=>$row['id']===$this->financialRecoveryReversalReceiptId &&
                $row['decision']==='confirmed' && $row['reversalId']===null &&
                $row['reviewedBy']!==(int)$actor->getKey()),403);
        try {
            $this->lastFinancialRecoveryReference=app(\App\Services\HallInventoryFinancialRecoveryService::class)
                ->reverse($actor,$this->companyId,$this->financialRecoveryReversalReceiptId,
                    $this->financialRecoveryReversalId,$this->financialRecoveryReversalReason);
        }catch(InvalidArgumentException|ConflictHttpException $e) {
            throw ValidationException::withMessages(['financialRecoveryReversalReason'=>$e->getMessage()]);
        }
        $this->financialRecoveryReversalId=(string)Str::uuid();
        $this->financialRecoveryReversalReceiptId='';
        $this->financialRecoveryReversalReason='';
        $this->reloadFinancialClaims($actor);
    }

    public function closeNoncleanFinalLoan():void
    {
        $actor=$this->authorizeSelection();
        abort_unless($this->canManageFinancialClaims() &&
            config('inventory_controls.loan_nonclean_final_closure_enabled') &&
            $this->financialClaimLoanId!=='' && Str::isUuid($this->financialClaimLoanId),403);
        $this->reloadFinancialClaims($actor);
        abort_unless(count($this->noncleanReconciliation)>0,403);
        try {
            app(\App\Services\HallInventoryNoncleanFinalClosureService::class)->close(
                $actor,$this->companyId,$this->hallId,$this->financialClaimLoanId,
                (string)Str::uuid());
        }catch(InvalidArgumentException|ConflictHttpException $e){
            throw ValidationException::withMessages(['noncleanFinalClosure'=>$e->getMessage()]);
        }
        $this->reloadFinancialClaims($actor);
        $this->reloadLoanHistory();
    }

    public function downloadNoncleanFinalLoanPdf():\Symfony\Component\HttpFoundation\StreamedResponse
    {
        $actor=$this->authorizeSelection();
        abort_unless($this->canManageFinancialClaims() &&
            config('inventory_controls.loan_nonclean_final_closure_enabled') &&
            $this->financialClaimLoanId!=='' && Str::isUuid($this->financialClaimLoanId),403);
        $bytes=app(\App\Services\HallInventoryNoncleanFinalClosureService::class)
            ->receiptPdf($actor,$this->companyId,$this->hallId,$this->financialClaimLoanId);
        return response()->streamDownload(static function()use($bytes):void {echo $bytes;},
            'venue-inventory-nonclean-final-'.$this->financialClaimLoanId.'.pdf',[
                'Content-Type'=>'application/pdf','Cache-Control'=>'private, no-store',
                'X-Content-Type-Options'=>'nosniff'
            ]);
    }

    public function canManageSourceCleanClosure():bool
    {
        if(!config('inventory_controls.loan_clean_closure_enabled'))return false;
        $actor=auth()->user();
        $hall=Hall::query()->where('company_id',$this->companyId)
            ->where('id',$this->hallId)->where('is_active',true)->first();
        return $actor instanceof User && $hall!==null &&
            app(TenantAccess::class)->canConfigureHall($actor,$hall);
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
