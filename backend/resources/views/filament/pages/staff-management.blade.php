<x-filament-panels::page>
    <div class="space-y-6">
        <x-venuedesk.header eyebrow="VenueDesk · Staff" title="Staff & Access" />
        <div class="vd-metrics" aria-label="Staff and access summary">
            <div class="vd-metric"><b>{{ count($invitations) }}</b><span>Invitations in this view</span></div>
            <div class="vd-metric"><b>{{ count($halls) }}</b><span>Authorized halls</span></div>
            <div class="vd-metric"><b>{{ count($hallIds) }}</b><span>Selected halls</span></div>
        </div>
        <p class="text-sm">Staff access requires acceptance, email verification and MFA. Invitations never create a default password.</p>

        @if ($message)
            <p role="status" class="rounded border p-3 text-sm">{{ $message }}</p>
        @endif

        <section class="vd-panel space-y-3" aria-label="Staff directory">
            <div class="vd-panel-head"><h2>Staff directory</h2><p>Owner-controlled roles and hall assignments.</p></div>
            <label class="block max-w-md space-y-1"><span class="text-sm">Search staff</span>
                <input wire:model.live.debounce.300ms="staffSearch" maxlength="120" placeholder="Name or email" class="w-full rounded-lg border p-2">
            </label>
            <div class="overflow-x-auto">
                <table class="vd-table w-full text-left text-sm">
                    <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Halls</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                    @forelse ($staffRows as $member)
                        <tr wire:key="staff-{{ $member['id'] }}">
                            <td><strong>{{ $member['name'] }}</strong>@if($member['isOwner']) <small>owner</small>@endif</td>
                            <td>{{ $member['email'] }}</td>
                            <td>{{ ucfirst($member['role']) }}</td>
                            <td>{{ count($member['hallIds']) }}</td>
                            <td><span class="vd-status {{ $member['active'] ? 'confirmed' : 'cancelled' }}">{{ $member['active'] ? 'Active' : 'Revoked' }}</span></td>
                            <td><button type="button" wire:click="selectStaff('{{ $member['id'] }}')" class="underline">Manage</button></td>
                        </tr>
                    @empty
                        <tr><td colspan="6">No staff match this search.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if ($selectedStaffId !== null)
                @php($selectedStaff = collect($staffRows)->firstWhere('id', $selectedStaffId))
                @if ($selectedStaff !== null)
                    <div class="rounded-lg border p-4 space-y-3" aria-label="Staff role and hall assignment">
                        <h3 class="font-semibold">{{ $selectedStaff['name'] }} · {{ $selectedStaff['email'] }}</h3>
                        <p class="text-xs">MFA: {{ $selectedStaff['mfaRequired'] ? 'required' : 'optional' }} · Email {{ $selectedStaff['verified'] ? 'verified' : 'not verified' }}</p>
                        <label class="block space-y-1"><span class="text-sm">Role</span>
                            <select wire:model="staffRole" class="w-full rounded-lg border p-2">
                                <option value="admin">Admin</option><option value="manager">Manager</option>
                                <option value="operations">Operations</option><option value="reception">Reception</option>
                                <option value="cashier">Cashier</option>
                            </select>
                        </label>
                        <fieldset class="space-y-2"><legend class="text-sm font-medium">Assigned halls</legend>
                            @foreach ($halls as $hall)
                                <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="staffHallIds" value="{{ $hall['id'] }}"> {{ $hall['name'] }}</label>
                            @endforeach
                        </fieldset>
                        <label class="block space-y-1"><span class="text-sm">Reason for change (audited)</span>
                            <textarea wire:model="staffReason" rows="2" maxlength="300" class="w-full rounded-lg border p-2"></textarea>
                        </label>
                        @error('staffReason') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
                        <div class="flex flex-wrap gap-2">
                            <button type="button" wire:click="saveStaff" class="rounded-lg bg-emerald-800 px-4 py-2 text-white">Save staff access</button>
                            <button type="button" wire:click="revokeStaff('{{ $selectedStaff['id'] }}')" class="rounded-lg border border-rose-300 px-4 py-2 text-rose-700">Revoke membership</button>
                            <button type="button" wire:click="closeStaff" class="rounded-lg border px-4 py-2">Close</button>
                        </div>
                    </div>
                @endif
            @endif
        </section>
        <section class="vd-panel space-y-3">
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

        <section class="vd-panel space-y-2" aria-label="Staff invitations">
            <div class="vd-panel-head"><h2>Invitations</h2><p>Pending invitations expire automatically; revoked invitations cannot be reused.</p></div>
            <div class="overflow-x-auto">
                <table class="vd-table w-full text-left text-sm">
                    <thead><tr><th>Email</th><th>Role</th><th>Status</th><th>Expires</th><th>Action</th></tr></thead>
                    <tbody>
                    @forelse ($invitations as $invitation)
                        <tr wire:key="invite-{{ $invitation['id'] }}">
                            <td>{{ $invitation['email'] }}</td>
                            <td>{{ ucfirst($invitation['role']) }}</td>
                            <td><span class="vd-status {{ $invitation['status'] === 'accepted' ? 'confirmed' : ($invitation['status'] === 'revoked' ? 'cancelled' : 'hold') }}">{{ ucfirst($invitation['status']) }}</span></td>
                            <td>{{ $invitation['expires_at'] }}</td>
                            <td>@if ($invitation['status'] !== 'revoked' && $invitation['status'] !== 'accepted')
                                <button type="button" wire:click="revoke('{{ $invitation['id'] }}')" class="underline">Revoke</button>
                            @endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="5">No invitations.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @error('reason') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
        </section>
    </div>
</x-filament-panels::page>
