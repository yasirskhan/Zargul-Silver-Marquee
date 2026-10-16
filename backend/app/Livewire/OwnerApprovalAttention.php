<?php

namespace App\Livewire;

use App\Models\User;
use App\Services\ApprovalWorkflowService;
use Filament\Facades\Filament;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class OwnerApprovalAttention extends Component
{
    #[Locked]
    public array $items = [];

    #[Locked]
    public string $fingerprint = '';

    #[Locked]
    public bool $open = false;

    public function mount(): void
    {
        $this->reload();
    }

    private function owner(): ?User
    {
        $actor = Filament::auth()->user();
        return $actor instanceof User && $actor->is_active && $actor->hasVerifiedEmail()
            ? $actor : null;
    }

    private function sessionKey(User $actor): string
    {
        return 'venuedesk.owner_approval_notice_dismissed.'.$actor->getKey();
    }

    private function reload(): void
    {
        $actor = $this->owner();
        if (! $actor) {
            $this->items = [];
            $this->fingerprint = '';
            $this->open = false;
            return;
        }

        $attention = app(ApprovalWorkflowService::class)->ownerPendingAttention($actor);
        $this->items = $attention['items'];
        $this->fingerprint = $attention['fingerprint'];
        $this->open = $this->fingerprint !== ''
            && session($this->sessionKey($actor)) !== $this->fingerprint;
    }

    public function dismiss(): void
    {
        // Re-evaluate role, tenant and current requests; never trust locked
        // Livewire state as a grant or suppress a newer pending request.
        $original = $this->fingerprint;
        $this->reload();
        $actor = $this->owner();
        if ($actor && $this->fingerprint !== '' && hash_equals($this->fingerprint, $original)) {
            session()->put($this->sessionKey($actor), $this->fingerprint);
            $this->open = false;
        }
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        return view('livewire.owner-approval-attention');
    }
}
