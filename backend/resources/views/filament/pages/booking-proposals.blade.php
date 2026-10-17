<x-filament-panels::page>
    <div class="space-y-6">
        <p class="text-sm text-gray-600 dark:text-gray-300">
            Internal booking proposals for owner/admin review and safe draft editing. These are NOT confirmed bookings or holds.
            Availability, prices, consent and booking approval must be checked separately. No customer contact is displayed.
        </p>
        <section class="space-y-3 rounded-xl border p-4" aria-label="Private customer and proposal intake">
            <h2 class="text-lg font-semibold">Create customer and internal booking proposal</h2>
            <p class="text-xs">Owner/admin only. Choose an existing customer by exact private ID, or enter a new customer below. Personal contact information is encrypted and never shown in the proposal list. Submitting for review does not reserve a hall.</p>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <label class="space-y-1"><span>Existing customer ID (optional)</span>
                    <input wire:model="intakeCustomerId" maxlength="36" class="w-full rounded-lg border p-3 text-gray-900">
                </label>
                <label class="space-y-1"><span>New customer's name</span>
                    <input wire:model="intakeName" maxlength="120" autocomplete="off" class="w-full rounded-lg border p-3 text-gray-900">
                </label>
                <label class="space-y-1"><span>Customer email (optional)</span>
                    <input wire:model="intakeEmail" type="email" maxlength="254" autocomplete="off" class="w-full rounded-lg border p-3 text-gray-900">
                </label>
                <label class="space-y-1"><span>Customer phone (optional)</span>
                    <input wire:model="intakePhone" maxlength="32" autocomplete="off" class="w-full rounded-lg border p-3 text-gray-900">
                </label>
                <label class="space-y-1"><span>Hall</span>
                    <select wire:model="intakeHallId" class="w-full rounded-lg border p-3 text-gray-900">
                        <option value="">Select hall</option>
                        @foreach ($this->hallOptions() as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="space-y-1"><span>Event date</span>
                    <input wire:model="intakeDate" type="date" class="w-full rounded-lg border p-3 text-gray-900">
                </label>
                <label class="space-y-1"><span>Program</span>
                    <select wire:model="intakeProgram" class="w-full rounded-lg border p-3 text-gray-900">
                        <option value="morning">Morning</option><option value="noon">Noon</option><option value="evening">Evening</option>
                    </select>
                </label>
                <label class="space-y-1"><span>New proposal guest count</span>
                    <input wire:model="intakeGuests" type="number" min="1" class="w-full rounded-lg border p-3 text-gray-900">
                </label>
            </div>
            <label class="block space-y-1"><span>Internal creation/review reason (no personal details)</span>
                <textarea wire:model="intakeReason" rows="2" maxlength="500" class="w-full rounded-lg border p-3 text-gray-900"></textarea>
            </label>
            <label class="flex items-center gap-2"><input type="checkbox" wire:model="intakeSubmitReview">
                Submit new proposal for review after creating it
            </label>
            @foreach (['intakeCustomerId','intakeName','intakeEmail','intakePhone','intakeHallId','intakeDate','intakeProgram','intakeGuests','intakeReason'] as $field)
                @error($field) <p role="alert" class="text-xs text-red-600">{{ $message }}</p> @enderror
            @endforeach
            <button type="button" wire:click="createIntake" wire:loading.attr="disabled"
                class="rounded-lg border px-4 py-2 font-semibold">Save private customer and proposal</button>
        </section>
        <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
            <label class="block space-y-2">
                <span class="text-sm font-medium">Company</span>
                <select wire:model.live="companyId" class="w-full rounded-lg border p-3 text-gray-900">
                    @foreach ($this->companyOptions() as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block space-y-2">
                <span class="text-sm font-medium">Hall</span>
                <select wire:model="hallId" class="w-full rounded-lg border p-3 text-gray-900">
                    <option value="">All company halls</option>
                    @foreach ($this->hallOptions() as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block space-y-2">
                <span class="text-sm font-medium">Proposal status</span>
                <select wire:model="statusFilter" class="w-full rounded-lg border p-3 text-gray-900">
                    <option value="">All states</option>
                    <option value="draft">Draft</option>
                    <option value="under_review">Under review</option>
                    <option value="withdrawn">Withdrawn</option>
                </select>
                @error('statusFilter') <span role="alert" class="text-sm text-red-600">{{ $message }}</span> @enderror
            </label>
            <label class="block space-y-2">
                <span class="text-sm font-medium">Hall program</span>
                <select wire:model="programFilter" class="w-full rounded-lg border p-3 text-gray-900">
                    <option value="">All programs</option>
                    <option value="morning">Morning</option>
                    <option value="noon">Noon</option>
                    <option value="evening">Evening</option>
                </select>
                @error('programFilter') <span role="alert" class="text-sm text-red-600">{{ $message }}</span> @enderror
            </label>
            <label class="block space-y-2">
                <span class="text-sm font-medium">Proposal reference (exact UUID)</span>
                <input type="text" wire:model="referenceSearch" maxlength="36" autocomplete="off"
                    placeholder="Paste full internal proposal reference"
                    class="w-full rounded-lg border p-3 font-mono text-sm text-gray-900">
                @error('referenceSearch') <span role="alert" class="text-sm text-red-600">{{ $message }}</span> @enderror
            </label>
            <label class="block space-y-2">
                <span class="text-sm font-medium">Event date from</span>
                <input type="date" wire:model="fromDate" class="w-full rounded-lg border p-3 text-gray-900">
            </label>
            <label class="block space-y-2">
                <span class="text-sm font-medium">Event date to</span>
                <input type="date" wire:model="toDate" class="w-full rounded-lg border p-3 text-gray-900">
            </label>
        </div>
        <div>
            <button type="button" wire:click="refreshResults" class="rounded-lg border px-4 py-2 text-sm font-semibold">
                Search / refresh
            </button>
            @error('fromDate') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
        </div>
        @if ($loadedCompanyId === $companyId)
            <section class="space-y-4" aria-label="Private company proposal results">
                <p class="text-sm">Showing {{ count($entries) }} internal proposal(s), starting at {{ $offset + 1 }}.</p>
                <div class="overflow-x-auto rounded-xl border">
                    <table class="w-full min-w-[850px] border-collapse text-left text-sm">
                        <caption class="sr-only">Unconfirmed owner-only proposal metadata, no customer contact details</caption>
                        <thead class="bg-gray-100 text-gray-900">
                            <tr>
                                <th scope="col" class="p-3">Date</th>
                                <th scope="col" class="p-3">Reference</th>
                                <th scope="col" class="p-3">Hall</th>
                                <th scope="col" class="p-3">Program</th>
                                <th scope="col" class="p-3">Guests</th>
                                <th scope="col" class="p-3">Status</th>
                                <th scope="col" class="p-3">Revision</th>
                                <th scope="col" class="p-3">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($entries as $item)
                                <tr wire:key="booking-proposal-{{ $item['id'] }}" class="border-t">
                                    <td class="p-3">{{ $item['eventDate'] }}</td>
                                    <td class="p-3 font-mono text-xs">{{ $item['id'] }}</td>
                                    <td class="p-3">{{ $item['hallName'] }}</td>
                                    <td class="p-3">{{ ucfirst($item['program']) }}</td>
                                    <td class="p-3">{{ $item['guests'] }}</td>
                                    <td class="p-3 font-medium">{{ str_replace('_', ' ', ucfirst($item['status'])) }}</td>
                                    <td class="p-3">{{ $item['revision'] }}</td>
                                    <td class="p-3">
                                        <div class="flex flex-wrap gap-2">
                                            <button type="button" wire:click="selectDraft('{{ $item['id'] }}')" class="rounded-lg border px-3 py-2 font-semibold">Review/edit</button>
                                            <a class="rounded-lg border px-3 py-2 underline"
                                                href="{{ \App\Filament\Pages\ApprovalCenter::getUrl(['company' => $companyId, 'booking' => $item['id']]) }}">
                                                Linked approvals
                                            </a>
                                            @if ($item['status'] === 'under_review')
                                                <a class="rounded-lg border px-3 py-2 underline"
                                                    href="{{ \App\Filament\Pages\FinanceDesk::getUrl(['company' => $companyId, 'proposal' => $item['id']]) }}">
                                                    Freeze rental preview
                                                </a>
                                            @endif
                                            @if (in_array($item['status'], ['draft', 'under_review'], true))
                                                <a class="rounded-lg border px-3 py-2 underline"
                                                    href="{{ \App\Filament\Pages\OperationsPlanning::getUrl(['company' => $companyId, 'proposal' => $item['id']]) }}">
                                                    Plan setup
                                                </a>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="p-4 text-sm">No matching internal proposals.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <nav aria-label="Proposal pagination" class="flex items-center gap-3">
                    <button type="button" wire:click="previousPage" @disabled($offset === 0) class="rounded-lg border px-4 py-2 text-sm disabled:opacity-50">Previous</button>
                    <button type="button" wire:click="nextPage" @disabled(! $hasMore || $offset > 5000 - 25) class="rounded-lg border px-4 py-2 text-sm disabled:opacity-50">Next</button>
                </nav>
                <p class="text-xs">Only an unconfirmed draft can be edited. No action here reserves a hall, charges money or confirms an event.</p>
            </section>
        @endif
        @if ($selectedBookingId !== null && $selectedCompanyId === $companyId)
            <section aria-label="Selected internal booking proposal" class="space-y-4 rounded-xl border p-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold">Internal proposal review</h2>
                        <p class="text-sm">Saved revision {{ $selectedRevision }}. State: {{ str_replace('_', ' ', $selectedStatus) }}.</p>
                    </div>
                    <button type="button" wire:click="selectDraft('{{ $selectedBookingId }}')" class="rounded-lg border px-3 py-2">Reload saved proposal</button>
                    <button type="button" wire:click="clearSelection" class="rounded-lg border px-3 py-2">Close</button>
                </div>
                <p class="text-sm">No customer contact is shown. This workflow does not check physical availability, reserve the hall, issue a quote or confirm a booking.</p>
                <div class="grid gap-4 md:grid-cols-2">
                    <label class="block space-y-1">
                        <span class="text-sm font-medium">Hall</span>
                        <select wire:model="editHallId" @disabled($selectedStatus !== 'draft') class="w-full rounded-lg border p-3 text-gray-900">
                            @foreach ($this->hallOptions() as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                        @error('editHallId') <span role="alert" class="text-sm text-red-600">{{ $message }}</span> @enderror
                    </label>
                    <label class="block space-y-1">
                        <span class="text-sm font-medium">Proposed date</span>
                        <input type="date" wire:model="editDate" @disabled($selectedStatus !== 'draft') class="w-full rounded-lg border p-3 text-gray-900">
                        @error('editDate') <span role="alert" class="text-sm text-red-600">{{ $message }}</span> @enderror
                    </label>
                    <label class="block space-y-1">
                        <span class="text-sm font-medium">Program</span>
                        <select wire:model="editProgram" @disabled($selectedStatus !== 'draft') class="w-full rounded-lg border p-3 text-gray-900">
                            <option value="morning">Morning</option>
                            <option value="noon">Noon</option>
                            <option value="evening">Evening</option>
                        </select>
                        @error('editProgram') <span role="alert" class="text-sm text-red-600">{{ $message }}</span> @enderror
                    </label>
                    <label class="block space-y-1">
                        <span class="text-sm font-medium">Expected guests</span>
                        <input type="number" min="1" step="1" wire:model="editGuests" @disabled($selectedStatus !== 'draft') class="w-full rounded-lg border p-3 text-gray-900">
                        @error('editGuests') <span role="alert" class="text-sm text-red-600">{{ $message }}</span> @enderror
                    </label>
                </div>
                @if ($selectedStatus !== 'withdrawn')
                    <label class="block space-y-1">
                        <span class="text-sm font-medium">Required change reason (no customer names or contact information)</span>
                        <textarea wire:model="editReason" maxlength="500" rows="2" class="w-full rounded-lg border p-3 text-gray-900"></textarea>
                        @error('editReason') <span role="alert" class="text-sm text-red-600">{{ $message }}</span> @enderror
                    </label>
                    <div class="flex flex-wrap gap-3">
                        @if ($selectedStatus === 'draft')
                            <button type="button" wire:click="saveDraft" wire:loading.attr="disabled" class="rounded-lg border px-4 py-2 font-semibold">Save draft changes</button>
                            <button type="button" wire:click="submitReview" wire:loading.attr="disabled" class="rounded-lg border px-4 py-2 font-semibold">Submit saved draft for review</button>
                        @endif
                        <button type="button" wire:click="withdrawDraft" wire:loading.attr="disabled" class="rounded-lg border px-4 py-2">Withdraw unconfirmed proposal</button>
                    </div>
                @else
                    <p class="text-sm font-semibold">This proposal is withdrawn. Its immutable history is retained.</p>
                @endif
            </section>
        @endif
    </div>
</x-filament-panels::page>
