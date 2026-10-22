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
                                        'external_out_handover_pending_approval' => 'OUTSIDE LENDING HANDOVER / INDEPENDENT REVIEW PENDING',
                                        'external_out_partner_custody_outstanding' => 'HALL-OWNED STOCK LENT OUT / PARTNER RETURN PENDING',
                                        'external_out_partial_return_review' => 'OUTSIDE OWNED PARTIAL RETURNS / RECONCILIATION PENDING',
                                        'external_out_physically_returned_clean_closure_pending' => 'OUTSIDE OWNED PHYSICAL RETURN VERIFIED / FORMAL CLOSURE PENDING',
                                        'external_out_clean_closed' => 'CLOSED / OUTSIDE OWNED CLEAN RETURN VERIFIED',
                                        'external_physical_receipt_pending_review' => 'EXTERNAL PHYSICAL RECEIPT / INDEPENDENT REVIEW PENDING',
                                        'external_borrowed_received_return_pending' => 'EXTERNAL BORROWED STOCK RECEIVED / RETURN PENDING',
                                        'external_partial_return_or_partner_ack_pending' => 'EXTERNAL PARTIAL RETURN / PARTNER RECEIPT PENDING',
                                        'external_all_returns_physically_confirmed_finance_unsettled' => 'EXTERNAL RETURN PHYSICALLY VERIFIED / FINANCE UNSETTLED',
                                        'external_clean_loan_closed' => 'CLOSED / EXTERNAL CLEAN PARTNER RETURN VERIFIED',
                                        default => 'DRAFT / NOT DISPATCHED'
                                    };
                                @endphp
                                <p class="font-medium">{{ $loan['purpose'] }} — {{ ($loan['viewRole'] ?? 'originating') === 'receiving' ? 'INCOMING REQUEST' : 'OUTGOING REQUEST' }} — {{ $loanStatus }}</p>
                                <p>Reference: {{ $loan['id'] }}. Return expected: {{ $loan['expectedReturnDate'] }}.</p>
                                @if(config('inventory_controls.loan_incidents_enabled') && $loan['lendingHallId'] === $hallId &&
                                    (($loan['direction'] === 'internal' && $loan['destinationReceiptConfirmed']) ||
                                     ($loan['direction'] === 'external_out' &&
                                        config('inventory_controls.external_out_return_enabled') &&
                                        $loan['externalOutApproved'])))
                                    <button type="button" wire:click="startLoanIncident('{{ $loan['id'] }}')" class="rounded-lg border px-3 py-2 text-sm">Review damage, missing or disputed items</button>
                                @endif
                                @if(config('inventory_controls.loan_financial_claims_enabled') &&
                                    $loan['direction']==='external_out' &&
                                    $loan['lendingHallId']===$hallId &&
                                    $loan['externalOutApproved'] &&
                                    $this->canManageFinancialClaims())
                                    <button type="button" wire:click="startFinancialClaims('{{ $loan['id'] }}')"
                                        class="rounded-lg border px-3 py-2 text-sm">Review provisional damage/loss claims</button>
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
                                @if(($loan['externalOutReturnReconciliation'] ?? null)!==null)
                                    <p>Outside owned loan custody: original {{ $loan['externalOutReturnReconciliation']['original'] }},
                                        physically returned {{ $loan['externalOutReturnReconciliation']['physicallyReceived'] }},
                                        independently good {{ $loan['externalOutReturnReconciliation']['verifiedGood'] }},
                                        damaged {{ $loan['externalOutReturnReconciliation']['verifiedDamaged'] }},
                                        unresolved {{ $loan['externalOutReturnReconciliation']['verifiedUnresolved'] }}.
                                        Return review pending {{ $loan['externalOutReturnReconciliation']['reviewPending'] }},
                                        not physically returned {{ $loan['externalOutReturnReconciliation']['notYetReturned'] }},
                                        outstanding owned-on-loan {{ $loan['externalOutReturnReconciliation']['stillOnLoan'] }}.
                                        @if($loan['externalOutReturnReconciliation']['loanClosed'])
                                            Numbered clean return custody is CLOSED; no financial settlement was posted.
                                        @else
                                            Loan closure and financial settlement are NOT recorded.
                                        @endif</p>
                                    @if($loan['externalOutReturnReconciliation']['integrityWarning'])
                                        <p role="alert">Outside-owned per-loan physical count lineage mismatch; do not approve clean closure.</p>
                                    @endif
                                @endif
                                @if(($loan['externalReturnReconciliation'] ?? null) !== null)
                                    <p>External borrowed custody: accepted {{ $loan['externalReturnReconciliation']['acceptedQuantity'] }},
                                        shipped to partner {{ $loan['externalReturnReconciliation']['shippedQuantity'] }},
                                        independently partner-confirmed {{ $loan['externalReturnReconciliation']['partnerConfirmedQuantity'] }}.
                                        Still in hall {{ $loan['externalReturnReconciliation']['stillAtHall'] }},
                                        awaiting partner receipt {{ $loan['externalReturnReconciliation']['awaitingPartnerConfirmation'] }},
                                        total outstanding {{ $loan['externalReturnReconciliation']['stillOutstanding'] }}.</p>
                                    @if($loan['externalReturnReconciliation']['integrityWarning'])
                                        <p role="alert">External return custody evidence mismatch. Do not close or settle this loan.</p>
                                    @elseif($loan['externalReturnReconciliation']['allPhysicalReturnsConfirmed'])
                                        <p>All borrowed physical quantities independently returned and partner receipt-verified.
                                            @if($loan['externalReturnReconciliation']['loanClosed'])
                                                Clean physical return closure is recorded; it does not create money, penalty or any financial settlement.
                                            @else
                                                Formal loan closure, claims and financial settlement remain separate and unrecorded.
                                            @endif
                                        </p>
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
                                        <p class="text-sm">External inventory accepted as BORROWED, not owned. No money posted or loan completed.
                                            {{ ($loan['externalReturnReconciliation']['allPhysicalReturnsConfirmed'] ?? false) ? 'Physical return verified; formal closure pending.' : 'Physical return is still due.' }}</p>
                                        @if(config('inventory_controls.external_borrowed_return_enabled') &&
                                            !$loan['externalCleanClosed'] &&
                                            $loan['createdBy']!==(int)auth()->id())
                                            <button type="button" wire:click="startExternalBorrowedReturn('{{ $loan['id'] }}')"
                                                class="rounded-lg border px-3 py-2 text-sm">Prepare partial outside partner return</button>
                                        @endif
                                    @endif
                                    @error('externalIntakeFile') <p role="alert">{{ $message }}</p> @enderror
                                @endif
                                @if(config('inventory_controls.external_borrowed_clean_closure_enabled') &&
                                    $loan['direction']==='external_in' &&
                                    $loan['borrowingHallId']===$hallId &&
                                    $this->canCloseCleanExternalBorrowing())
                                    @if(isset($externalCleanReceipts[$loan['id']]))
                                        <p class="font-medium">External borrowed clean return receipt EX-{{ $externalCleanReceipts[$loan['id']]['receiptNumber'] }}. No financial journal posted.</p>
                                        <button type="button" wire:click="downloadExternalCleanReceipt('{{ $loan['id'] }}')"
                                            class="rounded-lg border px-3 py-2 text-sm">Download numbered external clean-return PDF</button>
                                    @elseif($loan['externalReceiptAccepted'] &&
                                        !$loan['externalCleanClosed'] &&
                                        $loan['createdBy']!==(int)auth()->id() &&
                                        ($loan['externalReturnReconciliation']['allPhysicalReturnsConfirmed'] ?? false) &&
                                        !($loan['externalReturnReconciliation']['integrityWarning'] ?? true))
                                        <p class="text-xs">Final owner approval rechecks original accepted quantities, partner-signed evidence, separate actors and open incidents. Money and claims remain unsettled.</p>
                                        <button type="button" wire:click="closeCleanExternalBorrowing('{{ $loan['id'] }}')"
                                            class="rounded-lg border px-3 py-2 text-sm">Finalize externally borrowed clean loan</button>
                                    @endif
                                    @error('externalCleanClosure') <p role="alert">{{ $message }}</p> @enderror
                                @endif
                                @if(config('inventory_controls.external_out_dispatch_enabled') &&
                                    $loan['direction']==='external_out' &&
                                    $loan['lendingHallId']===$hallId)
                                    @if(!$loan['externalOutHandoverRecorded'] &&
                                        $loan['createdBy']!==(int)auth()->id())
                                        <button type="button" wire:click="startExternalOutHandover('{{ $loan['id'] }}')"
                                            class="rounded-lg border px-3 py-2 text-sm">Witness outside partner physical handover</button>
                                    @elseif($loan['externalOutHandoverRecorded'] && !$loan['externalOutApproved'])
                                        <p>Witnessed outside handover:
                                            @foreach($loan['lines'] as $outLine)
                                                {{ $outLine['name'] }} counted {{ $loan['externalOutCounts'][$outLine['itemId']] ?? 'MISSING' }} / requested {{ $outLine['quantity'] }}.
                                            @endforeach
                                        </p>
                                        @if(!$loan['externalOutCountMatchesDraft'])
                                            <p role="alert">Outside physical counts do not match original loan; no owned stock may leave the book.</p>
                                        @elseif($this->canApproveExternalOutDispatch() &&
                                            $loan['createdBy']!==(int)auth()->id())
                                            <button type="button" wire:click="approveExternalOutDispatch('{{ $loan['id'] }}')"
                                                class="rounded-lg border px-3 py-2 text-sm">Independently approve outside lending stock dispatch</button>
                                        @endif
                                    @elseif($loan['externalOutApproved'])
                                        <p>Outside lending approved. Hall-owned goods are ON LOAN to the partner, not sold or financially settled. Independently reviewed partner returns are separately listed below.</p>
                                        @if(config('inventory_controls.external_out_return_enabled') &&
                                            !$this->canApproveExternalOutDispatch() &&
                                            $loan['createdBy']!==(int)auth()->id())
                                            <button type="button" wire:click="startExternalOutReturn('{{ $loan['id'] }}')"
                                                class="rounded-lg border px-3 py-2 text-sm">Record partial owned-stock return from outside partner</button>
                                        @endif
                                    @endif
                                    @if(config('inventory_controls.external_out_clean_closure_enabled') &&
                                        $this->canCloseExternalOutClean())
                                        @if(isset($externalOutCleanReceipts[$loan['id']]))
                                            <p class="font-medium">Immutable outside-owned clean physical return EOL-{{ $externalOutCleanReceipts[$loan['id']]['receiptNumber'] }}. No money posted.</p>
                                            <button type="button" wire:click="downloadExternalOutCleanLoanPdf('{{ $loan['id'] }}')"
                                                class="rounded-lg border px-3 py-2 text-sm">Download numbered EOL outside-owned clean return PDF</button>
                                        @elseif(!$loan['externalOutClosed'] &&
                                            $loan['createdBy']!==(int)auth()->id() &&
                                            ($loan['externalOutReturnReconciliation']['allFullyReturnedClean']??false))
                                            <p class="text-xs">Final owner review independently checks every original signed handover and partner return, full-good condition, absent incidents and separate actors. Financial obligations are not assessed.</p>
                                            <button type="button" wire:click="closeExternalOutCleanLoan('{{ $loan['id'] }}')"
                                                class="rounded-lg border px-3 py-2 text-sm">Finalize outside-owned clean physical loan</button>
                                        @endif
                                        @error('externalOutCleanClosure') <p role="alert">{{ $message }}</p> @enderror
                                    @endif
                                    @error('externalOutFile') <p role="alert">{{ $message }}</p> @enderror
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
                                @if(config('inventory_controls.internal_discrepancy_enabled') &&
                                    $loan['direction']==='internal' && $loan['destinationReceiptConfirmed'])
                                    @if(($loan['viewRole']??'')==='receiving')
                                        <button type="button" wire:click="startInternalDiscrepancy('{{ $loan['id'] }}')">File signed borrowing-hall missing/dispute statement</button>
                                    @elseif($loan['lendingHallId']===$hallId && $this->canReviewInternalDiscrepancy())
                                        <button type="button" wire:click="startInternalDiscrepancyReview('{{ $loan['id'] }}')">Review original borrower signed discrepancy evidence</button>
                                    @endif
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
                        @if(count($internalAbnormalCustody)>0)
                            <section aria-label="Internal abnormal custody reconciliation" class="rounded-lg border p-3 space-y-2">
                                <h4 class="font-semibold">Original lending hall physical custody (read-only)</h4>
                                <p>Good units are separate from damaged, unresolved and in-transit units. This view cannot authorize a write-off, debt, receipt, or final closure.</p>
                                @foreach($internalAbnormalCustody as $balance)
                                    <p wire:key="internal-custody-{{ $balance['itemId'] }}">
                                        Item {{ $balance['itemId'] }}: dispatched {{ $balance['dispatched'] }},
                                        good {{ $balance['good'] }}, damaged {{ $balance['damaged'] }},
                                        independently retired damaged {{ $balance['approvedDamagedRetirement'] }},
                                        damaged still awaiting disposition {{ $balance['remainingDamagedForDisposition'] }},
                                        unresolved {{ $balance['unresolved'] }}, return pending review {{ $balance['inTransit'] }},
                                        not yet returned {{ $balance['notReturned'] }},
                                        original physically unsettled {{ $balance['unsettledPhysical'] }},
                                        destination item buckets may combine multiple loans; not signed borrower acceptance,
                                        destination item aggregate borrowed usable {{ $balance['borrowerBookUsable'] }},
                                        destination item aggregate borrowed transit {{ $balance['borrowerBookTransit'] }},
                                        destination item aggregate borrowed damaged {{ $balance['borrowerBookDamaged'] }},
                                        independently substantiated missing evidence {{ $balance['substantiatedMissingEvidence'] }},
                                        independently substantiated dispute evidence {{ $balance['substantiatedDisputeEvidence'] }},
                                        discrepancy evidence awaiting independent review {{ $balance['pendingDiscrepancyReview'] }},
                                        incident reports {{ $balance['incidentReports'] }},
                                        independently substantiated reports {{ $balance['substantiatedReports'] }}.
                                        @if($balance['integrityWarning']) INTEGRITY REVIEW REQUIRED. @endif
                                        Closure BLOCKED; no money posted by this reconciliation.
                                    </p>
                                @endforeach
                            </section>
                            @if(config('inventory_controls.loan_nonclean_writeoffs_enabled') && config('inventory_controls.loan_nonclean_reconciliation_enabled'))
                                <section aria-label="Independent internal damaged stock disposition" class="space-y-2 rounded-lg border p-3">
                                    <h4 class="font-semibold">Damaged owned stock: independent physical disposition</h4>
                                    <p>This only retires confirmed damaged owned stock. A different Owner/Admin must approve. No financial settlement or loan closure occurs.</p>
                                    <select wire:model="internalDamageIncidentId" aria-label="Substantiated damaged incident">
                                        <option value="">Choose reviewed damage evidence</option>
                                        @foreach($internalDamageEligible as $entry)
                                            <option value="{{ $entry['id'] }}">{{ $entry['itemId'] }} - {{ $entry['quantity'] }} {{ $entry['kind'] }}</option>
                                        @endforeach
                                    </select>
                                    <input wire:model="internalDamageQuantity" type="number" min="1" aria-label="Disposal quantity" placeholder="Quantity">
                                    <input wire:model="internalDamageReason" aria-label="Physical disposal reason" placeholder="Physical disposal reason">
                                    <button type="button" wire:click="proposeInternalDamageDisposition">Request physical write-off</button>
                                    @foreach($internalDamageWriteoffs as $item)
                                        <p>Physical proposal {{ $item['id'] }}: {{ $item['quantity'] }} {{ $item['kind'] }} — {{ $item['decision'] ?? 'PENDING INDEPENDENT APPROVAL' }}</p>
                                    @endforeach
                                    <select wire:model="internalDamageReviewProposalId" aria-label="Independent physical proposal">
                                        <option value="">Select proposal for independent decision</option>
                                        @foreach($internalDamageWriteoffs as $item)
                                            @if($item['decision']===null)
                                                <option value="{{ $item['id'] }}">{{ $item['id'] }} - {{ $item['quantity'] }}</option>
                                            @endif
                                        @endforeach
                                    </select>
                                    <select wire:model="internalDamageReviewDecision" aria-label="Physical disposition decision">
                                        <option value="rejected">Reject</option><option value="approved">Approve verified damage</option>
                                    </select>
                                    <input wire:model="internalDamageReviewReason" aria-label="Independent disposal decision reason" placeholder="Independent decision reason">
                                    <button type="button" wire:click="decideInternalDamageDisposition">Record independent decision</button>
                                </section>
                            @endif
                        @endif
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

                @if(config('inventory_controls.loan_financial_claims_enabled') &&
                    $financialClaimLoanId !== '' && $this->canManageFinancialClaims())
                    <section class="space-y-3 rounded-xl border p-4" aria-label="Provisional outside-owned inventory financial claims">
                        <h3 class="font-semibold">Outside lending provisional loss claims</h3>
                        @if(config('inventory_controls.loan_nonclean_reconciliation_enabled') && count($noncleanReconciliation)>0)
                            <section aria-label="Read-only non-clean loan reconciliation" class="rounded-lg border p-3 space-y-2">
                                <h4 class="font-semibold">Non-clean loan reconciliation (read-only)</h4>
                                <p>Original expected partner return date: {{ $noncleanReconciliation['expectedReturnDate'] }}. Due-date disputes need separate hall-local assessment.</p>
                                @if(config('inventory_controls.loan_nonclean_final_closure_enabled') && \Illuminate\Support\Facades\DB::table('hall_inventory_nonclean_final_closures')->where('company_id',$this->companyId)->where('hall_id',$this->hallId)->where('loan_id',$financialClaimLoanId)->exists())
                                    <p role="status">Final non-clean closure: ISSUED. Reprint the immutable NCL certificate below.</p>
                                @else
                                    <p role="status">Final non-clean closure: BLOCKED. No write-off, custody disposal, financial waiver or numbered final certificate has been authorized by this view.</p>
                                @endif
                                <p>Pending closure checks: {{ implode(', ', $noncleanReconciliation['closureBlockers'] ?? []) ?: 'No detected data blocker; final authorization still required' }}.</p>
                                @if(config('inventory_controls.loan_nonclean_final_closure_enabled'))
                                    <div class="flex flex-wrap gap-2" aria-label="Independently authorized final non-clean closure">
                                        @if(\Illuminate\Support\Facades\DB::table('hall_inventory_nonclean_final_closures')->where('company_id',$this->companyId)->where('hall_id',$this->hallId)->where('loan_id',$financialClaimLoanId)->exists())
                                            <button type="button" wire:click="downloadNoncleanFinalLoanPdf" class="rounded border px-3 py-2">Download existing immutable numbered NCL PDF</button>
                                        @else
                                            <button type="button" wire:click="closeNoncleanFinalLoan" class="rounded border px-3 py-2" @disabled(count($noncleanReconciliation['closureBlockers'] ?? []) > 0)>Independently finalize non-clean loan</button>
                                        @endif
                                    </div>
                                    @error('noncleanFinalClosure')<p role="alert">{{ $message }}</p>@enderror
                                @endif
                                @foreach($noncleanReconciliation['items'] as $physical)
                                    <p wire:key="nonclean-{{ $physical['itemId'] }}">Item {{ $physical['itemId'] }}: dispatched {{ $physical['dispatched'] }}, good {{ $physical['good'] }}, damaged {{ $physical['damaged'] }}, unresolved {{ $physical['unresolved'] }}, pending independent review {{ $physical['pendingReview'] }}. Physically unreturned: {{ $physical['unreturned'] }}. On-loan custody outstanding: {{ $physical['onLoan'] }}. Written off missing {{ $physical['writtenOffMissing'] }}, written off damaged {{ $physical['writtenOffDamaged'] }} (financial liability unchanged).</p>
                                @endforeach
                                <p>Incident reports: {{ $noncleanReconciliation['incidentReports'] }}; awaiting independent incident review: {{ $noncleanReconciliation['unreviewedIncidents'] }}. Any outstanding incident requires authorized individual disposition before final closure.</p>
                                @foreach($noncleanReconciliation['obligations'] as $id=>$amount)
                                    <p wire:key="nonclean-money-{{ $id }}">Independently authorized obligation {{ $id }}: {{ $amount['currency'] }} {{ $amount['amountMinor'] }} minor units; independently verified net collected {{ $amount['paidMinor'] }}, unverified pending {{ $amount['pendingMinor'] }}, outstanding {{ $amount['remainingMinor'] }}, approved forgiveness {{ $amount['waivedMinor'] }}, pending forgiveness {{ $amount['waiverPendingMinor'] }}.</p>
                                @endforeach
                                <p>Physical and financial summaries are informational only; separate independently authorized workflows control physical losses and contractual forgiveness.</p>
                                @if(config('inventory_controls.loan_financial_waivers_enabled'))
                                    <h4 class="font-semibold">Independent contractual settlement and partial forgiveness</h4>
                                    <p>Forgiveness reduces the outstanding approved obligation only after a second owner authorizes it. It is not a received payment, bank journal, physical write-off or loan closure.</p>
                                    <label>Existing independently approved obligation
                                        <select wire:model="financialWaiverObligationId" class="w-full rounded-lg border p-2 text-gray-900">
                                            <option value="">Choose authorized money obligation</option>
                                            @foreach($financialRecoveryBalances as $id=>$balance)
                                                <option value="{{ $id }}">{{ $balance['currency'] }} {{ $balance['unreservedMinor'] }} unreserved minor units ({{ $id }})</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <label>Partial amount in currency minor units
                                        <input wire:model="financialWaiverAmountMinor" type="number" min="1" class="w-full rounded-lg border p-2 text-gray-900">
                                    </label>
                                    <label>Documented contractual forgiveness reason
                                        <textarea wire:model="financialWaiverReason" class="w-full rounded-lg border p-2 text-gray-900"></textarea>
                                    </label>
                                    <button type="button" wire:click="submitFinancialWaiver" class="rounded-lg border p-2">Propose independent obligation forgiveness</button>
                                    @foreach($financialWaiverHistory as $waiver)
                                        <article wire:key="fin-waiver-{{ $waiver['id'] }}" class="rounded-lg border p-2 text-sm">
                                            <p>{{ $waiver['currency'] }} {{ $waiver['amountMinor'] }} minor units; {{ $waiver['decision'] ?? 'AWAITING independent approval' }}. {{ $waiver['id'] }}</p>
                                        </article>
                                    @endforeach
                                    <label>Pending proposed contractual forgiveness
                                        <select wire:model="financialWaiverDecisionProposalId" class="w-full rounded-lg border p-2 text-gray-900">
                                            <option value="">Choose pending request</option>
                                            @foreach($financialWaiverHistory as $waiver)
                                                @if($waiver['decision'] === null)
                                                    <option value="{{ $waiver['id'] }}">{{ $waiver['currency'] }} {{ $waiver['amountMinor'] }} ({{ $waiver['id'] }})</option>
                                                @endif
                                            @endforeach
                                        </select>
                                    </label>
                                    <label>Independent waiver decision
                                        <select wire:model="financialWaiverDecision" class="w-full rounded-lg border p-2 text-gray-900">
                                            <option value="rejected">Reject</option>
                                            <option value="approved">Approve contractual forgiveness</option>
                                        </select>
                                    </label>
                                    <label>Independent finance review reason
                                        <textarea wire:model="financialWaiverDecisionReason" class="w-full rounded-lg border p-2 text-gray-900"></textarea>
                                    </label>
                                    <button type="button" wire:click="decideFinancialWaiver" class="rounded-lg border p-2">Record independent financial waiver decision</button>
                                @endif
                                @if(config('inventory_controls.loan_nonclean_writeoffs_enabled'))
                                    <h4 class="font-semibold">Independent abnormal physical write-off approval</h4>
                                    <p>Write-off permanently removes confirmed lost or damaged owned units from book custody. It never cancels partner debt, confirms payment, or closes a loan. A different owner must authorize it.</p>
                                    @if(count($noncleanWriteoffEligible)>0)
                                        <label>Independently substantiated physical incident
                                            <select wire:model="noncleanWriteoffIncidentId" class="w-full rounded-lg border p-2 text-gray-900">
                                                <option value="">Choose substantiated loss</option>
                                                @foreach($noncleanWriteoffEligible as $incident)
                                                    <option value="{{ $incident['id'] }}">{{ $incident['kind'] }}: {{ $incident['quantity'] }} units ({{ $incident['id'] }})</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <label>Exact quantity to dispose
                                            <input wire:model="noncleanWriteoffQuantity" type="number" min="1" class="w-full rounded-lg border p-2 text-gray-900">
                                        </label>
                                        <label>Physical loss disposition reason
                                            <textarea wire:model="noncleanWriteoffReason" class="w-full rounded-lg border p-2 text-gray-900"></textarea>
                                        </label>
                                        <button type="button" wire:click="submitNoncleanWriteoff" class="rounded-lg border p-2">Request independent stock write-off review</button>
                                    @endif
                                    <h5>Immutable physical disposition queue</h5>
                                    @foreach($noncleanWriteoffHistory as $case)
                                        <article wire:key="writeoff-{{ $case['id'] }}" class="rounded-lg border p-2 text-sm">
                                            <p>{{ $case['kind'] }}: {{ $case['quantity'] }} units. {{ $case['decision'] ?? 'PENDING independent approval' }}</p>
                                            <p>Proposal {{ $case['id'] }}; verified movement {{ $case['movementId'] ?? 'none' }}.</p>
                                        </article>
                                    @endforeach
                                    <label>Pending physical proposal to independently decide
                                        <select wire:model="noncleanWriteoffDecisionProposalId" class="w-full rounded-lg border p-2 text-gray-900">
                                            <option value="">Choose pending proposal</option>
                                            @foreach($noncleanWriteoffHistory as $case)
                                                @if($case['decision'] === null)
                                                    <option value="{{ $case['id'] }}">{{ $case['kind'] }}: {{ $case['quantity'] }} units ({{ $case['id'] }})</option>
                                                @endif
                                            @endforeach
                                        </select>
                                    </label>
                                    <label>Independent decision
                                        <select wire:model="noncleanWriteoffDecision" class="w-full rounded-lg border p-2 text-gray-900">
                                            <option value="rejected">Reject</option>
                                            <option value="approved">Approve witnessed stock disposal</option>
                                        </select>
                                    </label>
                                    <label>Independent review reason
                                        <textarea wire:model="noncleanWriteoffDecisionReason" class="w-full rounded-lg border p-2 text-gray-900"></textarea>
                                    </label>
                                    <button type="button" wire:click="decideNoncleanWriteoff" class="rounded-lg border p-2">Record independent physical disposition decision</button>
                                @endif
                            </section>
                        @endif
                        <p class="text-sm">Loan {{ $financialClaimLoanId }}. Record only evidence-backed proposed amounts in the currency's smallest units (for USD, cents). An internal collection approval is NOT a partner admission, invoice, receivable, payment, stock write-off or final settlement.</p>
                        @if(count($financialClaimEligibleReports)>0)
                            <h4 class="font-semibold">Propose an independently evidenced amount</h4>
                            <label class="block text-sm">Substantiated unclaimed incident
                                <select wire:model="financialClaimReportId" class="w-full rounded-lg border p-2 text-gray-900">
                                    <option value="">Choose independently reviewed evidence</option>
                                    @foreach($financialClaimEligibleReports as $report)
                                        <option value="{{ $report['reportId'] }}">{{ $report['kind'] }}: {{ $report['quantity'] }} units ({{ $report['reportId'] }})</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="block text-sm">ISO currency
                                <input wire:model="financialClaimCurrency" maxlength="3" class="w-full rounded-lg border p-2 text-gray-900">
                            </label>
                            <label class="block text-sm">Proposed amount in integer minor units
                                <input wire:model="financialClaimAmountMinor" type="number" min="1" step="1" class="w-full rounded-lg border p-2 text-gray-900">
                            </label>
                            <label class="block text-sm">Item valuation and evidence explanation
                                <textarea wire:model="financialClaimReason" class="w-full rounded-lg border p-2 text-gray-900"></textarea>
                            </label>
                            @error('financialClaimReason') <p role="alert">{{ $message }}</p> @enderror
                            @error('financialClaimAmountMinor') <p role="alert">{{ $message }}</p> @enderror
                            @error('financialClaimReportId') <p role="alert">{{ $message }}</p> @enderror
                            @error('financialClaimCurrency') <p role="alert">{{ $message }}</p> @enderror
                            <button type="button" wire:click="submitFinancialClaim" class="rounded-lg border px-3 py-2 text-sm">Submit provisional amount for independent owner review</button>
                        @endif
                        <h4 class="font-semibold">Claim history and independent review queue</h4>
                        @forelse($financialClaimHistory as $claim)
                            <article wire:key="provisional-claim-{{ $claim['id'] }}" class="rounded-lg border p-3 text-sm">
                                <p>Claim {{ $claim['id'] }} linked to incident {{ $claim['reportId'] }}: {{ $claim['quantity'] }} units, {{ $claim['currency'] }} {{ $claim['amountMinor'] }} minor units.</p>
                                <p>Assessment: {{ $claim['decision'] ?? 'AWAITING INDEPENDENT REVIEW' }}. Provisional claim alone does not post money. See separate verified recovery journal below if enabled.</p>
                            </article>
                        @empty
                            <p>No financial claim has been proposed for this loan.</p>
                        @endforelse
                        @if(collect($financialClaimHistory)->contains(fn($c)=>$c['decision']===null && $c['proposedBy']!==(int)auth()->id()))
                            <h4 class="font-semibold">Independent owner/admin claim assessment</h4>
                            <label class="block text-sm">Pending claim from another owner
                                <select wire:model="financialClaimDecisionClaimId" class="w-full rounded-lg border p-2 text-gray-900">
                                    <option value="">Choose a pending claim</option>
                                    @foreach($financialClaimHistory as $claim)
                                        @if($claim['decision']===null && $claim['proposedBy']!==(int)auth()->id())
                                            <option value="{{ $claim['id'] }}">{{ $claim['currency'] }} {{ $claim['amountMinor'] }} minor units ({{ $claim['id'] }})</option>
                                        @endif
                                    @endforeach
                                </select>
                            </label>
                            <label class="block text-sm">Decision
                                <select wire:model="financialClaimDecision" class="w-full rounded-lg border p-2 text-gray-900">
                                    <option value="disputed">Disputed / more resolution required</option>
                                    <option value="approved_for_collection">Approve collection attempt, NOT recognized debt</option>
                                    <option value="rejected">Reject proposed amount</option>
                                </select>
                            </label>
                            <label class="block text-sm">Independent reasoning
                                <textarea wire:model="financialClaimDecisionReason" class="w-full rounded-lg border p-2 text-gray-900"></textarea>
                            </label>
                            @error('financialClaimDecisionReason') <p role="alert">{{ $message }}</p> @enderror
                            <button type="button" wire:click="submitFinancialClaimDecision" class="rounded-lg border px-3 py-2 text-sm">Record immutable independent claim assessment</button>
                        @endif
                        @if($lastFinancialClaimReference)
                            <p role="status">Financial claim/review {{ $lastFinancialClaimReference }} saved. No receivable, payment, financial journal, stock write-off or final closure recorded.</p>
                        @endif
                        @if(config('inventory_controls.loan_partner_responses_enabled'))
                            <h4 class="font-semibold">Private outside partner correspondence and disputes</h4>
                            <p class="text-sm">Only owner-recorded copies of partner documents are captured. The document is not authenticated by uploading it. Even a document describing acceptance or an offer is NOT a legally confirmed debt, a paid collection, or a posted accounting entry.</p>
                            @if(collect($financialClaimHistory)->contains(fn($c)=>in_array($c['decision'],['approved_for_collection','disputed'],true)))
                                <label class="block text-sm">Reviewed claim linked to correspondence
                                    <select wire:model="financialPartnerClaimId" class="w-full rounded-lg border p-2 text-gray-900">
                                        <option value="">Choose claim</option>
                                        @foreach($financialClaimHistory as $claim)
                                            @if(in_array($claim['decision'],['approved_for_collection','disputed'],true))
                                                <option value="{{ $claim['id'] }}">{{ $claim['currency'] }} {{ $claim['amountMinor'] }} ({{ $claim['id'] }})</option>
                                            @endif
                                        @endforeach
                                    </select>
                                </label>
                                <label class="block text-sm">What the submitted document appears to state
                                    <select wire:model="financialPartnerPosition" class="w-full rounded-lg border p-2 text-gray-900">
                                        <option value="document_says_disputes">Partner document disputes claim</option>
                                        <option value="document_says_accepts">Document appears to accept proposed claim, authenticity NOT verified</option>
                                        <option value="counteroffer">Counteroffer documented</option>
                                        <option value="other_document">Other correspondence</option>
                                    </select>
                                </label>
                                <label class="block text-sm">Counteroffer in minor units (only when Counteroffer selected)
                                    <input wire:model="financialPartnerCounterofferMinor" type="number" min="1" step="1" class="w-full rounded-lg border p-2 text-gray-900">
                                </label>
                                <label class="block text-sm">Private signed correspondence PDF/photo, maximum 5 MB
                                    <input type="file" wire:model="financialPartnerFile" accept=".pdf,.png,.jpeg,.jpg"
                                        class="w-full rounded-lg border p-2">
                                </label>
                                @error('financialPartnerClaimId') <p role="alert">{{ $message }}</p> @enderror
                                @error('financialPartnerFile') <p role="alert">{{ $message }}</p> @enderror
                                @error('financialPartnerCounterofferMinor') <p role="alert">{{ $message }}</p> @enderror
                                <button type="button" wire:click="submitFinancialPartnerEvidence"
                                    class="rounded-lg border px-3 py-2 text-sm">Record private partner document without posting money</button>
                            @endif
                            <h4 class="font-semibold">Partner document custody and independent review</h4>
                            @forelse($financialPartnerHistory as $document)
                                <article wire:key="finance-partner-doc-{{ $document['id'] }}" class="rounded-lg border p-3 text-sm">
                                    <p>Document {{ $document['id'] }}: {{ $document['position'] }} for claim {{ $document['claimId'] }}.
                                        @if($document['counterofferMinor']!==null)
                                            Counteroffer {{ $document['currency'] }} {{ $document['counterofferMinor'] }} minor units.
                                        @endif
                                    </p>
                                    <p>Review: {{ $document['assessment'] ?? 'PENDING DOCUMENT REVIEW' }}.
                                        Partner identity verified: NO. Paid: {{ $document['paidMinor'] }}. Journal posted: NO.</p>
                                    <button type="button" wire:click="downloadFinancialPartnerDocument('{{ $document['id'] }}')"
                                        class="rounded-lg border px-3 py-2 text-sm">Download hash-checked private correspondence</button>
                                </article>
                            @empty
                                <p>No partner correspondence recorded for this loan.</p>
                            @endforelse
                            @if(collect($financialPartnerHistory)->contains(fn($d)=>$d['assessment']===null &&
                                $d['recordedBy']!==(int)auth()->id() &&
                                $d['proposedBy']!==(int)auth()->id()))
                                <label class="block text-sm">Unreviewed document submitted by another owner
                                    <select wire:model="financialPartnerReviewEvidenceId" class="w-full rounded-lg border p-2 text-gray-900">
                                        <option value="">Choose document</option>
                                        @foreach($financialPartnerHistory as $document)
                                            @if($document['assessment']===null &&
                                                $document['recordedBy']!==(int)auth()->id() &&
                                                $document['proposedBy']!==(int)auth()->id())
                                                <option value="{{ $document['id'] }}">{{ $document['position'] }} ({{ $document['id'] }})</option>
                                            @endif
                                        @endforeach
                                    </select>
                                </label>
                                <label class="block text-sm">Independent documentary review
                                    <select wire:model="financialPartnerAssessment" class="w-full rounded-lg border p-2 text-gray-900">
                                        <option value="insufficient_partner_proof">Insufficient partner proof</option>
                                        <option value="document_integrity_confirmed">Stored document matches upload; authenticity NOT proven</option>
                                    </select>
                                </label>
                                <label class="block text-sm">Review explanation
                                    <textarea wire:model="financialPartnerReviewReason" class="w-full rounded-lg border p-2 text-gray-900"></textarea>
                                </label>
                                @error('financialPartnerReviewReason') <p role="alert">{{ $message }}</p> @enderror
                                <button type="button" wire:click="submitFinancialPartnerReview"
                                    class="rounded-lg border px-3 py-2 text-sm">Save independent immutable documentary review</button>
                            @endif
                            @if($lastFinancialPartnerReference)
                                <p role="status">Documentary record {{ $lastFinancialPartnerReference }} saved. No accepted debt, receipt, recovery, journal or physical write-off.</p>
                            @endif
                        @endif
                        @if(config('inventory_controls.loan_authorized_obligations_enabled') &&
                            config('inventory_controls.loan_partner_responses_enabled'))
                            <h4 class="font-semibold">Manually attested collectible obligation approval</h4>
                            <p class="text-sm">Requires an independently reviewed partner acceptance or counteroffer document, a real separately verified contact or contract identity reference and a different owner's written decision. This does NOT post accounts receivable, record a payment, waive unresolved custody, or close the loan.</p>
                            @if(collect($financialPartnerHistory)->contains(fn($d)=>
                                in_array($d['position'],['document_says_accepts','counteroffer'],true) &&
                                $d['assessment']==='document_integrity_confirmed'))
                                <label class="block text-sm">Independently reviewed partner agreement document
                                    <select wire:model="financialObligationEvidenceId" class="w-full rounded-lg border p-2 text-gray-900">
                                        <option value="">Select agreement evidence</option>
                                        @foreach($financialPartnerHistory as $document)
                                            @if(in_array($document['position'],['document_says_accepts','counteroffer'],true) &&
                                                $document['assessment']==='document_integrity_confirmed')
                                                <option value="{{ $document['id'] }}">{{ $document['position'] }} / claim {{ $document['claimId'] }}</option>
                                            @endif
                                        @endforeach
                                    </select>
                                </label>
                                <label class="block text-sm">Proposed collectible amount in currency minor units, never greater than agreed offer
                                    <input type="number" min="1" step="1" wire:model="financialObligationAmountMinor" class="w-full rounded-lg border p-2 text-gray-900">
                                </label>
                                <label class="block text-sm">Actual partner identity verification method
                                    <select wire:model="financialObligationIdentityMethod" class="w-full rounded-lg border p-2 text-gray-900">
                                        <option value="verified_callback">Independently verified partner callback</option>
                                        <option value="in_person_signed">In-person signer identity checked</option>
                                        <option value="trusted_contract_record">Matched approved partner contract</option>
                                    </select>
                                </label>
                                <label class="block text-sm">Auditable verification reference (12 to 200 characters, avoid private contacts)
                                    <input wire:model="financialObligationIdentityReference" maxlength="200" class="w-full rounded-lg border p-2 text-gray-900">
                                </label>
                                <label class="block text-sm">Reason and proposed obligation basis
                                    <textarea wire:model="financialObligationReason" class="w-full rounded-lg border p-2 text-gray-900"></textarea>
                                </label>
                                @error('financialObligationReason') <p role="alert">{{ $message }}</p> @enderror
                                @error('financialObligationAmountMinor') <p role="alert">{{ $message }}</p> @enderror
                                @error('financialObligationIdentityReference') <p role="alert">{{ $message }}</p> @enderror
                                <button type="button" wire:click="submitFinancialObligation" class="rounded-lg border px-3 py-2 text-sm">Propose verified-document obligation for independent review</button>
                            @endif
                            <h4 class="font-semibold">Independent obligation decisions</h4>
                            @forelse($financialObligationHistory as $obligation)
                                <article wire:key="fin-obligation-{{ $obligation['id'] }}" class="rounded-lg border p-3 text-sm">
                                    <p>Proposal {{ $obligation['id'] }}, claim {{ $obligation['claimId'] }},
                                       {{ $obligation['currency'] }} {{ $obligation['amountMinor'] }} minor units.
                                       Manual check: {{ $obligation['manualCheck'] }}.</p>
                                    <p>Decision: {{ $obligation['decision'] ?? 'PENDING INDEPENDENT OBLIGATION REVIEW' }}.
                                       This obligation approval does not post money. See verified recovery ledger below if enabled.</p>
                                </article>
                            @empty
                                <p>No attested financial obligation proposal for this loan.</p>
                            @endforelse
                            @if(collect($financialObligationHistory)->contains(fn($o)=>
                                $o['decision']===null && $o['proposedBy']!==(int)auth()->id()))
                                <label class="block text-sm">Pending obligation proposed by another owner
                                    <select wire:model="financialObligationDecisionProposalId" class="w-full rounded-lg border p-2 text-gray-900">
                                        <option value="">Choose proposal</option>
                                        @foreach($financialObligationHistory as $obligation)
                                            @if($obligation['decision']===null && $obligation['proposedBy']!==(int)auth()->id())
                                                <option value="{{ $obligation['id'] }}">{{ $obligation['currency'] }} {{ $obligation['amountMinor'] }} ({{ $obligation['id'] }})</option>
                                            @endif
                                        @endforeach
                                    </select>
                                </label>
                                <label class="block text-sm">Independent owner decision
                                    <select wire:model="financialObligationDecision" class="w-full rounded-lg border p-2 text-gray-900">
                                        <option value="disputed">Dispute or require more evidence</option>
                                        <option value="authorized">Authorize collection obligation, not accounting</option>
                                        <option value="rejected">Reject obligation</option>
                                    </select>
                                </label>
                                <label class="block text-sm">Decision reason
                                    <textarea wire:model="financialObligationDecisionReason" class="w-full rounded-lg border p-2 text-gray-900"></textarea>
                                </label>
                                @error('financialObligationDecisionReason') <p role="alert">{{ $message }}</p> @enderror
                                <button type="button" wire:click="submitFinancialObligationDecision"
                                    class="rounded-lg border px-3 py-2 text-sm">Record independent immutable obligation decision</button>
                            @endif
                            @if($lastFinancialObligationReference)
                                <p role="status">Agreement decision {{ $lastFinancialObligationReference }} recorded. No receivable journal or cash collection was posted.</p>
                            @endif
                        @endif
                        @if(config('inventory_controls.loan_recoveries_enabled') &&
                            config('inventory_controls.loan_authorized_obligations_enabled'))
                            <h4 class="font-semibold">Separately verified partial and full loss recoveries</h4>
                            <p class="text-sm">Payment evidence is not money until independently verified. This section records CASH-BASIS recovery journals only after an authorized financial agreement, a second current owner and the separate finance posting release flag. It does not automatically create an invoice, accrual receivable, damage write-off, dispute waiver or final loan closure.</p>
                            @foreach($financialRecoveryBalances as $obligationId=>$balance)
                                <p wire:key="recovery-balance-{{ $obligationId }}">Obligation {{ $obligationId }} ({{ $balance['currency'] }}):
                                    authorized {{ $balance['amountMinor'] }}, independently verified net recovered {{ $balance['paidMinor'] }},
                                    pending verification {{ $balance['pendingMinor'] }}, outstanding {{ $balance['remainingMinor'] }} minor units.
                                </p>
                            @endforeach
                            @if(count($financialRecoveryBalances)>0)
                                <label class="block text-sm">Authorized obligation for payment evidence
                                    <select wire:model="financialRecoveryObligationId" class="w-full rounded-lg border p-2 text-gray-900">
                                        <option value="">Select authorized agreement</option>
                                        @foreach($financialRecoveryBalances as $obligationId=>$balance)
                                            @if($balance['unreservedMinor']>0)
                                                <option value="{{ $obligationId }}">{{ $balance['currency'] }} {{ $balance['unreservedMinor'] }} unreserved ({{ $obligationId }})</option>
                                            @endif
                                        @endforeach
                                    </select>
                                </label>
                                <label class="block text-sm">Actually evidenced payment, integer minor units
                                    <input type="number" min="1" step="1" wire:model="financialRecoveryAmountMinor" class="w-full rounded-lg border p-2 text-gray-900">
                                </label>
                                <label class="block text-sm">Documented payment method
                                    <select wire:model="financialRecoveryMethod" class="w-full rounded-lg border p-2 text-gray-900">
                                        <option value="bank">Bank transfer, not verified until bank statement checked</option>
                                        <option value="cash">Physically witnessed cash, not verified until independent cash count</option>
                                    </select>
                                </label>
                                <label class="block text-sm">Bank transaction reference (only for bank, unique within company)
                                    <input wire:model="financialRecoveryBankReference" maxlength="120" class="w-full rounded-lg border p-2 text-gray-900">
                                </label>
                                <label class="block text-sm">Signed original payment evidence, private PDF/photo up to 5 MB
                                    <input type="file" wire:model="financialRecoveryFile" accept=".pdf,.png,.jpg,.jpeg" class="w-full rounded-lg border p-2">
                                </label>
                                @error('financialRecoveryFile') <p role="alert">{{ $message }}</p> @enderror
                                @error('financialRecoveryBankReference') <p role="alert">{{ $message }}</p> @enderror
                                @error('financialRecoveryAmountMinor') <p role="alert">{{ $message }}</p> @enderror
                                <button type="button" wire:click="submitFinancialRecovery" class="rounded-lg border px-3 py-2 text-sm">Record unverified payment evidence for independent review</button>
                            @endif
                            <h4 class="font-semibold">Recovery history, journals and corrections</h4>
                            @forelse($financialRecoveryHistory as $payment)
                                <article wire:key="fin-recovery-{{ $payment['id'] }}" class="rounded-lg border p-3 text-sm">
                                    <p>Evidence {{ $payment['id'] }}: {{ $payment['currency'] }} {{ $payment['amountMinor'] }} via {{ $payment['method'] }}.</p>
                                    <p>Status: {{ $payment['decision'] ?? 'AWAITING INDEPENDENT PAYMENT VERIFICATION' }}.
                                       Journal: {{ $payment['journalId'] ?? 'NONE' }}.
                                       Reversal: {{ $payment['reversalId'] ?? 'NONE' }}.</p>
                                    <button type="button" wire:click="downloadFinancialRecoveryEvidence('{{ $payment['id'] }}')"
                                        class="rounded-lg border px-3 py-2 text-sm">Download private hash-verified money evidence</button>
                                </article>
                            @empty
                                <p>No partner payment evidence recorded for this loan.</p>
                            @endforelse
                            @if(collect($financialRecoveryHistory)->contains(fn($p)=>
                                $p['decision']===null && $p['recordedBy']!==(int)auth()->id()))
                                <h5 class="font-semibold">Second owner independently verifies payment proof</h5>
                                <label class="block text-sm">Pending payment from a different actor
                                    <select wire:model="financialRecoveryReceiptId" class="w-full rounded-lg border p-2 text-gray-900">
                                        <option value="">Choose pending payment</option>
                                        @foreach($financialRecoveryHistory as $payment)
                                            @if($payment['decision']===null && $payment['recordedBy']!==(int)auth()->id())
                                                <option value="{{ $payment['id'] }}">{{ $payment['currency'] }} {{ $payment['amountMinor'] }} ({{ $payment['id'] }})</option>
                                            @endif
                                        @endforeach
                                    </select>
                                </label>
                                <label class="block text-sm">Independent outcome
                                    <select wire:model="financialRecoveryDecision" class="w-full rounded-lg border p-2 text-gray-900">
                                        <option value="rejected">Reject unsubstantiated payment</option>
                                        @if(config('finance_controls.posting_enabled'))
                                            <option value="confirmed">Confirm verified actual receipt and post balanced journal</option>
                                        @endif
                                    </select>
                                </label>
                                <label class="block text-sm">Independent verification method
                                    <select wire:model="financialRecoveryVerificationMethod" class="w-full rounded-lg border p-2 text-gray-900">
                                        <option value="verified_bank_statement">Matched actual bank statement</option>
                                        <option value="witnessed_cash_count">Separately witnessed cash count</option>
                                    </select>
                                </label>
                                <label class="block text-sm">Bank statement or separate witness/cash count reference
                                    <input wire:model="financialRecoveryVerificationReference" maxlength="160" class="w-full rounded-lg border p-2 text-gray-900">
                                </label>
                                <label class="block text-sm">Verification decision reasoning
                                    <textarea wire:model="financialRecoveryReviewReason" class="w-full rounded-lg border p-2 text-gray-900"></textarea>
                                </label>
                                @error('financialRecoveryReviewReason') <p role="alert">{{ $message }}</p> @enderror
                                <button type="button" wire:click="submitFinancialRecoveryReview" class="rounded-lg border px-3 py-2 text-sm">Save immutable independent payment verification</button>
                            @endif
                            @if(config('finance_controls.posting_enabled') &&
                                collect($financialRecoveryHistory)->contains(fn($p)=>
                                    $p['decision']==='confirmed' && $p['reversalId']===null &&
                                    $p['reviewedBy']!==(int)auth()->id()))
                                <h5 class="font-semibold">Correct erroneous posted recovery by balanced reversal</h5>
                                <label class="block text-sm">Verified posted receipt requiring correction
                                    <select wire:model="financialRecoveryReversalReceiptId" class="w-full rounded-lg border p-2 text-gray-900">
                                        <option value="">Select posted receipt</option>
                                        @foreach($financialRecoveryHistory as $payment)
                                            @if($payment['decision']==='confirmed' && $payment['reversalId']===null &&
                                                $payment['reviewedBy']!==(int)auth()->id())
                                                <option value="{{ $payment['id'] }}">{{ $payment['currency'] }} {{ $payment['amountMinor'] }} ({{ $payment['id'] }})</option>
                                            @endif
                                        @endforeach
                                    </select>
                                </label>
                                <label class="block text-sm">Why the original receipt was recorded in error, 15 to 500 characters
                                    <textarea wire:model="financialRecoveryReversalReason" class="w-full rounded-lg border p-2 text-gray-900"></textarea>
                                </label>
                                @error('financialRecoveryReversalReason') <p role="alert">{{ $message }}</p> @enderror
                                <button type="button" wire:click="reverseFinancialRecovery" class="rounded-lg border px-3 py-2 text-sm">Post exact balanced reversal, preserve original journal</button>
                            @endif
                            @if($lastFinancialRecoveryReference)
                                <p role="status">Payment, review or financial correction {{ $lastFinancialRecoveryReference }} saved. Finance and custody histories remain independent. No final loan closure.</p>
                            @endif
                        @endif



                    </section>
                @endif
                @if(config('inventory_controls.external_out_dispatch_enabled') && $externalOutLoanId !== '')
                    <section class="rounded-xl border p-4 space-y-3" aria-label="Witness outside lending physical handover">
                        <h3 class="font-semibold">Outside lending physical handover — independent witness</h3>
                        <p>Loan {{ $externalOutLoanId }}. Enter physical counts; a different owner/admin must approve before owned stock is posted ON LOAN. No finance or closure is posted.</p>
                        @foreach($loanHistory as $outLoan)
                            @if($outLoan['id']===$externalOutLoanId)
                                @foreach($outLoan['lines'] as $outLine)
                                    <label wire:key="ext-out-qty-{{ $outLine['itemId'] }}" class="block text-sm">
                                        {{ $outLine['name'] }} (requested {{ $outLine['quantity'] }} {{ $outLine['unit'] }})
                                        <input type="number" min="0" max="100000000" step="1" required
                                            wire:model="externalOutCounts.{{ $outLine['itemId'] }}"
                                            class="w-full rounded-lg border p-2 text-gray-900">
                                    </label>
                                @endforeach
                            @endif
                        @endforeach
                        @error('externalOutCounts') <p role="alert">{{ $message }}</p> @enderror
                        <label class="block text-sm">Outside partner signed physical handover, PDF/photo up to 5 MB
                            <input type="file" wire:model="externalOutFile" accept=".jpg,.jpeg,.png,.pdf"
                                class="w-full rounded-lg border p-2"></label>
                        @error('externalOutFile') <p role="alert">{{ $message }}</p> @enderror
                        <button type="button" wire:click="submitExternalOutHandover"
                            class="rounded-lg bg-emerald-800 px-4 py-2 text-sm text-white">Submit witnessed outside lending handover</button>
                    </section>
                @endif
                @if($lastExternalOutReference)
                    <p role="status">Outside lending custody reference {{ $lastExternalOutReference }}. Only independent approval can move owned goods; no money posted.</p>
                @endif
                @if(config('inventory_controls.external_out_return_enabled') &&
                    config('inventory_controls.external_out_dispatch_enabled'))
                    <section class="rounded-xl border p-4 space-y-3" aria-label="External owned stock physical return history">
                        <h3 class="font-semibold">Outside partner returns of hall-owned stock</h3>
                        <p>Signed actual partner handovers require independent owner/admin condition counts. Unreviewed or unresolved units stay ON LOAN. No monetary liability or settlement is created here.</p>
                        @foreach($externalOutReturnHistory as $outsideReceipt)
                            <article wire:key="ext-owned-return-{{ $outsideReceipt['receiptId'] }}"
                                class="rounded-lg border p-3 text-sm space-y-2">
                                <p>Loan {{ $outsideReceipt['loanId'] }}; physical return {{ $outsideReceipt['receiptId'] }}
                                    — {{ $outsideReceipt['reviewed'] ? 'INDEPENDENT PHYSICAL CONDITION REVIEWED' : 'INDEPENDENT REVIEW PENDING' }}</p>
                                <ul class="list-inside list-disc">
                                    @foreach($outsideReceipt['lines'] as $line)
                                        <li>Item {{ $line['itemId'] }}: {{ $line['quantity'] }} units physically returned</li>
                                    @endforeach
                                </ul>
                                @if($outsideReceipt['reviewed'])
                                    <ul class="list-inside list-disc">
                                        @foreach($outsideReceipt['conditions'] as $line)
                                            <li>Item {{ $line['itemId'] }}: good {{ $line['good'] }},
                                                damaged {{ $line['damaged'] }},
                                                unresolved {{ $line['unresolved'] }} — unresolved stays ON LOAN</li>
                                        @endforeach
                                    </ul>
                                @elseif($this->canApproveExternalOutDispatch() &&
                                    $outsideReceipt['receivedBy']!==(int)auth()->id())
                                    <button type="button" wire:click="startExternalOutReturnReview('{{ $outsideReceipt['receiptId'] }}')"
                                        class="rounded-lg border px-3 py-2 text-sm">Independently assess outside partner physical return</button>
                                @endif
                            </article>
                        @endforeach
                    </section>
                @endif
                @if(config('inventory_controls.external_out_return_enabled') && $externalOutReturnLoanId !== '')
                    <section class="rounded-xl border p-4 space-y-3" aria-label="Record outside owned stock return">
                        <h3 class="font-semibold">Witness actual partial outside partner return</h3>
                        <p>Loan {{ $externalOutReturnLoanId }}. Only items physically returned now; leave others blank. Partner-signed evidence is mandatory.</p>
                        @foreach($loanHistory as $outLoan)
                            @if($outLoan['id']===$externalOutReturnLoanId)
                                @foreach($outLoan['lines'] as $line)
                                    <label wire:key="ext-owned-partial-{{ $line['itemId'] }}" class="block text-sm">
                                        {{ $line['name'] }} (original {{ $line['quantity'] }})
                                        <input type="number" min="1" max="{{ $line['quantity'] }}" step="1"
                                            wire:model="externalOutReturnQuantities.{{ $line['itemId'] }}"
                                            class="w-full rounded-lg border p-2 text-gray-900" placeholder="Blank when not physically returned">
                                    </label>
                                @endforeach
                            @endif
                        @endforeach
                        @error('externalOutReturnQuantities') <p role="alert">{{ $message }}</p> @enderror
                        <label class="block text-sm">Partner-signed physical return PDF/photo, maximum 5 MB
                            <input type="file" wire:model="externalOutReturnFile" accept=".jpg,.jpeg,.png,.pdf"
                                class="w-full rounded-lg border p-2"></label>
                        @error('externalOutReturnFile') <p role="alert">{{ $message }}</p> @enderror
                        <button type="button" wire:click="submitExternalOutReturn"
                            class="rounded-lg bg-emerald-800 px-4 py-2 text-sm text-white">Record witnessed partner return only</button>
                    </section>
                @endif
                @if(config('inventory_controls.external_out_return_enabled') && $externalOutReviewReceiptId !== '')
                    <section class="rounded-xl border p-4 space-y-3" aria-label="Independent outside partner return condition review">
                        <h3 class="font-semibold">Independent owner inspection — outside partner return</h3>
                        <p>Receipt {{ $externalOutReviewReceiptId }}. Enter separate physically good, damaged and unresolved counts; their sum MUST exactly equal each witnessed return line.</p>
                        @foreach($externalOutReturnHistory as $outsideReceipt)
                            @if($outsideReceipt['receiptId']===$externalOutReviewReceiptId)
                                @foreach($outsideReceipt['lines'] as $line)
                                    <fieldset wire:key="ext-owned-review-{{ $line['itemId'] }}" class="rounded-lg border p-3 space-y-1">
                                        <legend class="font-medium">Item {{ $line['itemId'] }}; actual received {{ $line['quantity'] }}</legend>
                                        <label class="block text-sm">Good
                                            <input type="number" min="0" step="1" wire:model="externalOutReviewGood.{{ $line['itemId'] }}"
                                                class="w-full rounded-lg border p-2 text-gray-900"></label>
                                        <label class="block text-sm">Damaged
                                            <input type="number" min="0" step="1" wire:model="externalOutReviewDamaged.{{ $line['itemId'] }}"
                                                class="w-full rounded-lg border p-2 text-gray-900"></label>
                                        <label class="block text-sm">Unresolved
                                            <input type="number" min="0" step="1" wire:model="externalOutReviewUnresolved.{{ $line['itemId'] }}"
                                                class="w-full rounded-lg border p-2 text-gray-900"></label>
                                    </fieldset>
                                @endforeach
                            @endif
                        @endforeach
                        @error('externalOutReviewGood') <p role="alert">{{ $message }}</p> @enderror
                        <button type="button" wire:click="submitExternalOutReturnReview"
                            class="rounded-lg bg-emerald-800 px-4 py-2 text-sm text-white">Independently record partner-return condition</button>
                    </section>
                @endif
                @if($lastExternalOutReturnReference)
                    <p role="status">Outside partner return evidence/review {{ $lastExternalOutReturnReference }}. No financial entry, debt or loan closure posted.</p>
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
                @if(config('inventory_controls.internal_discrepancy_enabled') && $discrepancyLoanId !== '')
                    <section class="space-y-2 rounded-xl border p-4" aria-label="Signed internal discrepancy evidence">
                        <h3>Original internal loan signed discrepancy evidence (no stock or money posting)</h3>
                        <p>Loan {{ $discrepancyLoanId }}. Statements are testimony only, not borrower acceptance or liability.</p>
                        @if(count($discrepancyLines)>0)
                            <select wire:model="discrepancyItemId" aria-label="Original discrepancy item">
                                <option value="">Select original loan item</option>
                                @foreach($discrepancyLines as $line)
                                    <option value="{{ $line['sourceItemId'] }}">{{ $line['name'] }} — originally received {{ $line['receivedQuantity'] }}</option>
                                @endforeach
                            </select>
                            <select wire:model="discrepancyKind" aria-label="Discrepancy kind">
                                <option value="missing">Missing</option><option value="dispute">Disputed count</option>
                            </select>
                            <input type="number" wire:model="discrepancyQuantity" min="1" aria-label="Claimed discrepancy quantity" placeholder="Claimed quantity">
                            <input wire:model="discrepancyReason" aria-label="Signed statement reason" placeholder="Explain physically observed discrepancy">
                            <input type="file" wire:model="discrepancyFile" accept=".pdf,.jpg,.jpeg,.png" aria-label="Signed borrower physical evidence">
                            <button type="button" wire:click="submitInternalDiscrepancy">Submit signed borrowing-hall statement</button>
                            @error('discrepancyFile') <p role="alert">{{ $message }}</p> @enderror
                        @endif
                        @foreach($discrepancyHistory as $e)
                            <p>Statement {{ $e['id'] }} item {{ $e['itemId'] }}: {{ $e['quantity'] }} {{ $e['kind'] }} — {{ $e['decision'] ?? 'SOURCE REVIEW PENDING' }}; SHA256 {{ $e['sha256'] }}. No financial posting or stock change.</p>
                            <button type="button" wire:click="downloadInternalBorrowerSignedProof('{{ $e['id'] }}')" class="rounded border px-2 py-1">Download original signed borrower proof (audited)</button>
                        @endforeach
                        @if(count($discrepancyLines)===0)
                            <select wire:model="discrepancyReviewStatementId" aria-label="Independent signed statement review">
                                <option value="">Choose pending signed borrower evidence</option>
                                @foreach($discrepancyHistory as $e)
                                    @if($e['decision']===null)
                                        <option value="{{ $e['id'] }}">{{ $e['id'] }} — {{ $e['kind'] }} {{ $e['quantity'] }}</option>
                                    @endif
                                @endforeach
                            </select>
                            <select wire:model="discrepancyReviewDecision" aria-label="Independent discrepancy decision">
                                <option value="requires_more_evidence">More evidence required</option>
                                <option value="corroborated">Evidence corroborated only</option>
                                <option value="disputed">Dispute statement</option>
                            </select>
                            <input wire:model="discrepancyReviewReason" aria-label="Independent review reason" placeholder="Independent review reason">
                            <button type="button" wire:click="decideInternalDiscrepancy">Record independent source review</button>
                        @endif
                        @if(config('inventory_controls.internal_physical_finding_enabled'))
                            <div class="space-y-2 rounded-lg border p-3" aria-label="Independent original-loan physical findings">
                                <h4 class="font-semibold">Original loan physical discrepancy findings</h4>
                                <p class="text-sm">Signed borrower statement and separately substantiated lender incident must agree on the same original loan, item, missing/disputed kind and exact quantity. Confirmed findings keep outstanding physical custody OPEN. They are not returns, stock write-offs, financial admissions, settlements or final receipts.</p>
                                @foreach($physicalFindingHistory as $finding)
                                    <article wire:key="physical-finding-{{ $finding['id'] }}" class="rounded-lg border p-2 text-sm">
                                        <p>Finding {{ $finding['id'] }}: {{ $finding['kind'] }} {{ $finding['quantity'] }} original item {{ $finding['itemId'] }}</p>
                                        <p>{{ $finding['decision'] ?? 'INDEPENDENT REVIEW PENDING' }}. Signed source proof SHA256 {{ $finding['sha256'] }}. Stock and money unchanged.</p>
                                        @if($finding['supersededByIndependentCorrection'])
                                            <p>Historical original finding superseded by separately signed third-person correction {{ $finding['correctionId'] }}. Not a physical return, debt waiver or stock retirement.</p>
                                        @endif
                                        <p>Original-loan physical units currently open (not independently accepted good/damaged or merely in-transit): {{ $finding['currentPhysicalOutstanding'] }}.
                                            @unless($finding['historicalFindingStillFullyOutstanding'])
                                                Historical discrepancy evidence is STALE after later physical return activity. New reconciliation and independent review required; do not charge or close from this finding.
                                            @endunless
                                        </p>
                                        <button type="button" wire:click="downloadInternalPhysicalFindingProof('{{ $finding['id'] }}')" class="rounded border px-2 py-1">Private source evidence (audited)</button>
                                    </article>
                                @endforeach
                                @if(count($discrepancyLines)===0 && $this->canReviewInternalDiscrepancy())
                                    <label>Corroborated borrower signed original-loan statement
                                        <select wire:model="physicalFindingStatementId" class="w-full rounded border p-2">
                                            <option value="">Choose signed borrower statement</option>
                                            @foreach($discrepancyHistory as $statement)
                                                @if($statement['decision']==='corroborated')
                                                    <option value="{{ $statement['id'] }}">{{ $statement['id'] }}: {{ $statement['kind'] }} {{ $statement['quantity'] }} item {{ $statement['itemId'] }}</option>
                                                @endif
                                            @endforeach
                                        </select>
                                    </label>
                                    <label>Independently substantiated original source incident
                                        <select wire:model="physicalFindingIncidentId" class="w-full rounded border p-2">
                                            <option value="">Choose independently reviewed lender incident</option>
                                            @foreach($physicalFindingIncidents as $incident)
                                                @if($incident['decision']==='substantiated' && in_array($incident['kind'],['missing','dispute'],true))
                                                    <option value="{{ $incident['reportId'] }}">{{ $incident['reportId'] }}: {{ $incident['kind'] }} {{ $incident['quantity'] }} item {{ $incident['itemId'] }}</option>
                                                @endif
                                            @endforeach
                                        </select>
                                    </label>
                                    <label>Source physical investigation reason <textarea wire:model="physicalFindingReason" class="w-full rounded border p-2"></textarea></label>
                                    <label>Signed source PDF/photo <input type="file" wire:model="physicalFindingFile" accept=".pdf,.jpg,.jpeg,.png"></label>
                                    <button type="button" wire:click="submitInternalPhysicalFinding" class="rounded border px-3 py-2">File original-loan source physical proof for independent review</button>
                                    @error('physicalFindingFile') <p role="alert">{{ $message }}</p> @enderror
                                    <label>Independent decision on a pending physical finding
                                        <select wire:model="physicalFindingDecisionTargetId" class="w-full rounded border p-2">
                                            <option value="">Choose pending finding</option>
                                            @foreach($physicalFindingHistory as $finding)
                                                @if($finding['decision']===null)
                                                    <option value="{{ $finding['id'] }}">{{ $finding['id'] }}: {{ $finding['kind'] }} {{ $finding['quantity'] }}</option>
                                                @endif
                                            @endforeach
                                        </select>
                                    </label>
                                    <label>Decision
                                        <select wire:model="physicalFindingDecision" class="w-full rounded border p-2">
                                            <option value="requires_more_evidence">More physical evidence required</option>
                                            <option value="confirmed_outstanding">Corroborated unreturned units, custody still OPEN</option>
                                            <option value="rejected">Reject physical finding</option>
                                        </select>
                                    </label>
                                    <label>Independent physical reviewer reason <textarea wire:model="physicalFindingDecisionReason" class="w-full rounded border p-2"></textarea></label>
                                    <button type="button" wire:click="decideInternalPhysicalFinding" class="rounded border px-3 py-2">Record immutable source review</button>
                                    @error('physicalFindingDecisionReason') <p role="alert">{{ $message }}</p> @enderror
                                    <div class="space-y-2 rounded-lg border p-3" aria-label="Independent immutable physical finding correction">
                                        <h5 class="font-semibold">Correct historical physical discrepancy finding (never silently erase)</h5>
                                        <p>After a confirmed source finding, a current source owner/admin may file new signed correction proof. A THIRD independent owner/admin must approve the correction. The original signed evidence stays visible. This never posts a return, waives debt, deletes stock or closes a loan.</p>
                                        <label>Original confirmed finding
                                            <select wire:model="physicalCorrectionFindingId" class="w-full rounded border p-2">
                                                <option value="">Choose a confirmed and not yet corrected finding</option>
                                                @foreach($physicalFindingHistory as $finding)
                                                    @if($finding['decision']==='confirmed_outstanding' && $finding['correctionId']===null)
                                                        <option value="{{ $finding['id'] }}">{{ $finding['id'] }}: {{ $finding['kind'] }} {{ $finding['quantity'] }}</option>
                                                    @endif
                                                @endforeach
                                            </select>
                                        </label>
                                        <label>Reason for correction <textarea wire:model="physicalCorrectionReason" class="w-full rounded border p-2"></textarea></label>
                                        <label>New signed original source PDF/photo <input type="file" wire:model="physicalCorrectionFile" accept=".pdf,.jpg,.jpeg,.png"></label>
                                        <button type="button" wire:click="submitInternalFindingCorrection" class="rounded border px-3 py-2">Submit signed correction for third-person review</button>
                                        @error('physicalCorrectionFile') <p role="alert">{{ $message }}</p> @enderror
                                        <h5>Immutable signed correction review queue</h5>
                                        @foreach($physicalCorrectionHistory as $correction)
                                            <article wire:key="internal-correction-{{ $correction['id'] }}" class="rounded border p-2">
                                                <p>Correction {{ $correction['id'] }} of finding {{ $correction['findingId'] }}: {{ $correction['decision'] ?? 'THIRD OWNER REVIEW PENDING' }}. {{ $correction['reason'] }}</p>
                                                <p>Signed correction proof SHA256 {{ $correction['sha256'] }}. Stock and finance unchanged.</p>
                                                <button type="button" wire:click="downloadInternalFindingCorrectionProof('{{ $correction['id'] }}')" class="rounded border px-2 py-1">Private correction proof (audited)</button>
                                            </article>
                                        @endforeach
                                        <label>Pending independent correction
                                            <select wire:model="physicalCorrectionReviewTargetId" class="w-full rounded border p-2">
                                                <option value="">Choose pending signed correction</option>
                                                @foreach($physicalCorrectionHistory as $correction)
                                                    @if($correction['decision']===null)
                                                        <option value="{{ $correction['id'] }}">{{ $correction['id'] }} for finding {{ $correction['findingId'] }}</option>
                                                    @endif
                                                @endforeach
                                            </select>
                                        </label>
                                        <label>Independent correction review decision
                                            <select wire:model="physicalCorrectionDecision" class="w-full rounded border p-2">
                                                <option value="rejected">Reject correction; finding remains on record</option>
                                                <option value="superseded">Accept documented correction; supersede documentary finding only</option>
                                            </select>
                                        </label>
                                        <label>Third-owner correction reason <textarea wire:model="physicalCorrectionReviewReason" class="w-full rounded border p-2"></textarea></label>
                                        <button type="button" wire:click="reviewInternalFindingCorrection" class="rounded border px-3 py-2">Record immutable independent correction review</button>
                                        @error('physicalCorrectionReviewReason') <p role="alert">{{ $message }}</p> @enderror
                                    </div>
                                @endif
                            </div>
                        @endif
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
                                                originally unresolved {{ $line['unresolvedQuantity'] }},
                                                 later independently received good {{ $line['lateGood'] }}, damaged {{ $line['lateDamaged'] }},
                                                 still unresolved {{ $line['remainingUnresolved'] }}; not loan closure)
                                            @else
                                                (source count pending)
                                            @endif
                                        </li>
                                        @if(config('inventory_controls.internal_late_return_enabled'))
                                            @foreach(($line['lateProofIds']??[]) as $lateProofId)
                                                <li><button type="button" wire:click="downloadLatePhysicalReturnProof('{{ $lateProofId }}')"
                                                    class="rounded-lg border px-3 py-2 text-sm">
                                                    Download audited separately witnessed later receipt {{ $lateProofId }}
                                                </button></li>
                                            @endforeach
                                        @endif
                                        @if(config('inventory_controls.internal_late_return_enabled') &&
                                            $batch['accepted'] && ($line['remainingUnresolved']??0)>0)
                                            <li><button type="button"
                                                wire:click="startLatePhysicalReturn('{{ $batch['batchId'] }}','{{ $line['sourceItemId'] }}')"
                                                class="rounded-lg border px-3 py-2 text-sm">
                                                Record separately witnessed later physical arrival
                                            </button></li>
                                        @endif
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
                @if(config('inventory_controls.internal_late_return_enabled') && $lateReturnBatchId!=='')
                    <section class="space-y-3 rounded-xl border p-4" aria-label="Later original loan physical count">
                        <h3 class="font-semibold">Later independently witnessed physical arrival</h3>
                        <p>Original batch {{ $lateReturnBatchId }}, original source item {{ $lateReturnItemId }}.
                        Record only actual newly observed physical units. No testimony-only credit, stock retirement, payment or automatic closure.</p>
                        <label class="block text-sm">Newly observed good
                            <input type="number" min="0" wire:model="lateReturnGood"
                                class="w-full rounded-lg border p-2 text-gray-900">
                        </label>
                        <label class="block text-sm">Newly observed damaged
                            <input type="number" min="0" wire:model="lateReturnDamaged"
                                class="w-full rounded-lg border p-2 text-gray-900">
                        </label>
                        <label class="block text-sm">New signed physical arrival PDF/photo
                            <input type="file" wire:model="lateReturnFile" accept=".pdf,.jpg,.jpeg,.png"
                                class="w-full rounded-lg border p-2">
                        </label>
                        @error('lateReturnGood') <p role="alert">{{ $message }}</p> @enderror
                        @error('lateReturnFile') <p role="alert">{{ $message }}</p> @enderror
                        <button type="button" wire:click="submitLatePhysicalReturn"
                            class="rounded-lg bg-emerald-800 px-4 py-2 text-sm text-white">
                            Post separately witnessed physical return
                        </button>
                    </section>
                @endif
                @if($lastLateReturnId)
                    <p role="status">Additional immutable witnessed physical receipt {{ $lastLateReturnId }} recorded, without financial posting or closure.</p>
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
