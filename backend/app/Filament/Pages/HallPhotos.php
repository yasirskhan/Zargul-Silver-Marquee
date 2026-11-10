<?php

namespace App\Filament\Pages;

use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\User;
use App\Services\HallMediaService;
use App\Services\TenantAccess;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

final class HallPhotos extends Page
{
    protected string $view = 'filament.pages.hall-photos';
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedPhoto;
    protected static ?string $navigationLabel = 'Hall photography';
    protected static ?string $slug = 'hall-photos';

    #[Url(as: 'company')] public ?string $companyId = null;
    #[Url(as: 'hall')] public ?string $hallId = null;
    public array $selectedMediaIds = [];
    public string $reason = '';
    public string $message = '';

    public static function canAccess(): bool
    {
        $actor = Filament::auth()->user();
        return $actor instanceof User && $actor->is_active && $actor->hasVerifiedEmail()
            && CompanyMembership::query()->where('user_id', $actor->id)
                ->whereIn('role', ['owner', 'admin'])->where('is_active', true)
                ->whereHas('company', fn ($q) => $q->where('is_active', true))->exists();
    }

    public function mount(): void
    {
        if ($this->companyId !== null && ! array_key_exists($this->companyId, $this->companyOptions())) {
            abort(403);
        }
        $this->companyId ??= array_key_first($this->companyOptions());
        $this->hallId ??= array_key_first($this->hallOptions());
        $this->loadSelected();
    }

    public function companyOptions(): array
    {
        $actor = Filament::auth()->user();
        if (! $actor instanceof User) return [];
        return CompanyMembership::query()->where('user_id', $actor->id)
            ->whereIn('role', ['owner', 'admin'])->where('is_active', true)
            ->whereHas('company', fn ($q) => $q->where('is_active', true))
            ->with('company')->get()->mapWithKeys(fn ($m) => [$m->company_id => $m->company->name])->all();
    }

    public function hallOptions(): array
    {
        if (! $this->companyId || ! array_key_exists($this->companyId, $this->companyOptions())) return [];
        return Hall::query()->where('company_id', $this->companyId)->where('is_active', true)
            ->orderBy('name')->pluck('name', 'id')->all();
    }

    public function updatedCompanyId(): void
    {
        $this->hallId = array_key_first($this->hallOptions());
        $this->loadSelected();
    }

    public function updatedHallId(): void
    {
        $this->loadSelected();
    }

    public function loadSelected(): void
    {
        $this->message = '';
        $actor = Filament::auth()->user();
        if (! $actor instanceof User) {
            $this->selectedMediaIds = [];
            return;
        }
        $this->selectedMediaIds = $this->hallId
            ? app(HallMediaService::class)->selectedMedia($actor, (string) $this->companyId, (string) $this->hallId)
            : [];
    }

    public function media(): array
    {
        $actor = Filament::auth()->user();
        return $actor instanceof User && $this->companyId
            ? app(HallMediaService::class)->availableMedia($actor, $this->companyId) : [];
    }

    public function save(): void
    {
        $actor = Filament::auth()->user();
        abort_unless($actor instanceof User, 403);
        $this->validate(['hallId' => ['required', 'uuid'], 'reason' => ['required', 'string', 'max:500']]);
        app(HallMediaService::class)->replace($actor, (string) $this->companyId,
            (string) $this->hallId, $this->selectedMediaIds, $this->reason);
        $this->message = 'Hall photography saved. Publish the website to update the public snapshot.';
        $this->reason = '';
    }
}