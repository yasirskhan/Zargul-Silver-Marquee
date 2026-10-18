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
                    <span class="text-xs">Read</span>
                @endif
            </article>
        @empty
            <p class="text-sm">No HAFZATECH support replies have been received for this company.</p>
        @endforelse
        @if(!config('central_control.support_reply_pull_enabled'))
            <p class="text-xs">Automatic support-reply synchronization has not been activated.</p>
        @endif
    </section>
</x-filament-panels::page>
