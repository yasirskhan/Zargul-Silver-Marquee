<x-filament-panels::page>
    <div class="space-y-6">
        <x-venuedesk.header eyebrow="VenueDesk · Settings" title="Payment settings" />
        <section class="vd-panel">
            <div class="vd-panel-head"><h2>Payment settings</h2><p>Owner-controlled manual payment instructions. Provider API activation and live settlement remain disabled.</p></div>
            <label class="block space-y-1"><span class="text-sm font-medium">Operating company</span>
                <select wire:model.live="companyId" class="w-full rounded-lg border p-2 text-gray-900">
                    @foreach($this->companyOptions() as $id=>$name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>
            </label>
            <p class="mt-2 text-xs text-gray-500">Revision {{ $revision }} · changes are audited and saved against this revision.</p>
        </section>

        <section class="vd-panel">
            <div class="vd-panel-head"><h2>Accepted methods</h2><p>Only enabled methods appear as venue payment instructions.</p></div>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach(['bank_transfer'=>'Bank transfer','cash'=>'Cash','qr'=>'QR / Raast instructions','easypaisa'=>'Easypaisa','jazzcash'=>'JazzCash','raast'=>'Raast'] as $value=>$label)
                    <label class="flex items-center gap-2 rounded-lg border p-3">
                        <input type="checkbox" wire:model="methods" value="{{ $value }}">
                        <span>{{ $label }}</span>
                    </label>
                @endforeach
            </div>
        </section>

        <section class="vd-panel">
            <div class="vd-panel-head"><h2>Venue bank accounts</h2><p>Masked account references only. Never enter full account numbers, IBANs or credentials.</p></div>
            @foreach($banks as $index=>$bank)
                <div class="mb-4 grid gap-3 rounded-lg border p-3 md:grid-cols-3" wire:key="bank-{{ $index }}">
                    <label class="block space-y-1"><span>Bank name</span><input wire:model="banks.{{ $index }}.bankName" maxlength="120" class="w-full rounded-lg border p-2"></label>
                    <label class="block space-y-1"><span>Branch</span><input wire:model="banks.{{ $index }}.branch" maxlength="160" class="w-full rounded-lg border p-2"></label>
                    <label class="block space-y-1"><span>Masked account</span><input wire:model="banks.{{ $index }}.accountMasked" maxlength="8" placeholder="****1234" class="w-full rounded-lg border p-2"></label>
                    <div><button type="button" class="rounded-lg border px-3 py-2" wire:click="removeBank({{ $index }})">Remove account</button></div>
                </div>
            @endforeach
            <button type="button" class="rounded-lg border px-3 py-2" wire:click="addBank">Add bank account</button>
        </section>

        <section class="vd-panel" aria-label="Payment instructions preview">
            <div class="vd-panel-head"><h2>Staff-facing preview</h2><p>This is the guidance staff will see for manual deposits. No provider is charged.</p></div>
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <h3 class="font-semibold">Accepted methods</h3>
                    <div class="mt-2 flex flex-wrap gap-2">
                        @forelse($methods as $method)
                            <span class="vd-status confirmed">{{ str_replace('_',' ', ucfirst($method)) }}</span>
                        @empty
                            <p class="text-sm">No manual method enabled.</p>
                        @endforelse
                    </div>
                    <p class="mt-3 text-sm">{{ $instructions !== '' ? $instructions : 'No bank or wallet instructions entered.' }}</p>
                </div>
                <div>
                    <h3 class="font-semibold">Venue accounts</h3>
                    @forelse($banks as $bank)
                        @if(trim((string)($bank['bankName'] ?? '')) !== '')
                            <p class="text-sm">{{ $bank['bankName'] }} · {{ $bank['branch'] }} · {{ $bank['accountMasked'] }}</p>
                        @endif
                    @empty
                        <p class="text-sm">No bank accounts configured.</p>
                    @endforelse
                    <p class="mt-3 text-sm">{{ $qrInstructions !== '' ? $qrInstructions : 'No QR / Raast instructions entered.' }}</p>
                    <p class="mt-2 text-xs">Default deposit: {{ $depositPercent }}%</p>
                </div>
            </div>
        </section>

        <section class="vd-panel">
            <div class="vd-panel-head"><h2>Payment instructions</h2><p>Displayed to staff when recording a manual payment.</p></div>
            <div class="grid gap-4 md:grid-cols-2">
                <label class="block space-y-1"><span>Bank / wallet instructions</span><textarea wire:model="instructions" rows="4" maxlength="1000" class="w-full rounded-lg border p-2"></textarea></label>
                <label class="block space-y-1"><span>QR / Raast instructions</span><textarea wire:model="qrInstructions" rows="4" maxlength="1000" class="w-full rounded-lg border p-2"></textarea></label>
                <label class="block space-y-1"><span>Default deposit percentage</span><input type="number" min="0" max="100" wire:model="depositPercent" class="w-full rounded-lg border p-2"></label>
            </div>
            <div class="mt-4"><button type="button" wire:click="save" class="rounded-lg bg-emerald-900 px-4 py-2 font-semibold text-white">Save payment settings</button></div>
        </section>
    </div>
</x-filament-panels::page>
