<x-filament-panels::page>
    <section class="space-y-5" aria-label="Private HAFZATECH installation enrollment">
        <h2 class="text-lg font-semibold">Connect your venue to HAFZATECH</h2>
        <p class="text-sm">A verified company owner can register the installation using enrollment details provided by HAFZATECH. This step does not issue or activate a paid license.</p>
        <form wire:submit="connect" class="space-y-4">
            <label class="block space-y-1">
                <span>Operating company</span>
                <select wire:model="companyId" class="w-full rounded-lg border p-3">
                    @foreach (\App\Filament\Pages\InstallationSetup::allowedCompanies() as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block space-y-1">
                <span>Enrollment ID</span>
                <input wire:model="enrollmentId" autocomplete="off" required class="w-full rounded-lg border p-3">
                @error('enrollmentId') <span role="alert">{{ $message }}</span> @enderror
            </label>
            <label class="block space-y-1">
                <span>One-time enrollment token</span>
                <input type="password" wire:model="enrollmentToken" autocomplete="off" required class="w-full rounded-lg border p-3">
                @error('enrollmentToken') <span role="alert">{{ $message }}</span> @enderror
            </label>
            <label class="block space-y-1">
                <span>Vendor customer ID</span>
                <input wire:model="vendorCustomerId" autocomplete="off" required class="w-full rounded-lg border p-3">
                @error('vendorCustomerId') <span role="alert">{{ $message }}</span> @enderror
            </label>
            <label class="block space-y-1">
                <span>Verified venue domain</span>
                <input wire:model="verifiedDomain" autocomplete="off" required class="w-full rounded-lg border p-3" placeholder="venue.example.com">
                @error('verifiedDomain') <span role="alert">{{ $message }}</span> @enderror
            </label>
            <button type="submit" wire:loading.attr="disabled" wire:target="connect" class="rounded-lg border px-4 py-2">Register installation</button>
        </form>
        @if ($statusMessage)
            <p role="status" aria-live="polite">{{ $statusMessage }}</p>
        @endif
    </section>
</x-filament-panels::page>
