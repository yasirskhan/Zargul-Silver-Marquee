<x-filament-panels::page>
    <div class="space-y-6">
        <p class="text-sm text-gray-600 dark:text-gray-300">
            Hall-owned inventory catalog and witnessed physical count records.
            Creating an item does not purchase inventory or change owned stock.
            Counts are audit evidence awaiting independent reconciliation, NOT verified balance changes.
        </p>

        <div class="grid gap-4 md:grid-cols-2">
            <label class="block space-y-2">
                <span class="text-sm font-medium">Operating company</span>
                <select wire:model.live="companyId" class="w-full rounded-lg border p-3 text-gray-900">
                    @foreach($this->companyOptions() as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block space-y-2">
                <span class="text-sm font-medium">Individual hall (inventory owner)</span>
                <select wire:model.live="hallId" class="w-full rounded-lg border p-3 text-gray-900">
                    @foreach($this->hallOptions() as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </label>
        </div>

        <section class="space-y-3 rounded-xl border p-4" aria-label="Hall inventory item catalog">
            <h2 class="text-lg font-semibold">Item catalog for selected hall</h2>
            <p class="text-xs text-gray-600 dark:text-gray-300">An item listed below has no verified quantity until the separate stock movement/reconciliation workflow is enabled.</p>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[450px] border-collapse text-left text-sm">
                    <thead class="bg-gray-100 text-gray-900"><tr>
                        <th scope="col" class="p-2">Item</th>
                        <th scope="col" class="p-2">Category</th>
                        <th scope="col" class="p-2">Unit</th>
                        <th scope="col" class="p-2">Minimum target</th>
                    </tr></thead>
                    <tbody>
                        @forelse($catalog as $item)
                            <tr class="border-t" wire:key="stock-item-{{ $item['id'] }}">
                                <td class="p-2">{{ $item['name'] }}</td>
                                <td class="p-2">{{ $item['category'] }}</td>
                                <td class="p-2">{{ $item['unit'] }}</td>
                                <td class="p-2">{{ $item['minimumQuantity'] }}</td>
                            </tr>
                        @empty
                            <tr><td class="p-3 text-gray-600" colspan="4">No inventory catalog items have been created for this hall yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <button type="button" wire:click="reloadCatalog" class="rounded-lg border px-3 py-2 text-sm">Refresh items</button>
        </section>


        @if(config('inventory_controls.position_reports_enabled'))
            <section class="space-y-3 rounded-xl border p-4" aria-label="Posted inventory book balances">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-lg font-semibold">Hall stock balances and minimum-stock warnings</h2>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" wire:click="reloadPositionReport"
                            class="rounded-lg border px-3 py-2 text-sm">Refresh book balances</button>
                        <button type="button" wire:click="downloadPositionReportCsv"
                            class="rounded-lg border px-3 py-2 text-sm">Download hall book CSV</button>
                    </div>
                </div>
                <p class="text-xs text-gray-600 dark:text-gray-300">
                    These are posted BOOK balances, not a fresh verified physical count or a financial valuation.
                    A catalog item without matching immutable movement history is not confirmed stock.
                    Minimum warnings use owned usable units only. Borrowed stock is never treated as owned.
                    Up to 100 active items are shown for the selected hall.
                </p>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[950px] text-left text-sm">
                        <thead class="bg-gray-100 text-gray-900"><tr>
                            <th scope="col" class="p-2">Item</th>
                            <th scope="col" class="p-2">Owned usable</th>
                            <th scope="col" class="p-2">Owned on loan</th>
                            <th scope="col" class="p-2">Owned damaged</th>
                            <th scope="col" class="p-2">Borrowed usable</th>
                            <th scope="col" class="p-2">Borrowed transit</th>
                            <th scope="col" class="p-2">Borrowed damaged</th>
                            <th scope="col" class="p-2">Minimum owned</th>
                            <th scope="col" class="p-2">Book status</th>
                        </tr></thead>
                        <tbody>
                            @forelse($positionReport as $balance)
                                <tr class="border-t" wire:key="position-book-{{ $balance['itemId'] }}">
                                    <td class="p-2">{{ $balance['name'] }} ({{ $balance['unit'] }})</td>
                                    <td class="p-2">{{ $balance['ownedUsable'] ?? 'Not posted' }}</td>
                                    <td class="p-2">{{ $balance['ownedOnLoan'] ?? 'Not posted' }}</td>
                                    <td class="p-2">{{ $balance['ownedDamaged'] ?? 'Not posted' }}</td>
                                    <td class="p-2">{{ $balance['borrowedUsable'] ?? 'Not posted' }}</td>
                                    <td class="p-2">{{ $balance['borrowedTransit'] ?? 'Not posted' }}</td>
                                    <td class="p-2">{{ $balance['borrowedDamaged'] ?? 'Not posted' }}</td>
                                    <td class="p-2">{{ $balance['minimum'] }}</td>
                                    <td class="p-2">
                                        @if($balance['status']==='BELOW_MINIMUM')
                                            <span class="font-semibold text-amber-700">LOW OWNED STOCK / REPLENISHMENT REVIEW</span>
                                        @elseif($balance['status']==='NO_POSTED_MOVEMENT')
                                            No posted movement
                                        @elseif($balance['status']==='RECONCILIATION_REQUIRED')
                                            BOOK INTEGRITY REVIEW REQUIRED
                                        @else
                                            Posted book balance
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="9" class="p-3">No active catalog items for this hall.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        @if(config('inventory_controls.purchase_receipts_enabled'))
            <section class="space-y-3 rounded-xl border p-4" aria-label="Purchased stock request and receipt history">
                <h2 class="text-lg font-semibold">Purchased stock: receiving requested</h2>
                <p class="text-sm">Record a purchasing claim for independent physical receiving. An invoice or reference here is NOT verified payment or proof of delivered goods. Saving this request posts no owned inventory or financial journal.</p>
                <form wire:submit="submitPurchaseRequest" class="grid gap-3 rounded-lg border p-3 md:grid-cols-2">
                    <label class="space-y-1"><span class="text-sm">Hall inventory item</span>
                        <select wire:model="purchaseItemId" class="w-full rounded-lg border p-2 text-gray-900" required>
                            <option value="">Select item</option>
                            @foreach($catalog as $item)
                                <option value="{{ $item['id'] }}">{{ $item['name'] }} ({{ $item['unit'] }})</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="space-y-1"><span class="text-sm">Quantity delivered or expected</span>
                        <input wire:model="purchaseQuantity" type="text" pattern="[1-9][0-9]*" class="w-full rounded-lg border p-2 text-gray-900" required>
                    </label>
                    <label class="space-y-1"><span class="text-sm">Supplier / vendor name</span>
                        <input wire:model="purchaseVendor" type="text" maxlength="200" class="w-full rounded-lg border p-2 text-gray-900" required>
                    </label>
                    <label class="space-y-1"><span class="text-sm">Supplier document reference</span>
                        <input wire:model="purchaseVendorReference" type="text" maxlength="160" class="w-full rounded-lg border p-2 text-gray-900" required>
                    </label>
                    <label class="space-y-1 md:col-span-2"><span class="text-sm">Evidence reference / document fingerprint (not authenticated)</span>
                        <input wire:model="purchaseEvidence" type="text" maxlength="300" class="w-full rounded-lg border p-2 text-gray-900" required>
                    </label>
                    <label class="space-y-1 md:col-span-2"><span class="text-sm">Receiving request reason</span>
                        <input wire:model="purchaseReason" type="text" maxlength="500" class="w-full rounded-lg border p-2 text-gray-900" required>
                    </label>
                    @error('purchaseItemId') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
                    @error('purchaseQuantity') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
                    <button type="submit" class="rounded-lg border px-3 py-2 text-sm">Submit unverified purchase claim</button>
                </form>
                @if($lastPurchaseReference !== null)
                    <p role="status" class="text-sm">Purchase claim saved: {{ $lastPurchaseReference }}. No inventory or financial posting.</p>
                @endif

                <form wire:submit="recordPhysicalPurchaseReceiving" class="space-y-2 rounded-lg border p-3" aria-label="Record physical purchase receiving">
                    <h3 class="font-semibold">Record independently counted delivery</h3>
                    <p class="text-xs">Only a different authorized person can record the physical count. A typed evidence reference is not authenticated proof. No stock or money is posted.</p>
                    <label class="block text-sm">Purchase claim
                        <select wire:model="receivingPurchaseId" class="w-full rounded-lg border p-2 text-gray-900">
                            <option value="">Choose pending purchase</option>
                            @foreach($purchaseHistory as $purchase)
                                @if($purchase['receivingEvidenceId'] === null && $purchase['requestedBy'] !== (int)\Filament\Facades\Filament::auth()->id())
                                    <option value="{{ $purchase['id'] }}">{{ $purchase['itemName'] }} ({{ $purchase['quantity'] }})</option>
                                @endif
                            @endforeach
                        </select>
                    </label>
                    <label class="block text-sm">Quantity physically counted
                        <input wire:model="receivingQuantity" type="text" pattern="[1-9][0-9]*" class="w-full rounded-lg border p-2 text-gray-900">
                    </label>
                    <label class="block text-sm">Physical evidence reference (not verified)
                        <input wire:model="receivingEvidenceRef" type="text" maxlength="300" class="w-full rounded-lg border p-2 text-gray-900">
                    </label>
                    <label class="block text-sm">Condition notes
                        <input wire:model="receivingCondition" type="text" maxlength="500" class="w-full rounded-lg border p-2 text-gray-900">
                    </label>
                    @error('receivingPurchaseId') <p role="alert">{{ $message }}</p> @enderror
                    <button type="submit" class="rounded-lg border px-3 py-2 text-sm">Record physical receiving assertion only</button>
                </form>
                @if($lastReceivingReference !== null)
                    <p role="status" class="text-xs">Receiving record {{ $lastReceivingReference }} saved. No owned stock posted.</p>
                @endif
                @if($this->canReviewPurchaseReceiving())
                    <form wire:submit="independentlyReviewPurchaseReceiving" class="space-y-2 rounded-lg border p-3" aria-label="Independently review physical receiving">
                        <h3 class="font-semibold">Owner/admin independent receiving review</h3>
                        <p class="text-xs">Must be a third actor, neither the requester nor receiver. Review is not final stock acceptance or payment.</p>
                        <label class="block text-sm">Receiving assertion
                            <select wire:model="reviewReceiptId" class="w-full rounded-lg border p-2 text-gray-900">
                                <option value="">Choose pending review</option>
                                @foreach($purchaseHistory as $purchase)
                                    @if($purchase['receivingEvidenceId'] !== null && $purchase['independentReviewId'] === null
                                        && $purchase['requestedBy'] !== (int)\Filament\Facades\Filament::auth()->id()
                                        && $purchase['receivedBy'] !== (int)\Filament\Facades\Filament::auth()->id())
                                        <option value="{{ $purchase['receivingEvidenceId'] }}">{{ $purchase['itemName'] }} (counted {{ $purchase['countedQuantity'] }})</option>
                                    @endif
                                @endforeach
                            </select>
                        </label>
                        <label class="block text-sm">Review notes
                            <input wire:model="reviewReceiptNotes" type="text" maxlength="500" class="w-full rounded-lg border p-2 text-gray-900">
                        </label>
                        @error('reviewReceiptId') <p role="alert">{{ $message }}</p> @enderror
                        <button type="submit" class="rounded-lg border px-3 py-2 text-sm">Record independent review only</button>
                    </form>
                    @if($lastReceivingReviewReference !== null)
                        <p role="status" class="text-xs">Review {{ $lastReceivingReviewReference }} saved. No inventory or money posted.</p>
                    @endif
                @endif

                @if(config('inventory_controls.purchase_evidence_upload_enabled'))
                    <form wire:submit="uploadPurchaseEvidence" class="space-y-2 rounded-lg border p-3" aria-label="Upload private purchase and receiving evidence">
                        <h3 class="font-semibold">Attach original evidence privately</h3>
                        <p class="text-xs">Only the original requester may attach the supplier document; only the independent receiver may attach the receiving photo. PDF/JPEG/PNG, at most 5 MB. Uploaded bytes are not independently authenticated supplier or payment proof.</p>
                        <label class="block text-sm">Purchase claim
                            <select wire:model="purchaseUploadPurchaseId" class="w-full rounded-lg border p-2 text-gray-900">
                                <option value="">Select purchase</option>
                                @foreach($purchaseHistory as $purchase)
                                    <option value="{{ $purchase['id'] }}">{{ $purchase['itemName'] }} - {{ $purchase['id'] }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block text-sm">Evidence type
                            <select wire:model="purchaseUploadKind" class="w-full rounded-lg border p-2 text-gray-900">
                                <option value="supplier_document">Supplier document (requester)</option>
                                <option value="receiving_photo">Receiving photo (receiver, JPEG/PNG)</option>
                            </select>
                        </label>
                        <label class="block text-sm">Original file
                            <input type="file" wire:model="purchaseUploadFile" accept=".pdf,.jpg,.jpeg,.png" class="w-full rounded-lg border p-2">
                        </label>
                        @error('purchaseUploadFile') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
                        <button type="submit" class="rounded-lg border px-3 py-2 text-sm">Save private evidence</button>
                        @if($lastPurchaseUploadReference)
                            <p role="status" class="text-xs">Private evidence saved: {{ $lastPurchaseUploadReference }}. No stock or payment was posted.</p>
                        @endif
                    </form>
                @endif
                <h3 class="font-semibold">Purchased stock claim history</h3>
                @forelse($purchaseHistory as $purchase)
                    <article class="rounded-lg border p-3" wire:key="purchase-claim-{{ $purchase['id'] }}">
                        <p class="font-semibold">{{ $purchase['itemName'] }}: {{ $purchase['quantity'] }} {{ $purchase['unit'] }}</p>
                        <p class="text-sm">{{ $purchase['status'] }}</p>
                        <p class="text-xs">Vendor: {{ $purchase['vendorName'] }} / {{ $purchase['vendorReference'] }}</p>
                        <p class="text-xs">Evidence reference (unverified): {{ $purchase['evidenceReference'] }}</p>
                        <p class="text-xs">Claim ID: {{ $purchase['id'] }}</p>
                        @if(config('inventory_controls.purchase_evidence_upload_enabled') && $this->canReviewPurchaseReceiving())
                            @foreach($purchase['privateAttachments'] ?? [] as $attachment)
                                <a href="{{ route('inventory.purchase-evidence.download', [
                                    'companyId'=>$companyId, 'hallId'=>$hallId,
                                    'purchaseId'=>$purchase['id'], 'fileId'=>$attachment['id']
                                ]) }}" class="text-sm underline">
                                    Download {{ str_replace('_', ' ', $attachment['kind']) }} (private, unverified)
                                </a>
                            @endforeach
                        @endif
                        @if(config('inventory_controls.purchase_stock_posting_enabled')
                            && $this->canReviewPurchaseReceiving()
                            && !$purchase['ownedStockPosted']
                            && $purchase['independentReviewId'] !== null
                            && $purchase['reviewedBy'] === (int)\Filament\Facades\Filament::auth()->id())
                            <button type="button" wire:click="acceptPurchasedStock('{{ $purchase['id'] }}', {{ $purchase['positionRevision'] }})"
                                class="rounded-lg border px-3 py-2 text-sm">
                                Accept purchased stock (no financial entry)
                            </button>
                        @endif

                        <p class="text-xs">Owned inventory posted: {{ $purchase['ownedStockPosted'] ? 'YES' : 'NO' }}. Financially posted: NO.</p>
                    </article>
                @empty
                    <p>No purchase receipt claims exist for this hall.</p>
                @endforelse
                <button type="button" wire:click="reloadPurchaseHistory" class="rounded-lg border px-3 py-2 text-sm">Refresh purchase claims</button>
            </section>
        @endif

        @if(config('inventory_controls.opening_stock_posting_enabled'))
            <section class="space-y-3 rounded-xl border p-4" aria-label="Opening stock independent review">
                <h2 class="text-lg font-semibold">Opening stock: independent review</h2>
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    A saved physical opening count alone is NOT owned stock.
                    A different authorized owner/admin must accept its evidence;
                    no invoice, purchase or payment is created here.
                </p>
                <form wire:submit="submitOpeningRequest" class="space-y-3 rounded-lg border p-3">
                    <h3 class="font-semibold">Request independent verification of opening stock</h3>
                    <p class="text-xs">First record an OWNED, zero-damage opening physical count for the same hall.
                        This request cannot post stock until a different authorized owner/admin accepts.</p>
                    <label class="block space-y-1"><span class="text-sm">Catalog item</span>
                        <select wire:model="openingItemId" class="w-full rounded-lg border p-2 text-gray-900">
                            <option value="">Choose item</option>
                            @foreach($catalog as $item)
                                <option value="{{ $item['id'] }}">{{ $item['name'] }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block space-y-1"><span class="text-sm">Opening physical count reference (UUID)</span>
                        <input wire:model="openingStocktakeId" type="text" maxlength="36"
                            class="w-full rounded-lg border p-2 text-gray-900" required>
                    </label>
                    <label class="block space-y-1"><span class="text-sm">Good-condition item quantity</span>
                        <input wire:model="openingQuantity" type="text" pattern="[1-9][0-9]*"
                            class="w-full rounded-lg border p-2 text-gray-900" required>
                    </label>
                    <label class="block space-y-1"><span class="text-sm">Reason and evidence notes</span>
                        <input wire:model="openingReason" type="text" maxlength="500"
                            class="w-full rounded-lg border p-2 text-gray-900" required>
                    </label>
                    <button type="submit" class="rounded-lg border px-3 py-2 text-sm">
                        Request separate approval (no stock posted)
                    </button>
                </form>
                @if($lastOpeningReference !== null)
                    <p class="text-sm">Opening evidence request saved: {{ $lastOpeningReference }}</p>
                @endif
                <div class="space-y-3">
                    @forelse($openingHistory as $opening)
                        <article class="rounded-lg border p-3" wire:key="opening-evidence-{{ $opening['id'] }}">
                            <h3 class="font-semibold">{{ $opening['itemName'] }}</h3>
                            <p>{{ $opening['quantity'] }} {{ $opening['unit'] }} — {{ $opening['status'] }}</p>
                            <p class="text-xs">Physical count evidence: {{ $opening['physicalCountId'] }}</p>
                            <p class="text-xs">Reason: {{ $opening['reason'] }}</p>
                            @if($opening['movementId'] !== null)
                                <p class="text-xs">Immutable movement: {{ $opening['movementId'] }}</p>
                            @else
                                <p class="text-xs font-semibold">PENDING: no owned quantity has been posted.</p>
                                @if($this->canApproveOpening() && $opening['requestedBy'] !== (int) \Filament\Facades\Filament::auth()->id())
                                    <button type="button" wire:click="acceptOpeningRequest('{{ $opening['id'] }}')"
                                        class="rounded-lg border px-3 py-2 text-sm">
                                        Accept verified opening stock
                                    </button>
                                @endif
                            @endif
                            <p class="text-xs">Financial posting: NONE</p>
                        </article>
                    @empty
                        <p>No opening-count requests have been submitted for this hall.</p>
                    @endforelse
                </div>
                <button type="button" wire:click="reloadOpeningHistory"
                    class="rounded-lg border px-3 py-2 text-sm">Refresh opening review</button>
            </section>
        @endif

        <section class="space-y-4 rounded-xl border p-4" aria-label="Create hall inventory catalog item">
            <h2 class="text-lg font-semibold">Add a new item to this hall</h2>
            <div class="grid gap-3 md:grid-cols-2">
                <label class="block space-y-1"><span class="text-sm">Item name</span>
                    <input type="text" maxlength="160" wire:model="itemName" class="w-full rounded-lg border p-3 text-gray-900" placeholder="e.g. Water Jugs">
                </label>
                <label class="block space-y-1"><span class="text-sm">Category</span>
                    <select wire:model="itemCategory" class="w-full rounded-lg border p-3 text-gray-900">
                        @foreach(['Furniture', 'Crockery', 'Cutlery', 'Glassware', 'Serving', 'Decoration', 'Equipment', 'Consumables', 'Other'] as $category)
                            <option value="{{ $category }}">{{ $category }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block space-y-1"><span class="text-sm">Unit</span>
                    <select wire:model="itemUnit" class="w-full rounded-lg border p-3 text-gray-900">
                        @foreach(['piece', 'set', 'pair', 'kg', 'liter'] as $unit)
                            <option value="{{ $unit }}">{{ $unit }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block space-y-1"><span class="text-sm">Minimum quantity target (not current stock)</span>
                    <input type="number" min="0" max="100000000" wire:model="minimumQuantity" class="w-full rounded-lg border p-3 text-gray-900">
                </label>
            </div>
            <label class="block space-y-1"><span class="text-sm">Reason for creating item</span>
                <input type="text" maxlength="500" wire:model="createReason" class="w-full rounded-lg border p-3 text-gray-900" placeholder="Opening catalog for Hall A">
            </label>
            @error('itemName') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
            @error('minimumQuantity') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
            <button type="button" wire:click="createItem" class="rounded-lg bg-emerald-800 px-4 py-2 text-sm font-medium text-white">
                Add hall item
            </button>
        </section>

        @if(config('inventory_controls.stocktakes_enabled'))
            <section class="space-y-4 rounded-xl border p-4" aria-label="Physical inventory stocktake">
                <h2 class="text-lg font-semibold">Count physical inventory</h2>
                <p class="text-sm text-gray-600 dark:text-gray-300">Add separate rows for owned and temporarily borrowed stock. This saves count evidence only; shortages and new purchases require later authorized reconciliation.</p>
                <label class="block space-y-1"><span class="text-sm">Audit type</span>
                    <select wire:model="auditKind" class="w-full rounded-lg border p-3 text-gray-900">
                        <option value="ad_hoc">Additional count</option>
                        <option value="opening">Opening count</option>
                        <option value="post_event">After event</option>
                        <option value="monthly">Monthly</option>
                        <option value="quarterly">Quarterly</option>
                    </select>
                </label>
                @foreach($countRows as $i => $row)
                    <div class="grid gap-2 rounded-lg bg-gray-100 p-3 text-gray-900 md:grid-cols-5" wire:key="count-line-{{ $row['key'] }}">
                        <label class="block space-y-1 md:col-span-2"><span class="text-xs">Item</span>
                            <select wire:model="countRows.{{ $i }}.itemId" class="w-full rounded-lg border p-2 text-gray-900">
                                <option value="">Select item</option>
                                @foreach($catalog as $item)
                                    <option value="{{ $item['id'] }}">{{ $item['name'] }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block space-y-1"><span class="text-xs">Owned or borrowed</span>
                            <select wire:model="countRows.{{ $i }}.ownership" class="w-full rounded-lg border p-2 text-gray-900">
                                <option value="owned">Owned</option><option value="borrowed">Borrowed</option>
                            </select>
                        </label>
                        <label class="block space-y-1"><span class="text-xs">Usable</span>
                            <input type="number" min="0" wire:model="countRows.{{ $i }}.usableCount" class="w-full rounded-lg border p-2 text-gray-900">
                        </label>
                        <label class="block space-y-1"><span class="text-xs">Damaged</span>
                            <input type="number" min="0" wire:model="countRows.{{ $i }}.damagedCount" class="w-full rounded-lg border p-2 text-gray-900">
                        </label>
                        <div class="md:col-span-5"><button type="button" wire:click="removeCountRow('{{ $row['key'] }}')" class="text-sm underline">Remove row</button></div>
                    </div>
                @endforeach
                <button type="button" wire:click="addCountRow" class="rounded-lg border px-4 py-2 text-sm">
                    + Add item row
                </button>
                <label class="block space-y-1"><span class="text-sm">Count reason / observations</span>
                    <input type="text" wire:model="auditReason" maxlength="500" class="w-full rounded-lg border p-3 text-gray-900">
                </label>
                @error('countRows') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
                <button type="button" wire:click="submitCount" class="rounded-lg bg-emerald-800 px-4 py-2 text-sm font-medium text-white">
                    Save physical count evidence
                </button>
                @if($lastCountReference)
                    <p role="status" class="rounded-lg bg-emerald-100 p-3 text-sm text-emerald-900">
                        Count submitted for review. Reference {{ $lastCountReference }}. Inventory balances were not adjusted.
                    </p>
                @endif
                <section class="space-y-3 rounded-xl border p-4" aria-label="Hall physical count history">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h3 class="font-semibold">Physical count history (evidence only)</h3>
                        <button type="button" wire:click="reloadStocktakeHistory" class="rounded-lg border px-3 py-2 text-sm">Refresh count history</button>
                    </div>
                    <p class="text-xs text-gray-600 dark:text-gray-300">Review the selected hall's last 20 immutable counts. Owned and borrowed quantities are separate. No discrepancy has been approved or book balance adjusted.</p>
                    @forelse($stocktakeHistory as $record)
                        <article wire:key="stocktake-history-{{ $record['id'] }}" class="space-y-2 rounded-lg border p-3 text-sm">
                            <p class="font-medium">{{ str_replace('_',' ',$record['auditKind']) }} — UNRECONCILED PHYSICAL COUNT</p>
                            <p>Reference {{ $record['id'] }}. Counted at {{ $record['countedAt'] }}. {{ $record['reason'] }}</p>
                            <ul class="list-inside list-disc">
                                @foreach($record['lines'] as $line)
                                    <li>{{ $line['name'] }} ({{ $line['ownership'] }}): {{ $line['usable'] }} usable, {{ $line['damaged'] }} damaged — {{ $line['unit'] }}</li>
                                @endforeach
                            </ul>
                            @if(config('inventory_controls.stocktake_variance_enabled') &&
                                config('inventory_controls.position_reports_enabled'))
                                <button type="button" wire:click="viewStocktakeVariance('{{ $record['id'] }}')"
                                    class="rounded-lg border px-3 py-2 text-sm">Preview count-to-book variance</button>
                            @endif
                        </article>
                    @empty
                        <p class="text-sm text-gray-600 dark:text-gray-300">No saved physical counts for this hall yet.</p>
                    @endforelse
                </section>
                @if(config('inventory_controls.stocktake_variance_enabled') && $varianceStocktakeId !== '')
                    <section class="rounded-xl border p-4 space-y-2" aria-label="Immutable physical count variance preview">
                        <h3 class="font-semibold">Physical versus recorded book snapshot</h3>
                        <p class="text-xs">Count {{ $varianceStocktakeId }}. The book baseline was captured at count submission, not taken from today's balance.
                            Missing or inconsistent historical baselines cannot produce a trusted variance. This preview neither posts stock nor settles money.</p>
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[760px] text-left text-sm">
                                <thead><tr><th class="p-2">Item / ownership</th><th class="p-2">Counted usable</th>
                                    <th class="p-2">Book usable at count</th><th class="p-2">Usable variance</th>
                                    <th class="p-2">Counted damaged</th><th class="p-2">Book damaged</th>
                                    <th class="p-2">Damaged variance</th><th class="p-2">Review state</th><th class="p-2">Condition action</th></tr></thead>
                                <tbody>
                                @foreach($stocktakeVariance as $line)
                                    <tr class="border-t" wire:key="variance-preview-{{ $line['itemId'] }}-{{ $line['ownership'] }}">
                                        <td class="p-2">{{ $line['name'] }} ({{ $line['ownership'] }}, {{ $line['unit'] }})</td>
                                        <td class="p-2">{{ $line['countedUsable'] }}</td>
                                        <td class="p-2">{{ $line['bookUsable'] ?? 'No valid baseline' }}</td>
                                        <td class="p-2">{{ $line['usableVariance'] ?? 'Not available' }}</td>
                                        <td class="p-2">{{ $line['countedDamaged'] }}</td>
                                        <td class="p-2">{{ $line['bookDamaged'] ?? 'No valid baseline' }}</td>
                                        <td class="p-2">{{ $line['damagedVariance'] ?? 'Not available' }}</td>
                                        <td class="p-2">{{ $line['adjustmentPosted'] ? 'OWNED CONDITION RECLASSIFIED' : ($line['requiresReview'] ? 'REVIEW REQUIRED' : 'MATCHED BOOK SNAPSHOT') }}</td>
                                        <td class="p-2">
                                            @if($line['reclassificationEligible'] && $this->canIndependentlyReviewSelectedVariance())
                                                <button type="button" wire:click="reclassifyStocktakeDamage('{{ $line['lineId'] }}')"
                                                    class="rounded-lg border px-2 py-1">Reclassify owned condition</button>
                                            @elseif($line['adjustmentPosted'])
                                                Reclassification recorded
                                            @else
                                                Not eligible
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                        @if(collect($stocktakeVariance)->contains(fn($row) => $row['reviewAcknowledged']))
                            <p class="text-sm">An independent reviewer recorded the discrepancy.
                                @if(collect($stocktakeVariance)->contains(fn($row) => $row['adjustmentPosted']))
                                    A separately approved owned usable/damaged condition correction was posted, conserving total owned units. Other discrepancies may remain unresolved. No financial journal was posted.
                                @else
                                    Investigation and any separately approved correction remain outstanding. No stock or finance posting was made.
                                @endif
                            </p>
                        @endif
                        @if($this->canIndependentlyReviewSelectedVariance() &&
                            collect($stocktakeVariance)->contains(fn($row) => $row['requiresReview']) &&
                            !collect($stocktakeVariance)->contains(fn($row) => $row['reviewAcknowledged']))
                            <div class="space-y-2 rounded-lg border p-3">
                                <p class="text-sm">Independent variance review is an audit acknowledgment only. It does not approve stock write-offs, resolve discrepancies or settle money.</p>
                                <label class="block space-y-1">
                                    <span class="text-sm">Reason for reviewing physical discrepancy</span>
                                    <textarea wire:model="varianceReviewReason" maxlength="500"
                                        class="w-full rounded-lg border p-2 text-gray-900"></textarea>
                                </label>
                                @error('varianceReviewReason') <p role="alert">{{ $message }}</p> @enderror
                                <button type="button" wire:click="acknowledgeStocktakeVariance"
                                    class="rounded-lg border px-3 py-2 text-sm">Record independent variance review</button>
                            </div>
                        @endif
                        @if(config('inventory_controls.variance_reclassification_enabled') &&
                            collect($stocktakeVariance)->contains(fn($row) => $row['reclassificationEligible']))
                            <div class="space-y-2 rounded-lg border p-3">
                                <p class="text-sm">Only independently reviewed, same-total owned condition correction can post. Missing stock, gains, loans and money require separate authorization.</p>
                                <label class="block space-y-1"><span class="text-sm">Reason for independently reviewed condition correction</span>
                                    <textarea wire:model="varianceAdjudicationReason" maxlength="500"
                                        class="w-full rounded-lg border p-2 text-gray-900"></textarea>
                                </label>
                                @error('varianceAdjudicationReason') <p role="alert">{{ $message }}</p> @enderror
                            </div>
                        @endif
                        @if($lastVarianceReclassificationReference)
                            <p role="status">Owned condition correction recorded: {{ $lastVarianceReclassificationReference }}. Total owned unchanged. No financial journal posted.</p>
                        @endif
                        @if($lastVarianceReviewReference)
                            <p role="status">Independent review recorded: {{ $lastVarianceReviewReference }}. Stock and finance unchanged.</p>
                        @endif
                    </section>
                @endif
            </section>
        @endif

        @if(config('inventory_controls.loan_drafts_enabled'))
            <section class="space-y-4 rounded-xl border p-4" aria-label="Multi-item internal or external borrowing request">
                <h2 class="text-lg font-semibold">Borrow / lend multiple inventory items</h2>
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    Saving the request does not move inventory. Physical source dispatch,
                    independent receiving, partial returns and final acknowledgment
                    are separate controlled steps available after the draft.
                </p>
                <div class="grid gap-3 md:grid-cols-2">
                    <label class="block space-y-1"><span class="text-sm">Borrowing transaction type</span>
                        <select wire:model.live="loanDirection" class="w-full rounded-lg border p-3 text-gray-900">
                            <option value="internal">Lend from this hall to another hall</option>
                            <option value="external_in">Borrow from outside into this hall</option>
                            <option value="external_out">Lend from this hall to an outside establishment</option>
                        </select>
                    </label>
                    <label class="block space-y-1"><span class="text-sm">Expected return date</span>
                        <input type="date" wire:model="loanReturnDate" class="w-full rounded-lg border p-3 text-gray-900">
                    </label>
                </div>
                @if($loanDirection === 'internal')
                    <label class="block space-y-1">
                        <span class="text-sm">Receiving hall in this establishment</span>
                        <select wire:model="loanCounterpartHallId" class="w-full rounded-lg border p-3 text-gray-900">
                            <option value="">Select receiving hall</option>
                            @foreach($this->otherHallOptions() as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </label>
                @else
                    <div class="grid gap-3 md:grid-cols-2">
                        <label class="block space-y-1"><span class="text-sm">Outside hall / establishment</span>
                            <input wire:model="loanOutsideName" type="text" maxlength="160" placeholder="e.g. Royal Marriage Hall"
                                class="w-full rounded-lg border p-3 text-gray-900">
                        </label>
                        <label class="block space-y-1"><span class="text-sm">Contact person</span>
                            <input wire:model="loanOutsideContact" type="text" maxlength="240"
                                class="w-full rounded-lg border p-3 text-gray-900">
                        </label>
                        <label class="block space-y-1"><span class="text-sm">Contact phone</span>
                            <input wire:model="loanOutsidePhone" type="text" maxlength="100"
                                class="w-full rounded-lg border p-3 text-gray-900">
                        </label>
                        <label class="block space-y-1"><span class="text-sm">Outside address (optional)</span>
                            <input wire:model="loanOutsideAddress" type="text" maxlength="500"
                                class="w-full rounded-lg border p-3 text-gray-900">
                        </label>
                    </div>
                @endif
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    Add as many different items as required. Choose an existing catalog
                    item or create a new item directly within its row without changing owned stock.
                </p>
                @foreach($loanRows as $i => $row)
                    <div class="grid gap-3 rounded-lg bg-gray-100 p-3 text-gray-900 md:grid-cols-5"
                        wire:key="loan-line-{{ $row['key'] }}">
                        <label class="block space-y-1 md:col-span-3"><span class="text-xs">Inventory item {{ $i + 1 }}</span>
                            <select wire:model="loanRows.{{ $i }}.itemId" class="w-full rounded-lg border p-2 text-gray-900">
                                <option value="">Select an existing item</option>
                                @foreach($catalog as $item)
                                    <option value="{{ $item['id'] }}">{{ $item['name'] }} — {{ $item['unit'] }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block space-y-1"><span class="text-xs">Quantity</span>
                            <input wire:model="loanRows.{{ $i }}.quantity" type="number" min="1"
                                class="w-full rounded-lg border p-2 text-gray-900">
                        </label>
                        <div class="flex items-end gap-2">
                            <button type="button" wire:click="openLoanInlineItem('{{ $row['key'] }}')"
                                class="rounded-lg border px-3 py-2 text-sm">+ New item</button>
                            <button type="button" wire:click="removeLoanRow('{{ $row['key'] }}')"
                                class="rounded-lg border px-3 py-2 text-sm">Remove</button>
                        </div>
                        @if($loanInlineRowKey === $row['key'])
                            <div class="space-y-3 rounded-lg border border-emerald-300 bg-white p-4 md:col-span-5"
                                aria-label="Create a new inventory item directly in this borrowing row">
                                <h3 class="font-semibold">Create item for this hall</h3>
                                <p class="text-xs text-gray-600">Creating a catalog item does not purchase stock or increase owned quantities.</p>
                                <div class="grid gap-3 md:grid-cols-2">
                                    <label class="space-y-1"><span class="text-xs">New item name</span>
                                        <input type="text" wire:model="loanInlineName" maxlength="160"
                                            class="w-full rounded-lg border p-2" placeholder="e.g. Dinner Spoons">
                                    </label>
                                    <label class="space-y-1"><span class="text-xs">Category</span>
                                        <select wire:model="loanInlineCategory" class="w-full rounded-lg border p-2">
                                            @foreach(['Furniture', 'Crockery', 'Cutlery', 'Glassware', 'Serving', 'Decoration', 'Equipment', 'Consumables', 'Other'] as $category)
                                                <option value="{{ $category }}">{{ $category }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <label class="space-y-1"><span class="text-xs">Unit</span>
                                        <select wire:model="loanInlineUnit" class="w-full rounded-lg border p-2">
                                            @foreach(['piece', 'set', 'pair', 'kg', 'liter'] as $unit)
                                                <option value="{{ $unit }}">{{ $unit }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <label class="space-y-1"><span class="text-xs">Minimum stock target</span>
                                        <input wire:model="loanInlineMinimumQuantity" type="number" min="0" max="100000000"
                                            class="w-full rounded-lg border p-2">
                                    </label>
                                </div>
                                @error('loanInlineName') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
                                @error('loanInlineMinimumQuantity') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
                                <button type="button" wire:click="saveLoanInlineItem"
                                    class="rounded-lg bg-emerald-800 px-4 py-2 text-sm font-medium text-white">
                                    Save and select this item
                                </button>
                            </div>
                        @endif
                    </div>
                @endforeach
                <button type="button" wire:click="addLoanRow" class="rounded-lg border px-4 py-2 text-sm">
                    + Add Item Row
                </button>
                <label class="block space-y-1"><span class="text-sm">Purpose / linked event details</span>
                    <input wire:model="loanPurpose" type="text" maxlength="500"
                        placeholder="e.g. Additional chairs for evening wedding"
                        class="w-full rounded-lg border p-3 text-gray-900">
                </label>
                @error('loanRows') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
                <button type="button" wire:click="submitLoanDraft"
                    class="rounded-lg bg-emerald-800 px-4 py-2 text-sm font-medium text-white">
                    Save borrowing request (no stock moved)
                </button>
                @if($lastLoanReference)
                    <p role="status" class="rounded-lg bg-amber-100 p-3 text-sm text-amber-900">
                        Multi-item loan request saved: {{ $lastLoanReference }}.
                        No inventory was dispatched or received. Await controlled handover and independent counts.
                    </p>
                @endif
                <div class="space-y-3 rounded-xl border p-4" aria-label="Saved borrowing requests awaiting handover">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h3 class="font-semibold">Borrowing requests and source dispatch status</h3>
                        <button type="button" wire:click="reloadLoanHistory" class="rounded-lg border px-3 py-2 text-sm">Refresh requests</button>
                    </div>
                    <p class="text-xs text-gray-600 dark:text-gray-300">A source-dispatched request means owned stock left the lending hall. Destination received means independent borrowed custody has been recorded, NOT that items were returned. Drafts have not moved stock. No status proves payment or loan completion. Up to 20 requests in the selected hall are shown.</p>
                    <div class="space-y-3">
                        @forelse($loanHistory as $loan)
                            <article wire:key="inventory-loan-summary-{{ $loan['id'] }}" class="space-y-2 rounded-lg border p-3 text-sm">
                                @php
                                    $loanStatus = match($loan['status'] ?? 'draft_only') {
                                        'clean_loan_closed' => 'CLOSED / INDEPENDENT CLEAN RETURN VERIFIED',
                                        'return_acknowledged_outstanding_reconciliation_required' => 'RETURN ACKNOWLEDGED / OUTSTANDING RECONCILIATION REQUIRED',
                                        'partial_return_in_transit_source_ack_pending' => 'PARTIAL RETURN SENT / SOURCE ACK PENDING',
                                        'destination_received_return_pending' => 'DESTINATION RECEIVED / RETURNS PENDING',
                                        'source_dispatched_receipt_pending' => 'SOURCE DISPATCHED / DESTINATION RECEIPT PENDING',
                                        'external_physical_receipt_pending_review' => 'EXTERNAL PHYSICAL RECEIPT / INDEPENDENT REVIEW PENDING',
                                        'external_borrowed_received_return_pending' => 'EXTERNAL BORROWED STOCK RECEIVED / RETURN PENDING',
                                        default => 'DRAFT / NOT DISPATCHED'
                                    };
                                @endphp
                                <p class="font-medium">{{ $loan['purpose'] }} — {{ ($loan['viewRole'] ?? 'originating') === 'receiving' ? 'INCOMING REQUEST' : 'OUTGOING REQUEST' }} — {{ $loanStatus }}</p>
                                <p>Reference: {{ $loan['id'] }}. Return expected: {{ $loan['expectedReturnDate'] }}.</p>
                                @if(config('inventory_controls.loan_incidents_enabled') && $loan['direction'] === 'internal' && $loan['lendingHallId'] === $hallId && $loan['destinationReceiptConfirmed'])
                                    <button type="button" wire:click="startLoanIncident('{{ $loan['id'] }}')" class="rounded-lg border px-3 py-2 text-sm">Review damage, missing or disputed items</button>
                                @endif
                                @if($loan['overdueWithOutstandingCustody'] ?? false)
                                    <p role="status" class="font-semibold text-amber-700 dark:text-amber-300">OVERDUE: dispatched or externally borrowed inventory remains outstanding. Physical reconciliation required; no fee or loss has been assessed.</p>
                                @endif
                                @if(($loan['returnReconciliation'] ?? null) !== null)
                                    <p>Physical custody: received {{ $loan['returnReconciliation']['receivedQuantity'] }},
                                        sent back {{ $loan['returnReconciliation']['shippedQuantity'] }},
                                        still at borrower {{ $loan['returnReconciliation']['notYetShipped'] }},
                                        source acknowledgment pending {{ $loan['returnReconciliation']['sourceAcknowledgmentPending'] }}.</p>
                                    <p>Independently acknowledged: good {{ $loan['returnReconciliation']['sourceAcknowledgedGood'] }},
                                        damaged {{ $loan['returnReconciliation']['sourceAcknowledgedDamaged'] }},
                                        unresolved {{ $loan['returnReconciliation']['sourceUnresolved'] }}.
                                        Total not yet settled {{ $loan['returnReconciliation']['stillUnsettled'] }}.
                                        @if($loan['returnReconciliation']['loanCompleted'])
                                            Independently verified clean loan is CLOSED. This did not post a financial journal.
                                        @else
                                            Loan completion and financial settlement are NOT recorded.
                                        @endif</p>
                                    @if($loan['returnReconciliation']['integrityWarning'])
                                        <p role="alert">Inventory return evidence mismatch; reconciliation is blocked.</p>
                                    @endif
                                @endif
                                <p>Source: {{ $loan['lendingHallName'] ?? $loan['externalPartnerName'] ?? 'Not assigned' }}.
                                   Destination: {{ $loan['borrowingHallName'] ?? $loan['externalPartnerName'] ?? 'Not assigned' }}.</p>
                                <ul class="list-inside list-disc">
                                    @foreach($loan['lines'] as $line)
                                        <li>{{ $line['name'] }} — {{ $line['quantity'] }} {{ $line['unit'] }}</li>
                                    @endforeach
                                </ul>
                                @if(config('inventory_controls.external_borrowed_intake_enabled') &&
                                    $loan['direction']==='external_in' &&
                                    $loan['borrowingHallId']===$hallId)
                                    @if($loan['externalReceiptRecorded'])
                                        <p class="font-medium">Independently witnessed external physical counts</p>
                                        <ul class="list-inside list-disc text-sm">
                                            @foreach($loan['lines'] as $outsideLine)
                                                <li>{{ $outsideLine['name'] }}: physically counted
                                                    {{ $loan['externalCountByItem'][$outsideLine['itemId']] ?? 'MISSING EVIDENCE' }}
                                                    versus requested {{ $outsideLine['quantity'] }} {{ $outsideLine['unit'] }}</li>
                                            @endforeach
                                        </ul>
                                        @if(!$loan['externalCountMatchesDraft'])
                                            <p role="alert">Physical count differs from requested items. Discrepancy investigation required; no borrowed stock can be posted.</p>
                                        @endif
                                    @endif
                                    @if(!$loan['externalReceiptRecorded'] && $loan['createdBy']!==(int)auth()->id())
                                        <button type="button" wire:click="startExternalBorrowedReceipt('{{ $loan['id'] }}')"
                                            class="rounded-lg border px-3 py-2 text-sm">Record witnessed external borrowed delivery</button>
                                    @elseif($loan['externalReceiptRecorded'] && !$loan['externalReceiptAccepted'] &&
                                        $loan['externalCountMatchesDraft'] &&
                                        $this->canIndependentlyAcceptExternalBorrowing())
                                        <button type="button" wire:click="approveExternalBorrowedReceipt('{{ $loan['id'] }}')"
                                            class="rounded-lg border px-3 py-2 text-sm">Independently accept externally borrowed stock</button>
                                    @elseif($loan['externalReceiptAccepted'])
                                        <p class="text-sm">External inventory accepted as BORROWED, not owned. No money posted or loan completed. Physical return is still due.</p>
                                        @if(config('inventory_controls.external_borrowed_return_enabled') &&
                                            $loan['createdBy']!==(int)auth()->id())
                                            <button type="button" wire:click="startExternalBorrowedReturn('{{ $loan['id'] }}')"
                                                class="rounded-lg border px-3 py-2 text-sm">Prepare partial outside partner return</button>
                                        @endif
                                    @endif
                                    @error('externalIntakeFile') <p role="alert">{{ $message }}</p> @enderror
                                @endif
                                @if(config('inventory_controls.loan_dispatch_enabled') &&
                                    $loan['direction']==='internal' &&
                                    $loan['lendingHallId']===$hallId &&
                                    ($loan['status']??'')==='draft_only' &&
                                    $this->canManageSourceInventoryDispatch())
                                    <button type="button" wire:click="startInternalDispatch('{{ $loan['id'] }}')"
                                        class="rounded-lg border px-3 py-2 text-sm">Prepare witnessed source dispatch</button>
                                @endif
                                @if(config('inventory_controls.loan_clean_closure_enabled') &&
                                    $loan['direction']==='internal' &&
                                    $loan['lendingHallId']===$hallId &&
                                    $this->canManageSourceCleanClosure())
                                    @if(isset($cleanLoanReceipts[$loan['id']]))
                                        <p class="font-medium">Immutable clean-return receipt number {{ $cleanLoanReceipts[$loan['id']]['receiptNumber'] }}. No money posted.</p>
                                        <button type="button" wire:click="downloadCleanLoanReceipt('{{ $loan['id'] }}')"
                                            class="rounded-lg border px-3 py-2 text-sm">Download numbered clean-return PDF</button>
                                    @elseif($loan['destinationReceiptConfirmed'] &&
                                        ($loan['returnReconciliation'] ?? null) !== null &&
                                        !$loan['returnReconciliation']['integrityWarning'] &&
                                        $loan['returnReconciliation']['receivedQuantity']>0 &&
                                        $loan['returnReconciliation']['sourceAcknowledgedGood']===$loan['returnReconciliation']['receivedQuantity'] &&
                                        $loan['returnReconciliation']['sourceAcknowledgedDamaged']===0 &&
                                        $loan['returnReconciliation']['sourceUnresolved']===0 &&
                                        $loan['returnReconciliation']['sourceAcknowledgmentPending']===0 &&
                                        $loan['returnReconciliation']['notYetShipped']===0 &&
                                        $loan['returnReconciliation']['stillUnsettled']===0)
                                        <p class="text-xs">Final source approval will independently check all physical evidence, roles and open incidents. A clean receipt cannot settle damages or money.</p>
                                        <button type="button" wire:click="closeCleanInternalLoan('{{ $loan['id'] }}')"
                                            class="rounded-lg border px-3 py-2 text-sm">Finalize independently reconciled clean loan</button>
                                    @endif
                                    @error('cleanLoanClosure') <p role="alert">{{ $message }}</p> @enderror
                                @endif
                                @if(config('inventory_controls.loan_returns_enabled') &&
                                    ($loan['viewRole'] ?? '') === 'receiving' &&
                                    in_array(($loan['status'] ?? ''),[
                                        'destination_received_return_pending',
                                        'partial_return_in_transit_source_ack_pending',
                                        'return_acknowledged_outstanding_reconciliation_required'
                                    ],true))
                                    <button type="button" wire:click="startInternalReturn('{{ $loan['id'] }}')"
                                        class="rounded-lg border px-3 py-2 text-sm">Prepare partial return shipment</button>
                                @endif
                                @if(config('inventory_controls.loan_receipt_enabled') &&
                                    config('inventory_controls.loan_dispatch_enabled') &&
                                    ($loan['viewRole'] ?? '') === 'receiving' &&
                                    ($loan['status'] ?? '') === 'source_dispatched_receipt_pending')
                                    <button type="button" wire:click="startLoanReceipt('{{ $loan['id'] }}')"
                                        class="rounded-lg border px-3 py-2 text-sm">Record independent receiving count</button>
                                @endif
                            </article>
                        @empty
                            <p class="text-sm text-gray-600 dark:text-gray-300">No loan drafts yet for this hall.</p>
                        @endforelse
                    </div>
                </div>
                @if(config('inventory_controls.loan_incidents_enabled') && $incidentLoanId !== '')
                    <section class="space-y-3 rounded-xl border p-4" aria-label="Record inventory loan incident">
                        <h3 class="font-semibold">Independent incident report</h3>
                        <p class="text-sm">Evidence only. Reports do not assess charges, settle damage, transfer inventory or close the loan.</p>
                        <p>Loan: {{ $incidentLoanId }}</p>
                        <label class="block">Source item
                            <select wire:model="incidentItemId" class="w-full rounded-lg border p-2 text-gray-900">
                                <option value="">Choose loan item</option>
                                @foreach($incidentLines as $line)
                                    <option value="{{ $line['itemId'] }}">{{ $line['name'] }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">Incident type
                            <select wire:model="incidentKind" class="w-full rounded-lg border p-2 text-gray-900">
                                <option value="damage">Damaged</option>
                                <option value="missing">Missing / outstanding</option>
                                <option value="dispute">Disputed custody</option>
                            </select>
                        </label>
                        <label class="block">Affected quantity
                            <input wire:model="incidentQuantity" type="number" min="1" class="w-full rounded-lg border p-2 text-gray-900">
                        </label>
                        <label class="block">Incident explanation (10 to 500 characters)
                            <textarea wire:model="incidentReason" class="w-full rounded-lg border p-2 text-gray-900"></textarea>
                        </label>
                        @error('incidentQuantity') <p role="alert">{{ $message }}</p> @enderror
                        @error('incidentReason') <p role="alert">{{ $message }}</p> @enderror
                        <button type="button" wire:click="submitLoanIncident" class="rounded-lg border px-3 py-2">Record immutable incident</button>
                        @if($lastIncidentId)<p>Recorded incident: {{ $lastIncidentId }}. Financial resolution remains pending.</p>@endif
                        @if(config('inventory_controls.loan_incident_reviews_enabled'))
                            <h4 class="font-semibold">Independent incident evidence review</h4>
                            <p>Only a different authorized owner/admin may review a report. Review does not authorize any charge, debt write-off, or loan closure.</p>
                            <label class="block">Unreviewed incident
                                <select wire:model="incidentDecisionReportId" class="w-full rounded-lg border p-2 text-gray-900">
                                    <option value="">Select an incident</option>
                                    @foreach($incidentReviews as $review)
                                        @if($review['decision'] === null)
                                            <option value="{{ $review['reportId'] }}">{{ $review['kind'] }}: {{ $review['quantity'] }} ({{ $review['reportId'] }})</option>
                                        @endif
                                    @endforeach
                                </select>
                            </label>
                            <label class="block">Evidence assessment
                                <select wire:model="incidentDecision" class="w-full rounded-lg border p-2 text-gray-900">
                                    <option value="requires_more_evidence">More evidence required</option>
                                    <option value="substantiated">Evidence supports report</option>
                                    <option value="unsubstantiated">Evidence does not support report</option>
                                </select>
                            </label>
                            <label class="block">Review explanation
                                <textarea wire:model="incidentDecisionReason" class="w-full rounded-lg border p-2 text-gray-900"></textarea>
                            </label>
                            @error('incidentDecisionReason') <p role="alert">{{ $message }}</p> @enderror
                            <button type="button" wire:click="submitLoanIncidentReview" class="rounded-lg border px-3 py-2">Record independent decision</button>
                            @if($lastIncidentDecisionId)<p>Immutable review saved: {{ $lastIncidentDecisionId }}.</p>@endif
                            @foreach($incidentReviews as $review)
                                <p wire:key="loan-incident-review-{{ $review['reportId'] }}">Report {{ $review['reportId'] }}: {{ $review['decision'] ?? 'awaiting independent review' }}. Financial obligations and custody remain unsettled.</p>
                            @endforeach
                        @endif
                        <h4 class="font-semibold">Existing reports</h4>
                        @forelse($incidentHistory as $incident)
                            <p wire:key="incident-{{ $incident['id'] }}">{{ $incident['kind'] }}: {{ $incident['quantity'] }} units, {{ $incident['reason'] }} (report {{ $incident['id'] }})</p>
                        @empty
                            <p>No incident reports recorded.</p>
                        @endforelse
                    </section>
                @endif
                @if(config('inventory_controls.external_borrowed_intake_enabled') && $externalIntakeLoanId !== '')
                    <section class="space-y-3 rounded-xl border p-4" aria-label="Witnessed external borrowing arrival">
                        <h3 class="font-semibold">Witnessed external borrowing, signed partner handover</h3>
                        <p class="text-sm">Recording only captures independently counted physical evidence. A different current owner/admin must accept before BORROWED inventory enters the book. No owned stock or money is posted.</p>
                        <p class="text-xs">External borrowing {{ $externalIntakeLoanId }}</p>
                        <div class="space-y-2">
                            <p>Enter what was physically received for EACH item. A difference is preserved for investigation; owner approval cannot credit an unmatched count.</p>
                            @foreach($loanHistory as $externalLoan)
                                @if($externalLoan['id']===$externalIntakeLoanId)
                                    @foreach($externalLoan['lines'] as $externalLine)
                                        <label wire:key="external-intake-count-{{ $externalLine['itemId'] }}" class="flex items-center justify-between gap-3 text-sm">
                                            <span>{{ $externalLine['name'] }} (requested {{ $externalLine['quantity'] }} {{ $externalLine['unit'] }})</span>
                                            <input type="number" min="0" max="100000000" step="1" required
                                                wire:model="externalIntakeCounts.{{ $externalLine['itemId'] }}"
                                                class="w-28 rounded-lg border p-2 text-gray-900">
                                        </label>
                                    @endforeach
                                @endif
                            @endforeach
                        </div>
                        @error('externalIntakeCounts') <p role="alert">{{ $message }}</p> @enderror
                        <label class="block space-y-1"><span class="text-sm">Signed outside partner handover (PDF or photo, up to 5 MB)</span>
                            <input type="file" wire:model="externalIntakeFile" accept=".jpg,.jpeg,.png,.pdf" class="w-full rounded-lg border p-2"></label>
                        @error('externalIntakeFile') <p role="alert">{{ $message }}</p> @enderror
                        <button type="button" wire:click="submitExternalBorrowedReceipt"
                            class="rounded-lg bg-emerald-800 px-4 py-2 text-sm text-white">
                            Submit witnessed outside delivery for independent review
                        </button>
                    </section>
                @endif
                @if($lastExternalIntakeReference)
                    <p role="status">External physical evidence/approval reference: {{ $lastExternalIntakeReference }}. This does not create owned inventory or financial entries.</p>
                @endif

                @if(config('inventory_controls.external_borrowed_return_enabled') &&
                    config('inventory_controls.external_borrowed_intake_enabled'))
                    <section class="rounded-xl border p-4 space-y-3" aria-label="Outside borrowed return batches">
                        <h3 class="font-semibold">External borrowed physical returns</h3>
                        <p class="text-sm">Each shipment requires signed handover. Another owner/admin verifies the partner signed receipt and independently enters physically accepted quantities. Short or disputed counts remain in transit. No money or penalty is assumed.</p>
                        @foreach($externalReturnHistory as $returnBatch)
                            <article wire:key="external-return-{{ $returnBatch['batchId'] }}" class="rounded-lg border p-3 text-sm space-y-1">
                                <p>Loan {{ $returnBatch['loanId'] }} / batch {{ $returnBatch['batchId'] }}
                                    — {{ $returnBatch['confirmed'] ? 'PARTNER PHYSICAL RECEIPT CONFIRMED' : 'IN TRANSIT / PARTNER ACKNOWLEDGMENT PENDING' }}</p>
                                <ul class="list-inside list-disc">
                                    @foreach($returnBatch['lines'] as $line)
                                        <li>{{ $line['itemId'] }} — {{ $line['quantity'] }} units shipped</li>
                                    @endforeach
                                </ul>
                                @if(!$returnBatch['confirmed'] && $returnBatch['dispatchedBy']!==(int)auth()->id() &&
                                    $this->canIndependentlyAcceptExternalBorrowing())
                                    <button type="button" wire:click="startExternalReturnConfirmation('{{ $returnBatch['batchId'] }}')"
                                        class="rounded-lg border px-3 py-2">Independently verify partner receipt</button>
                                @endif
                            </article>
                        @endforeach
                    </section>
                @endif
                @if(config('inventory_controls.external_borrowed_return_enabled') && $externalReturnLoanId !== '')
                    <section class="rounded-xl border p-4 space-y-3" aria-label="Dispatch outside partner return">
                        <h3 class="font-semibold">Physically send partial borrowed return</h3>
                        <p>Loan {{ $externalReturnLoanId }}. Enter only units physically handed over. Leave non-returned items blank.</p>
                        @foreach($loanHistory as $outsideLoan)
                            @if($outsideLoan['id']===$externalReturnLoanId)
                                @foreach($outsideLoan['lines'] as $line)
                                    <label wire:key="ext-return-qty-{{ $line['itemId'] }}" class="block text-sm">
                                        {{ $line['name'] }} (original borrowed {{ $line['quantity'] }} {{ $line['unit'] }})
                                        <input type="number" min="1" max="{{ $line['quantity'] }}" step="1"
                                            wire:model="externalReturnQuantities.{{ $line['itemId'] }}"
                                            class="w-full rounded-lg border p-2 text-gray-900" placeholder="Leave blank if not returning">
                                    </label>
                                @endforeach
                            @endif
                        @endforeach
                        <label class="block text-sm">Signed outside return handover (PDF/photo, up to 5 MB)
                            <input type="file" wire:model="externalReturnFile" accept=".pdf,.jpg,.jpeg,.png"
                                class="w-full rounded-lg border p-2"></label>
                        @error('externalReturnFile') <p role="alert">{{ $message }}</p> @enderror
                        @error('externalReturnQuantities') <p role="alert">{{ $message }}</p> @enderror
                        <button type="button" wire:click="submitExternalBorrowedReturn"
                            class="rounded-lg bg-emerald-800 px-4 py-2 text-sm text-white">Record witnessed outside return shipment</button>
                    </section>
                @endif
                @if(config('inventory_controls.external_borrowed_return_enabled') && $externalConfirmBatchId !== '')
                    <section class="rounded-xl border p-4 space-y-3" aria-label="Independent outside partner receipt verification">
                        <h3 class="font-semibold">Independently confirm outside partner receipt</h3>
                        <p>Batch {{ $externalConfirmBatchId }}. Enter partner-acknowledged counts item by item. Discrepancies MUST stay in transit.</p>
                        @foreach($externalReturnHistory as $returnBatch)
                            @if($returnBatch['batchId']===$externalConfirmBatchId)
                                @foreach($returnBatch['lines'] as $line)
                                    <label wire:key="ext-confirm-qty-{{ $line['itemId'] }}" class="block text-sm">
                                        Item {{ $line['itemId'] }} (physically shipped {{ $line['quantity'] }})
                                        <input type="number" min="0" max="{{ $line['quantity'] }}" step="1" required
                                            wire:model="externalConfirmCounts.{{ $line['itemId'] }}"
                                            class="w-full rounded-lg border p-2 text-gray-900">
                                    </label>
                                @endforeach
                            @endif
                        @endforeach
                        <label class="block text-sm">Outside partner signed acknowledgment PDF/photo, up to 5 MB
                            <input type="file" wire:model="externalConfirmFile" accept=".pdf,.jpg,.jpeg,.png"
                                class="w-full rounded-lg border p-2"></label>
                        @error('externalConfirmFile') <p role="alert">{{ $message }}</p> @enderror
                        @error('externalConfirmCounts') <p role="alert">{{ $message }}</p> @enderror
                        <button type="button" wire:click="submitExternalReturnConfirmation"
                            class="rounded-lg bg-emerald-800 px-4 py-2 text-sm text-white">Confirm independently counted partner receipt</button>
                    </section>
                @endif
                @if($lastExternalReturnReference)
                    <p role="status">External return custody reference: {{ $lastExternalReturnReference }}. No financial entry or loan closure was posted.</p>
                @endif
                @if(config('inventory_controls.loan_dispatch_enabled') && $loanDispatchLoanId !== '')
                    <section class="space-y-3 rounded-xl border p-4" aria-label="Independent internal source dispatch">
                        <h3 class="font-semibold">Independently witnessed source dispatch</h3>
                        <p class="text-sm">Check each source item and quantity. Actual owned stock leaves the lending hall only when independent owner/admin review and the physical evidence are accepted. Destination receiving is separate.</p>
                        <p class="text-xs">Loan {{ $loanDispatchLoanId }}</p>
                        <ul class="list-inside list-disc text-sm">
                            @foreach($loanDispatchLines as $line)
                                <li wire:key="dispatch-line-{{ $line['itemId'] }}">{{ $line['name'] }}: {{ $line['quantity'] }} {{ $line['unit'] }}</li>
                            @endforeach
                        </ul>
                        <label class="block space-y-1"><span class="text-sm">Signed handover document or physical dispatch photo (5 MB maximum)</span>
                            <input type="file" wire:model="loanDispatchFile" accept=".jpg,.jpeg,.png,.pdf" class="w-full rounded-lg border p-3">
                        </label>
                        @error('loanDispatchFile') <p role="alert">{{ $message }}</p> @enderror
                        <button type="button" wire:click="submitInternalDispatch" class="rounded-lg bg-emerald-800 px-4 py-2 text-sm text-white">
                            Confirm actual source physical dispatch
                        </button>
                    </section>
                @endif
                @if($lastLoanDispatchReference)
                    <p role="status">Independently witnessed physical dispatch recorded: {{ $lastLoanDispatchReference }}. Await receiving-hall physical acknowledgment, no completed loan or payment.</p>
                @endif
                @if(config('inventory_controls.loan_receipt_enabled') &&
                    config('inventory_controls.loan_dispatch_enabled') && $loanReceiptLoanId !== '')
                    <section class="space-y-3 rounded-xl border p-4" aria-label="Confirm independent internal loan arrival">
                        <h3 class="font-semibold">Confirm receiving-hall physical custody</h3>
                        <p class="text-sm">This records borrowed inventory, not owned purchases or completed returns. Verify each line and attach a physical receiving photograph or PDF.</p>
                        <p class="text-xs">Internal loan: {{ $loanReceiptLoanId }}</p>
                        @foreach($loanReceiptLines as $line)
                            <label class="block space-y-1" wire:key="receive-line-{{ $line['itemId'] }}">
                                <span class="text-sm">{{ $line['name'] }} — {{ $line['quantity'] }} {{ $line['unit'] }}</span>
                                <select wire:model="loanReceiptMapping.{{ $line['itemId'] }}"
                                    class="w-full rounded-lg border p-3 text-gray-900">
                                    <option value="">Select matching item in receiving hall</option>
                                    @foreach($catalog as $item)
                                        @if($item['name']===$line['name'] && $item['unit']===$line['unit'])
                                            <option value="{{ $item['id'] }}">{{ $item['name'] }} ({{ $item['unit'] }})</option>
                                        @endif
                                    @endforeach
                                </select>
                            </label>
                        @endforeach
                        <label class="block space-y-1">
                            <span class="text-sm">Independent receiving photograph or PDF (5 MB maximum)</span>
                            <input type="file" wire:model="loanReceiptFile" accept=".jpg,.jpeg,.png,.pdf"
                                class="w-full rounded-lg border p-3">
                        </label>
                        @error('loanReceiptFile') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
                        @error('loanReceiptMapping') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
                        <button type="button" wire:click="submitLoanReceipt"
                            class="rounded-lg bg-emerald-800 px-4 py-2 text-sm font-medium text-white">
                            Confirm physically received stock
                        </button>
                    </section>
                @endif
                @if($lastLoanReceiptReference)
                    <p role="status" class="rounded-lg bg-amber-100 p-3 text-sm text-amber-900">
                        Receiving custody recorded: {{ $lastLoanReceiptReference }}. Source stock remains on loan until independently returned.
                    </p>
                @endif
                @if(config('inventory_controls.loan_returns_enabled') && $returnLoanId !== '')
                    <section class="space-y-3 rounded-xl border p-4" aria-label="Send partial physical borrowed return">
                        <h3 class="font-semibold">Send a partial borrowed-stock return</h3>
                        <p class="text-sm">This moves destination borrowed stock into transit only. The lender must independently inspect, accept and record condition.</p>
                        <p class="text-xs">Loan: {{ $returnLoanId }}</p>
                        @foreach($returnEligibleLines as $line)
                            @if($line['returnableQuantity']>0)
                                <label class="block space-y-1" wire:key="return-line-{{ $line['sourceItemId'] }}">
                                    <span class="text-sm">{{ $line['name'] }} — available {{ $line['returnableQuantity'] }} {{ $line['unit'] }}</span>
                                    <input type="number" min="1" max="{{ $line['returnableQuantity'] }}"
                                        wire:model="returnQuantities.{{ $line['sourceItemId'] }}"
                                        class="w-full rounded-lg border p-2 text-gray-900"
                                        placeholder="Quantity to physically return">
                                </label>
                            @endif
                        @endforeach
                        <label class="block space-y-1"><span class="text-sm">Physical return handover PDF or photo</span>
                            <input type="file" wire:model="returnHandoverFile" accept=".jpg,.jpeg,.png,.pdf"
                                class="w-full rounded-lg border p-3">
                        </label>
                        @error('returnQuantities') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
                        @error('returnHandoverFile') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
                        <button type="button" wire:click="sendInternalReturn" class="rounded-lg bg-emerald-800 px-4 py-2 text-sm text-white">
                            Record witnessed return shipment
                        </button>
                    </section>
                @endif
                @if($lastReturnBatchId)
                    <p role="status" class="rounded-lg bg-amber-100 p-3 text-sm text-amber-900">
                        Return shipment {{ $lastReturnBatchId }} is in transit. Lender condition review and physical acknowledgment remain required.
                    </p>
                @endif
                @if(config('inventory_controls.loan_returns_enabled') &&
                    config('inventory_controls.loan_return_acceptance_enabled'))
                    <section class="space-y-3 rounded-xl border p-4" aria-label="Incoming physical return batches">
                        <h3 class="font-semibold">Returned stock awaiting source-hall confirmation</h3>
                        @forelse($returnSourceHistory as $batch)
                            <article class="rounded-lg border p-3 text-sm" wire:key="return-source-batch-{{ $batch['batchId'] }}">
                                <p>Loan {{ $batch['loanId'] }} / return {{ $batch['batchId'] }}</p>
                                <p>{{ $batch['accepted'] ? 'REVIEW RECORDED / UNRESOLVED OBLIGATIONS MUST BE CHECKED' : 'IN TRANSIT / SOURCE ACKNOWLEDGMENT REQUIRED' }}</p>
                                <ul class="list-inside list-disc">
                                    @foreach($batch['lines'] as $line)
                                        <li>{{ $line['name'] }} — {{ $line['sentQuantity'] }} {{ $line['unit'] }}
                                            @if($line['goodQuantity'] !== null)
                                                (good {{ $line['goodQuantity'] }}, damaged {{ $line['damagedQuantity'] }},
                                                unresolved {{ $line['unresolvedQuantity'] }}; not loan closure)
                                            @else
                                                (source count pending)
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                                @if(!$batch['accepted'])
                                    <button type="button" wire:click="startReturnAcceptance('{{ $batch['batchId'] }}')"
                                        class="rounded-lg border px-3 py-2 text-sm">Review physically returned items</button>
                                @endif
                            </article>
                        @empty
                            <p class="text-sm text-gray-600 dark:text-gray-300">No source return shipments awaiting review.</p>
                        @endforelse
                    </section>
                @endif
                @if(config('inventory_controls.loan_return_acceptance_enabled') && $returnAcceptBatchId !== '')
                    <section class="space-y-3 rounded-xl border p-4" aria-label="Accept returned physical stock by condition">
                        <h3 class="font-semibold">Independent lender return condition count</h3>
                        <p>Acceptance requires physical evidence. Any unaccounted quantity stays outstanding and cannot close the loan.</p>
                        <p class="text-xs">Return batch: {{ $returnAcceptBatchId }}</p>
                        @foreach($returnAcceptanceLines as $line)
                            <div wire:key="accept-return-line-{{ $line['sourceItemId'] }}" class="space-y-2 rounded-lg border p-3">
                                <p class="text-sm font-medium">{{ $line['name'] }} — shipped {{ $line['sentQuantity'] }} {{ $line['unit'] }}</p>
                                <label class="block text-sm">Physically good quantity
                                    <input type="number" min="0" max="{{ $line['sentQuantity'] }}"
                                        wire:model="returnConditionGood.{{ $line['sourceItemId'] }}"
                                        class="w-full rounded-lg border p-2 text-gray-900">
                                </label>
                                <label class="block text-sm">Physically damaged quantity
                                    <input type="number" min="0" max="{{ $line['sentQuantity'] }}"
                                        wire:model="returnConditionDamaged.{{ $line['sourceItemId'] }}"
                                        class="w-full rounded-lg border p-2 text-gray-900">
                                </label>
                            </div>
                        @endforeach
                        <label class="block space-y-1"><span class="text-sm">Source physical inspection evidence (PDF or photo)</span>
                            <input type="file" wire:model="returnAcceptanceFile" accept=".jpg,.jpeg,.png,.pdf"
                                class="w-full rounded-lg border p-3">
                        </label>
                        @error('returnConditionGood') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
                        @error('returnAcceptanceFile') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
                        <button type="button" wire:click="submitReturnAcceptance"
                            class="rounded-lg bg-emerald-800 px-4 py-2 text-sm text-white">
                            Accept observed good/damaged return quantities
                        </button>
                    </section>
                @endif
                @if($lastReturnAcceptanceId)
                    <p role="status" class="rounded-lg bg-amber-100 p-3 text-sm text-amber-900">
                        Independent source return acknowledgment {{ $lastReturnAcceptanceId }} recorded.
                        No loan completion or money settlement was inferred.
                    </p>
                @endif
            </section>
        @endif
    </div>
</x-filament-panels::page>
