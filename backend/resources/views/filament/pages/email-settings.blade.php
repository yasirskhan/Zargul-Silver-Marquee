<x-filament-panels::page>
<div class="space-y-6">
<section class="vd-panel"><div class="vd-panel-head"><h2>Email / SMTP settings</h2><p>Owner-managed invoice delivery. Password is stored encrypted and never shown again.</p></div>
<label class="block space-y-1"><span>Operating company</span><select wire:model.live="companyId" class="w-full rounded-lg border p-2 text-gray-900">@foreach($this->companyOptions() as $id=>$name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></label>
<p class="mt-2 text-xs text-gray-500">Revision {{ $revision }} · password {{ $hasPassword ? 'stored' : 'not set' }}</p></section>
<section class="vd-panel"><div class="vd-panel-head"><h2>SMTP server</h2><p>Used only for venue invoice and customer email.</p></div>
<div class="grid gap-4 md:grid-cols-2">
<label class="block space-y-1"><span>Host</span><input wire:model="host" maxlength="253" class="w-full rounded-lg border p-2" placeholder="smtp.example.com"></label>
<label class="block space-y-1"><span>Port</span><input type="number" min="1" max="65535" wire:model="port" class="w-full rounded-lg border p-2"></label>
<label class="block space-y-1"><span>Username</span><input wire:model="username" maxlength="254" class="w-full rounded-lg border p-2"></label>
<label class="block space-y-1"><span>Password</span><input type="password" wire:model="password" maxlength="500" class="w-full rounded-lg border p-2" placeholder="{{ $hasPassword ? 'Leave blank to keep current' : '' }}"></label>
<label class="block space-y-1"><span>Encryption</span><select wire:model="encryption" class="w-full rounded-lg border p-2"><option value="tls">TLS</option><option value="ssl">SSL</option><option value="">None</option></select></label>
<label class="block space-y-1"><span>From address</span><input type="email" wire:model="fromAddress" maxlength="254" class="w-full rounded-lg border p-2"></label>
<label class="block space-y-1"><span>From name</span><input wire:model="fromName" maxlength="120" class="w-full rounded-lg border p-2"></label>
</div>
<div class="mt-4"><button type="button" wire:click="save" class="rounded-lg bg-emerald-900 px-4 py-2 font-semibold text-white">Save email settings</button></div></section>
<section class="vd-panel"><div class="vd-panel-head"><h2>Test connection</h2><p>Sends a real test message through the configured SMTP server.</p></div>
<div class="flex flex-wrap items-end gap-3"><label class="block space-y-1"><span>Test recipient</span><input type="email" wire:model="testRecipient" class="w-full rounded-lg border p-2" placeholder="owner@example.test"></label><button type="button" wire:click="sendTest" class="rounded-lg border px-4 py-2">Send test email</button></div></section>
</div>
</x-filament-panels::page>
