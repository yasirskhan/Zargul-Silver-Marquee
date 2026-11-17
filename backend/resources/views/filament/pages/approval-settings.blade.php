<x-filament-panels::page>
<div class="space-y-6">
<section class="vd-panel"><div class="vd-panel-head"><h2>Discount limits</h2><p>Maximum discount each role may apply without independent approval.</p></div>
<label class="block space-y-1"><span>Company</span><select wire:model.live="companyId" class="w-full rounded-lg border p-2 text-gray-900">@foreach($this->companyOptions() as $id=>$name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></label>
<div class="mt-4 grid gap-3 md:grid-cols-3">
@foreach(['owner'=>'Owner','admin'=>'Admin','manager'=>'Manager'] as $role=>$label)
<label class="block space-y-1"><span>{{ $label }} limit (%)</span><input type="number" min="0" max="100" step="0.01" wire:model="limits.{{ $role }}" class="w-full rounded-lg border p-2"></label>
@endforeach
</div>
<div class="mt-4"><button type="button" wire:click="saveLimits" class="rounded-lg bg-emerald-900 px-4 py-2 font-semibold text-white">Save discount limits</button></div></section>

<section class="vd-panel"><div class="vd-panel-head"><h2>Delegated approvers</h2><p>Owner-appointed, time-bound authority with a maximum amount.</p></div>
<div class="grid gap-3 md:grid-cols-2">
<label class="block space-y-1"><span>Administrator</span><select wire:model="grantAdminId" class="w-full rounded-lg border p-2"><option value="">Choose administrator</option>@foreach($this->admins() as $admin)<option value="{{ $admin['id'] }}">{{ $admin['name'] }}</option>@endforeach</select></label>
<label class="block space-y-1"><span>Ceiling (PKR)</span><input type="number" min="1" wire:model="grantCeiling" class="w-full rounded-lg border p-2"></label>
<label class="block space-y-1"><span>Starts</span><input type="datetime-local" wire:model="grantStarts" class="w-full rounded-lg border p-2"></label>
<label class="block space-y-1"><span>Ends</span><input type="datetime-local" wire:model="grantEnds" class="w-full rounded-lg border p-2"></label>
<label class="block space-y-1 md:col-span-2"><span>Reason</span><input wire:model="grantReason" maxlength="500" class="w-full rounded-lg border p-2"></label>
</div>
<div class="mt-4"><button type="button" wire:click="grant" class="rounded-lg bg-emerald-900 px-4 py-2 font-semibold text-white">Grant delegation</button></div>
<div class="mt-5 overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr><th class="p-2">Administrator</th><th class="p-2">Categories</th><th class="p-2">Ceiling</th><th class="p-2">Ends</th><th class="p-2"></th></tr></thead><tbody>
@forelse($this->delegations() as $grant)<tr class="border-t"><td class="p-2">#{{ $grant['delegated_to'] }}</td><td class="p-2">{{ implode(', ', $grant['categories']) }}</td><td class="p-2">PKR {{ number_format($grant['ceiling_minor']/100,2) }}</td><td class="p-2">{{ $grant['ends_at'] }}</td><td class="p-2"><button type="button" wire:click="revokeDelegation('{{ $grant['id'] }}')" class="rounded border px-3 py-1">Revoke</button></td></tr>@empty<tr><td colspan="5" class="p-4 text-center">No active delegations.</td></tr>@endforelse
</tbody></table></div></section>

<section class="vd-panel"><div class="vd-panel-head"><h2>Telegram approval alerts</h2><p>Private owner notifications use a configured bot; no message is sent until pairing completes.</p></div>
<p class="text-sm">Bot configuration: {{ $this->telegramConfigured()?'available':'not enabled on this server' }} · Pairing: {{ $this->telegramConnected()?'connected':'not connected' }}</p>
@if($telegramUrl)<p class="mt-2 break-all rounded border p-2 text-sm">Open Telegram pairing: <a class="underline" href="{{ $telegramUrl }}" target="_blank" rel="noopener">{{ $telegramUrl }}</a></p>@endif
<div class="mt-4 flex gap-3"><button type="button" wire:click="beginTelegram" class="rounded-lg border px-4 py-2">Connect Telegram</button><button type="button" wire:click="revokeTelegram" class="rounded-lg border px-4 py-2">Revoke Telegram</button></div></section>
</div>
</x-filament-panels::page>
