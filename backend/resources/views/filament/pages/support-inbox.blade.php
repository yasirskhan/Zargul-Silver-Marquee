<x-filament-panels::page>
    <section class="space-y-5" aria-label="Private HAFZATECH support replies">
        <h2 class="text-lg font-semibold">HAFZATECH Support</h2>
        <p class="text-sm text-gray-600 dark:text-gray-300">
            Secure replies already delivered to your venue. Reading them does not verify or renew a license.
        </p>
        <label class="block space-y-1">
            <span class="text-sm font-medium">Operating company</span>
            <select wire:model.live="companyId" class="w-full rounded-lg border p-3 text-gray-900">
                @foreach($this->companyOptions() as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </label>
        @if(config('central_control.support_enabled'))
            <form wire:submit="submitTicket" class="rounded-lg border p-4 space-y-3">
                <h3 class="font-semibold">Ask HAFZATECH Support</h3>
                <p class="text-xs">Enter only the details you choose to share; your event, guest and payment records are not attached.</p>
                <label class="block space-y-1">
                    <span class="text-sm">Subject</span>
                    <input wire:model="newSubject" type="text" maxlength="160" required class="w-full rounded-lg border p-2 text-gray-900" />
                </label>
                @error('newSubject') <p role="alert" class="text-sm">{{ $message }}</p> @enderror
                <label class="block space-y-1">
                    <span class="text-sm">Message</span>
                    <textarea wire:model="newMessage" rows="4" maxlength="4000" required class="w-full rounded-lg border p-2 text-gray-900"></textarea>
                </label>
                @error('newMessage') <p role="alert" class="text-sm">{{ $message }}</p> @enderror
                <button type="submit" wire:loading.attr="disabled" wire:target="submitTicket" class="rounded-lg border px-4 py-2 text-sm">Queue support request</button>
            </form>
        @else
            <p class="text-xs">Sending new requests is disabled until secure HAFZATECH support delivery is activated.</p>
        @endif
        @if($composeResult)
            <p role="status" aria-live="polite">{{ $composeResult }}</p>
        @endif
        <p role="status" aria-live="polite">{{ $unread }} unread {{ $unread === 1 ? 'reply' : 'replies' }}</p>
        <button type="button" wire:click="refreshMessages" class="rounded-lg border px-4 py-2 text-sm">Refresh local inbox</button>
        @forelse($messages as $message)
            <article class="rounded-lg border p-4 space-y-3">
                <h3 class="font-semibold">Ticket {{ $message['ticket_number'] }}</h3>
                <p class="whitespace-pre-wrap text-sm">{{ $message['body'] }}</p>
                <p class="text-xs text-gray-600 dark:text-gray-300">
                    Received {{ $message['received_at'] }}
                </p>
                @if($message['read_at'] === null)
                    <button type="button" wire:click="markMessageRead(@js($message['id']))"
                        class="rounded-lg border px-3 py-2 text-sm">Mark as read</button>
                @else
                    <span class="text-xs">Read{{ $message['read_by'] ? ' by '.$message['read_by'] : '' }}{{ $message['read_at'] ? ' at '.$message['read_at'] : '' }}</span>
                @endif
            </article>
        @empty
            <p class="text-sm">No HAFZATECH support replies have been received for this company.</p>
        @endforelse
        <nav class="flex flex-wrap gap-3" aria-label="Support reply pagination">
            @if($olderBeforeEvent !== null)
                <button type="button" wire:click="showRecentReplies"
                    class="rounded-lg border px-4 py-2 text-sm">Newest replies</button>
            @endif
            @if($hasOlderMessages)
                <button type="button" wire:click="loadOlderReplies"
                    class="rounded-lg border px-4 py-2 text-sm">Older replies</button>
            @endif
        </nav>
        @if(!config('central_control.support_reply_pull_enabled'))
            <p class="text-xs">Automatic support-reply synchronization has not been activated.</p>
        @endif
    </section>
</x-filament-panels::page>
