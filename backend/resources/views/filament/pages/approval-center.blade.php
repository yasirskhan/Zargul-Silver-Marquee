<x-filament-panels::page>
    <div class="space-y-6">
        <p class="text-sm text-gray-600 dark:text-gray-300">
            Internal, company-scoped exception requests. A decision does not change a booking, invoice,
            price, payment or refund. No customer contact or Telegram message is sent from this page.
        </p>
        <label class="block space-y-1">
            <span class="text-sm font-medium">Company</span>
            <select wire:model.live="companyId" class="w-full rounded-lg border p-3 text-gray-900">
                @foreach ($this->companyOptions() as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </label>

        <section class="space-y-2 rounded-xl border p-4" aria-label="Find a private approval by reference">
            <label class="block space-y-1">
                <span class="text-sm font-medium">Approval status</span>
                <select wire:model.live="statusFilter" class="w-full rounded-lg border p-3 text-gray-900">
                    <option value="all">All requests (pending first)</option>
                    <option value="pending">Pending only</option>
                    <option value="approved">Approved only</option>
                    <option value="rejected">Rejected only</option>
                </select>
            </label>
            @error('statusFilter') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
            <label class="block space-y-1">
                <span class="text-sm font-medium">Approval request reference (optional exact UUID)</span>
                <input type="text" wire:model="requestReference" maxlength="36" autocomplete="off"
                    class="w-full rounded-lg border p-3 font-mono text-sm text-gray-900"
                    placeholder="Paste the full internal reference">
            </label>
            @error('requestReference') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
            <div class="flex flex-wrap gap-2">
                <button type="button" wire:click="refreshInbox" class="rounded-lg border px-3 py-2">Find request</button>
                <button type="button" wire:click="clearRequestReference" class="rounded-lg border px-3 py-2">Show recent requests</button>
            </div>
            @if ($requestReference !== '')
                <p class="break-all text-xs">Filtered exact request: {{ $requestReference }}</p>
            @endif
        </section>

        <section class="space-y-3 rounded-xl border p-4" aria-label="Create exception request">
            <h2 class="font-semibold">Request approval</h2>
            <div class="grid gap-3 md:grid-cols-2">
                <label class="block space-y-1"><span class="text-sm">Exception category</span>
                    <select wire:model="newCategory" class="w-full rounded-lg border p-3 text-gray-900">
                        @foreach ($this->requestCategoryOptions() as $category)
                            <option value="{{ $category }}">{{ str_replace('_', ' ', ucfirst($category)) }}</option>
                        @endforeach
                    </select>
                    @error('newCategory') <span role="alert" class="text-sm text-red-600">{{ $message }}</span> @enderror
                </label>
                <label class="block space-y-1"><span class="text-sm">Linked internal proposal (required except for expense)</span>
                    <select wire:model="newBookingId" class="w-full rounded-lg border p-3 text-gray-900">
                        <option value="">{{ in_array('expense', $this->requestCategoryOptions(), true) ? 'No proposal (expense only)' : 'Select a proposal' }}</option>
                        @foreach ($this->proposalOptions() as $id => $label)
                            <option value="{{ $id }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('newBookingId') <span role="alert" class="text-sm text-red-600">{{ $message }}</span> @enderror
                </label>
                <label class="block space-y-1"><span class="text-sm">Proposed amount in minor currency units</span>
                    <input type="number" min="1" step="1" wire:model="newAmount" class="w-full rounded-lg border p-3 text-gray-900">
                </label>
                <label class="block space-y-1"><span class="text-sm">ISO currency code</span>
                    <input type="text" minlength="3" maxlength="3" wire:model="newCurrency" placeholder="e.g. PKR" class="w-full rounded-lg border p-3 text-gray-900">
                </label>
            </div>
            <label class="block space-y-1"><span class="text-sm">Operational reason (no personal/contact data)</span>
                <textarea wire:model="newReason" rows="2" maxlength="500" class="w-full rounded-lg border p-3 text-gray-900"></textarea>
            </label>
            @error('newReason') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
            <button type="button" wire:click="submitRequest" wire:loading.attr="disabled" class="rounded-lg border px-4 py-2 font-semibold">Submit non-applying approval request</button>
        </section>

        @if ($loadedCompanyId === $companyId)
            <section class="space-y-3" aria-label="Private approval inbox">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="font-semibold">Approval inbox</h2>
                    <button type="button" wire:click="refreshInbox" class="rounded-lg border px-3 py-2">Refresh</button>
                </div>
                @forelse ($entries as $entry)
                    <article wire:key="approval-{{ $entry['id'] }}" class="space-y-2 rounded-xl border p-4 text-sm">
                        <div class="flex flex-wrap justify-between gap-3">
                            <p class="font-semibold">{{ str_replace('_', ' ', ucfirst($entry['category'])) }} | {{ $entry['status'] }}</p>
                            <p>{{ $entry['currency'] }} minor units: {{ $entry['amountMinor'] }}</p>
                        </div>
                        <p>Requested by {{ ($entry['requesterName'] ?? '') !== '' ? $entry['requesterName'] : 'staff member' }}
                            (staff ID {{ $entry['requestedBy'] }}) | requested at {{ $entry['requestedAt'] }}
                            | revision {{ $entry['revision'] }}</p>
                        <p class="break-words">{{ $entry['reason'] }}</p>
                        <p class="break-all text-xs">ID: {{ $entry['id'] }} | proposal: {{ $entry['bookingId'] ?? 'none' }}</p>
                        @if (is_array($entry['proposal'] ?? null))
                            <p class="text-sm">Proposed event: {{ $entry['proposal']['date'] }}
                                · {{ ucfirst($entry['proposal']['program']) }}
                                · {{ $entry['proposal']['hallName'] }} (not confirmed)</p>
                        @endif
                        @if ($entry['bookingId'] !== null && in_array($this->role(), ['owner', 'admin'], true))
                            <a class="inline-block underline" href="{{ '/admin/booking-proposals?company='.rawurlencode($companyId).'&proposal='.rawurlencode($entry['bookingId']) }}">
                                View linked internal proposal
                            </a>
                        @endif
                        @if ($entry['status'] === 'pending' && $this->canReviewRequest($entry))
                            <button type="button" wire:click="selectRequest('{{ $entry['id'] }}')" class="rounded-lg border px-3 py-2">Review decision</button>
                        @endif
                    </article>
                @empty
                    <p class="rounded-lg border p-4 text-sm">No approval requests visible to your role.</p>
                @endforelse
            </section>
        @endif

        @if ($selectedId && $loadedCompanyId === $companyId)
            <section class="space-y-3 rounded-xl border p-4" aria-label="Review selected approval">
                <h2 class="font-semibold">Decide on request {{ $selectedId }}</h2>
                <p class="text-sm">Revision {{ $selectedRevision }}. Requesters cannot decide on their own requests.
                    Delegated administrators must have an active category- and amount-limited grant.</p>
                <label class="block space-y-1"><span class="text-sm">Decision reason</span>
                    <textarea wire:model="decisionReason" maxlength="500" rows="2" class="w-full rounded-lg border p-3 text-gray-900"></textarea>
                </label>
                @error('decisionReason') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
                <div class="flex flex-wrap gap-3">
                    <button type="button" wire:click="approve" wire:loading.attr="disabled" class="rounded-lg border px-4 py-2 font-semibold">Approve proposal only</button>
                    <button type="button" wire:click="reject" wire:loading.attr="disabled" class="rounded-lg border px-4 py-2">Reject proposal</button>
                </div>
            </section>
        @endif

        @if ($this->role() === 'owner' && $loadedCompanyId === $companyId)
            <section class="space-y-3 rounded-xl border p-4" aria-label="Emergency approval delegation">
            <section class="space-y-2 rounded-xl border p-3" aria-label="Private Telegram owner alerts">
                <h3 class="font-semibold">Optional private Telegram alerts</h3>
                <p class="text-sm">Link your own private chat using the bot. No customer contact,
                    price or approval decision is sent through Telegram. Every decision still requires
                    signing in to VenueDesk with MFA. Delivery is disabled until an operator securely
                    configures the bot, webhook and HTTPS domain.</p>
                @if (! $this->telegramConfigured())
                    <p class="text-sm">Telegram is not configured for this venue installation.</p>
                @else
                    <p class="text-sm">Status: {{ $this->telegramConnected() ? 'Private owner chat linked' : 'Not linked' }}</p>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" wire:click="beginTelegramPairing" class="rounded-lg border px-3 py-2">
                            Generate one-time private-chat pairing link
                        </button>
                        @if ($this->telegramConnected())
                            <button type="button" wire:click="revokeTelegramPairing" class="rounded-lg border px-3 py-2">Disconnect Telegram</button>
                        @endif
                    </div>
                    @if ($telegramPairingUrl)
                        <p class="text-sm">This link expires in 10 minutes and can be used only once.
                            Open it in your own Telegram app. Anyone with access to your chat may see the
                            link, so do not share it.</p>
                        <a href="{{ $telegramPairingUrl }}" rel="noopener noreferrer" target="_blank"
                            class="break-all text-sm underline">{{ $telegramPairingUrl }}</a>
                    @endif
                    @error('telegramPairingUrl') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
                @endif
            </section>
                <h2 class="font-semibold">Emergency delegation (owner only)</h2>
                <p class="text-sm">Admin-only recipients. Seven-day maximum; category and amount ceilings, no self-approval. Times are UTC.</p>
                <div class="grid gap-3 md:grid-cols-2">
                    <label class="block space-y-1"><span class="text-sm">Administrator</span>
                        <select wire:model="grantAdminId" class="w-full rounded-lg border p-3 text-gray-900">
                            <option value="">Select verified administrator</option>
                            @foreach ($this->adminOptions() as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block space-y-1"><span class="text-sm">Ceiling (minor units)</span>
                        <input type="number" min="1" wire:model="grantCeiling" class="w-full rounded-lg border p-3 text-gray-900">
                    </label>
                    <label class="block space-y-1"><span class="text-sm">Start (UTC)</span>
                        <input type="datetime-local" wire:model="grantStarts" class="w-full rounded-lg border p-3 text-gray-900">
                    </label>
                    <label class="block space-y-1"><span class="text-sm">End (UTC)</span>
                        <input type="datetime-local" wire:model="grantEnds" class="w-full rounded-lg border p-3 text-gray-900">
                    </label>
                </div>
                <fieldset class="space-y-2">
                    <legend class="text-sm font-medium">Allowed exception categories</legend>
                    <div class="grid gap-2 sm:grid-cols-2">
                        @foreach (\App\Services\ApprovalWorkflowService::CATEGORIES as $category)
                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox" wire:model="grantCategories" value="{{ $category }}">
                                {{ str_replace('_', ' ', ucfirst($category)) }}
                            </label>
                        @endforeach
                    </div>
                </fieldset>
                <label class="block space-y-1"><span class="text-sm">Grant justification</span>
                    <textarea wire:model="grantReason" maxlength="500" rows="2" class="w-full rounded-lg border p-3 text-gray-900"></textarea>
                </label>
                @error('grantReason') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
                <button type="button" wire:click="grant" wire:loading.attr="disabled" class="rounded-lg border px-4 py-2 font-semibold">Grant limited authority</button>
                <h3 class="font-semibold">Active / scheduled grants</h3>
                <label class="block space-y-1"><span class="text-sm">Reason to revoke immediately</span>
                    <input type="text" maxlength="500" wire:model="revokeReason" class="w-full rounded-lg border p-3 text-gray-900">
                </label>
                @error('revokeReason') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
                @forelse ($this->delegations() as $grant)
                    <div class="flex flex-wrap justify-between gap-2 border-t py-2 text-sm">
                        <p class="break-all">Admin #{{ $grant['delegated_to'] }}, ceiling {{ $grant['ceiling_minor'] }},
                            {{ $grant['starts_at'] }} to {{ $grant['ends_at'] }}.
                            Allowed: {{ implode(', ', $grant['categories']) }}.
                            Granted by staff #{{ $grant['granted_by'] }}</p>
                        <p class="break-words">Grant reason: {{ $grant['reason'] }}</p>
                        <button type="button" wire:click="revoke('{{ $grant['id'] }}')" class="rounded-lg border px-3 py-2">Revoke</button>
                    </div>
                @empty
                    <p class="text-sm">No unrevoked grants.</p>
                @endforelse
            </section>
        @endif
    </div>
</x-filament-panels::page>
