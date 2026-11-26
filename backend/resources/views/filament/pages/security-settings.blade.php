<x-filament-panels::page>
<div class="space-y-6">
<x-venuedesk.header eyebrow="VenueDesk · Security" title="Security & MFA" />
<section class="vd-panel"><div class="vd-panel-head"><h2>Two-step authentication</h2><p>Global policy with per-staff overrides. Trusted devices skip the code only for their configured lifetime.</p></div>
<label class="block space-y-1"><span>Operating company</span><select wire:model.live="companyId" class="w-full rounded-lg border p-2 text-gray-900">@foreach($this->companyOptions() as $id=>$name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></label>
<div class="mt-4 grid gap-4 md:grid-cols-2">
<label class="flex items-center gap-2 rounded-lg border p-3"><input type="checkbox" wire:model="mfaRequired"><span>Require two-step authentication by default</span></label>
<label class="block space-y-1"><span>Trusted device lifetime (days)</span><input type="number" min="1" max="365" wire:model="trustedDeviceDays" class="w-full rounded-lg border p-2"></label>
</div>
<p class="mt-2 text-xs text-gray-500">Revision {{ $revision }}</p>
<div class="mt-4 flex gap-3"><button type="button" wire:click="save" class="rounded-lg bg-emerald-900 px-4 py-2 font-semibold text-white">Save security settings</button><button type="button" wire:click="trustThisDevice" class="rounded-lg border px-4 py-2">Trust this device</button></div></section>

<section class="vd-panel"><div class="vd-panel-head"><h2>Staff policy</h2><p>Inherit the company default, require, or make optional per staff member.</p></div>
<div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr><th class="p-2">Staff</th><th class="p-2">Enrolled</th><th class="p-2">Policy</th></tr></thead><tbody>
@foreach($this->staffRows() as $row)<tr class="border-t"><td class="p-2">{{ $row['name'] }}<br><small>{{ $row['email'] }}</small></td><td class="p-2">{{ $row['enrolled']?'Yes':'Not enrolled' }}</td><td class="p-2"><select wire:change="setUserPolicy({{ $row['id'] }}, $event.target.value)" class="rounded border p-1"><option value="inherit" @selected($row['override']===null)>Inherit</option><option value="required" @selected($row['override']===true)>Required</option><option value="optional" @selected($row['override']===false)>Optional</option></select></td></tr>@endforeach
</tbody></table></div></section>

<section class="vd-panel"><div class="vd-panel-head"><h2>Trusted devices</h2><p>Revoke a device to require the next login to complete two-step authentication.</p></div>
<div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr><th class="p-2">Staff</th><th class="p-2">Expires</th><th class="p-2">Last used</th><th class="p-2">Status</th><th class="p-2"></th></tr></thead><tbody>
@forelse($this->deviceRows() as $device)<tr class="border-t"><td class="p-2">{{ $device['name'] }}<br><small>{{ $device['email'] }}</small></td><td class="p-2">{{ $device['expiresAt'] }}</td><td class="p-2">{{ $device['lastUsedAt'] ?? '—' }}</td><td class="p-2">{{ $device['revokedAt']?'Revoked':'Trusted' }}</td><td class="p-2">@if(!$device['revokedAt'])<button type="button" wire:click="revokeDevice('{{ $device['id'] }}')" class="rounded border px-3 py-1">Revoke</button>@endif</td></tr>@empty<tr><td colspan="5" class="p-4 text-center">No trusted devices recorded.</td></tr>@endforelse
</tbody></table></div></section>
</div>
</x-filament-panels::page>
