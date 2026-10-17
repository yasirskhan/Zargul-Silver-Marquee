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
            </section>
        @endif
    </div>
</x-filament-panels::page>
