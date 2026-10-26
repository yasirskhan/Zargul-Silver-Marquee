<x-filament-panels::page>
    <div class="space-y-6">
        <p class="text-sm">Staff access requires acceptance, email verification and MFA. Invitations never create a default password.</p>

        @if ($message)
            <p role="status" class="rounded border p-3 text-sm">{{ $message }}</p>
        @endif

        <section class="space-y-3 rounded-xl border p-4">
            <h2 class="font-semibold">Invite staff</h2>
            <div class="grid gap-3 md:grid-cols-3">
                <label class="space-y-1 text-sm"><span>Email</span>
                    <input type="email" wire:model="email" maxlength="254" class="w-full rounded border p-2">
                    @error('email') <span role="alert" class="text-sm text-red-600">{{ $message }}</span> @enderror
                </label>
                <label class="space-y-1 text-sm"><span>Role</span>
                    <select wire:model="role" class="w-full rounded border p-2">
                        <option value="admin">Admin</option>
                        <option value="manager">Manager</option>
                        <option value="reception">Reception</option>
                        <option value="operations">Operations</option>
                    </select>
                </label>
                <label class="space-y-1 text-sm"><span>Reason</span>
                    <input wire:model="reason" maxlength="300" class="w-full rounded border p-2">
                    @error('reason') <span role="alert" class="text-sm text-red-600">{{ $message }}</span> @enderror
                </label>
            </div>
            <fieldset class="space-y-2">
                <legend class="text-sm font-medium">Assigned halls</legend>
                @forelse ($halls as $hall)
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" wire:model="hallIds" value="{{ $hall['id'] }}"> {{ $hall['name'] }}
                    </label>
                @empty
                    <p class="text-sm">No bookable halls yet.</p>
                @endforelse
            </fieldset>
            <button type="button" wire:click="invite" class="rounded bg-emerald-800 px-4 py-2 font-semibold text-white">Create invitation</button>
        </section>

        <section class="space-y-2">
            <h2 class="font-semibold">Invitations</h2>
            @forelse ($invitations as $invitation)
                <div class="flex flex-wrap items-center justify-between gap-2 rounded border p-3 text-sm">
                    <span>{{ $invitation['email'] }} · {{ $invitation['role'] }} · {{ strtoupper($invitation['status']) }}</span>
                    @if ($invitation['status'] !== 'revoked')
                        <button type="button" wire:click="revoke('{{ $invitation['id'] }}')" class="rounded border px-3 py-1">Revoke</button>
                    @endif
                </div>
            @empty
                <p class="text-sm">No invitations.</p>
            @endforelse
        </section>
    </div>
</x-filament-panels::page>
