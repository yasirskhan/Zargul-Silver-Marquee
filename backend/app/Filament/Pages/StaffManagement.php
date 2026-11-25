<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\StaffDirectoryService;
use App\Services\StaffInvitationService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

final class StaffManagement extends Page
{
    protected string $view = 'filament.pages.staff-management';

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $navigationLabel = 'Staff';

    protected static ?string $slug = 'staff-management';

    #[Url(as: 'company')]
    public ?string $companyId = null;

    public string $email = '';
    public string $role = 'manager';
    public array $hallIds = [];
    public string $reason = '';
    public array $invitations = [];
    public string $staffSearch = '';
    public array $staffRows = [];
    #[Locked]
    public ?string $selectedStaffId = null;
    public string $staffRole = 'manager';
    public string $staffExpectedRole = '';
    public array $staffHallIds = [];
    public string $staffReason = '';
    public array $halls = [];
    public ?string $message = null;

    /** @return array<string,string> */
    private function companies(): array
    {
        $actor = Filament::auth()->user();
        if (! $actor instanceof User || ! $actor->is_active || ! $actor->hasVerifiedEmail()) {
            return [];
        }

        return DB::table('company_memberships as m')
            ->join('companies as c', 'c.id', '=', 'm.company_id')
            ->where('m.user_id', $actor->getKey())->where('m.role', 'owner')
            ->where('m.is_active', true)->where('c.is_active', true)
            ->pluck('c.name', 'c.id')->all();
    }

    public static function canAccess(): bool
    {
        $actor = Filament::auth()->user();
        return $actor instanceof User && $actor->is_active && $actor->hasVerifiedEmail()
            && DB::table('company_memberships')->where('user_id', $actor->getKey())
                ->where('role', 'owner')->where('is_active', true)->exists();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $companies = $this->companies();
        $this->companyId = $this->companyId ?? array_key_first($companies);
        abort_unless($this->companyId !== null && array_key_exists($this->companyId, $companies), 403);
        $this->refreshInvitations();
        $this->refreshStaff();
        $this->halls = DB::table('halls')->where('company_id', $this->companyId)
            ->where('is_active', true)
            ->orderBy('name')->get(['id', 'name'])->map(fn ($row) => (array) $row)->all();
    }

    public function hydrate(): void
    {
        abort_unless(static::canAccess() && $this->companyId !== null
            && array_key_exists($this->companyId, $this->companies()), 403);
        $this->refreshInvitations();
        $this->refreshStaff();
        $this->halls = DB::table('halls')->where('company_id', $this->companyId)
            ->where('is_active', true)
            ->orderBy('name')->get(['id', 'name'])->map(fn ($row) => (array) $row)->all();
    }


    public function refreshStaff(): void
    {
        $actor = Filament::auth()->user();
        if (! $actor instanceof User || $this->companyId === null) return;
        $this->staffRows = app(StaffDirectoryService::class)->staff($actor, $this->companyId, $this->staffSearch);
    }

    public function updatedStaffSearch(): void
    {
        $this->refreshStaff();
    }

    public function selectStaff(string $membershipId): void
    {
        $actor = Filament::auth()->user();
        abort_unless($actor instanceof User && $this->companyId !== null, 403);
        $row = collect(app(StaffDirectoryService::class)->staff($actor, $this->companyId))
            ->firstWhere('id', $membershipId);
        abort_unless($row !== null, 404);
        $this->selectedStaffId = $membershipId;
        $this->staffRole = $row['role'] === 'owner' ? 'owner' : $row['role'];
        $this->staffExpectedRole = $row['role'];
        $this->staffHallIds = $row['hallIds'];
        $this->staffReason = '';
    }

    public function closeStaff(): void
    {
        $this->selectedStaffId = null;
        $this->staffRole = 'manager';
        $this->staffHallIds = [];
        $this->staffReason = '';
    }

    public function saveStaff(): void
    {
        $actor = Filament::auth()->user();
        abort_unless($actor instanceof User && $this->companyId !== null && $this->selectedStaffId !== null, 403);
        $this->validate([
            'staffRole' => ['required', 'in:admin,manager,operations,reception,cashier'],
            'staffReason' => ['required', 'string', 'min:3', 'max:300'],
        ]);
        try {
            app(StaffDirectoryService::class)->update($actor, $this->companyId, $this->selectedStaffId,
                $this->staffRole, $this->staffHallIds, $this->staffReason, $this->staffExpectedRole);
        } catch (\Throwable $e) {
            $this->addError('staffReason', $e->getMessage());
            return;
        }
        $this->message = 'Staff role and hall assignments updated.';
        $this->refreshStaff();
        $this->closeStaff();
    }

    public function revokeStaff(string $membershipId): void
    {
        $actor = Filament::auth()->user();
        abort_unless($actor instanceof User && $this->companyId !== null, 403);
        $this->validate(['staffReason' => ['required', 'string', 'min:3', 'max:300']]);
        try {
            app(StaffDirectoryService::class)->revoke($actor, $this->companyId, $membershipId, $this->staffReason);
        } catch (\Throwable $e) {
            $this->addError('staffReason', $e->getMessage());
            return;
        }
        $this->message = 'Staff membership revoked and hall assignments removed.';
        $this->refreshStaff();
        $this->closeStaff();
    }

    public function refreshInvitations(): void
    {
        $actor = Filament::auth()->user();
        if (! $actor instanceof User || $this->companyId === null) {
            return;
        }
        $this->invitations = app(StaffInvitationService::class)->list($actor, $this->companyId);
    }

    public function invite(): void
    {
        $actor = Filament::auth()->user();
        abort_unless($actor instanceof User && $this->companyId !== null
            && array_key_exists($this->companyId, $this->companies()), 403);
        $this->validate([
            'email' => ['required', 'email', 'max:254'],
            'role' => ['required', 'in:admin,manager,reception,operations'],
            'reason' => ['required', 'string', 'min:3', 'max:300'],
        ]);
        try {
            $result = app(StaffInvitationService::class)->invite(
                $actor, $this->companyId, $this->email, $this->role,
                $this->hallIds, $this->reason,
            );
            $this->message = $result['delivery'] === 'sent'
                ? 'Invitation sent. Access requires acceptance, email verification and MFA.'
                : 'Invitation created, but mail delivery was not confirmed.';
            $this->email = '';
            $this->reason = '';
            $this->refreshInvitations();
        $this->refreshStaff();
        $this->halls = DB::table('halls')->where('company_id', $this->companyId)
            ->where('is_active', true)
            ->orderBy('name')->get(['id', 'name'])->map(fn ($row) => (array) $row)->all();
            Notification::make()->title('Staff invitation created')->success()->send();
        } catch (InvalidArgumentException|\Symfony\Component\HttpKernel\Exception\ConflictHttpException $exception) {
            throw ValidationException::withMessages(['email' => $exception->getMessage()]);
        }
    }

    public function revoke(string $id): void
    {
        $actor = Filament::auth()->user();
        abort_unless($actor instanceof User && $this->companyId !== null
            && array_key_exists($this->companyId, $this->companies()), 403);
        $this->validate(['reason' => ['required', 'string', 'min:3', 'max:300']]);
        app(StaffInvitationService::class)->revoke($actor, $this->companyId, $id, $this->reason);
        $this->message = 'Invitation revoked and active access deactivated.';
        $this->refreshInvitations();
        $this->refreshStaff();
        $this->halls = DB::table('halls')->where('company_id', $this->companyId)
            ->where('is_active', true)
            ->orderBy('name')->get(['id', 'name'])->map(fn ($row) => (array) $row)->all();
    }
}
