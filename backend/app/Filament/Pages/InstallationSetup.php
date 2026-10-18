<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\CentralOwnerInstallationOnboarding;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Attributes\Locked;
use Throwable;

final class InstallationSetup extends Page
{
    protected string $view = 'filament.pages.installation-setup';
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedLink;
    protected static ?string $navigationLabel = 'Connect HAFZATECH';
    protected static ?string $slug = 'installation-setup';

    #[Url(as: 'company')]
    public ?string $companyId = null;

    public string $enrollmentId = '';
    public string $enrollmentToken = '';
    public string $vendorCustomerId = '';
    public string $verifiedDomain = '';
    #[Locked]
    public ?string $statusMessage = null;

    /** @return array<string,string> */
    public static function allowedCompanies(): array
    {
        if (config('central_control.activation_enabled') !== true) return [];
        $actor = Filament::auth()->user();
        if (!$actor instanceof User ||
            !DB::table('users')->where('id', $actor->getKey())
                ->where('is_active', true)->whereNotNull('email_verified_at')->exists()) {
            return [];
        }
        $bound = DB::table('central_installation_activation')
            ->where('singleton_id', 1)->value('company_id');
        $query = DB::table('company_memberships as m')
            ->join('companies as c', 'c.id', '=', 'm.company_id')
            ->where('m.user_id', $actor->getKey())
            ->where('m.role', 'owner')
            ->where('m.is_active', true)->where('c.is_active', true);
        if ($bound !== null) $query->where('c.id', $bound);
        return $query->orderBy('c.name')->pluck('c.name', 'c.id')->all();
    }

    public static function canAccess(): bool
    {
        return static::allowedCompanies() !== [];
    }

    public function mount(): void
    {
        $choices = static::allowedCompanies();
        abort_unless($choices !== [], 403);
        $requested = $this->companyId ?? request()->query('company');
        abort_unless($requested === null || (is_string($requested) &&
            array_key_exists($requested, $choices)), 403);
        $this->companyId = $requested ?? array_key_first($choices);
    }

    public function hydrate(): void
    {
        $this->assertCompany();
    }

    private function assertCompany(): void
    {
        abort_unless($this->companyId !== null &&
            array_key_exists($this->companyId, static::allowedCompanies()), 403);
    }

    public function alreadyConnected(): bool
    {
        $this->assertCompany();
        return DB::table('central_installation_activation')
            ->where('singleton_id', 1)
            ->where('company_id', $this->companyId)
            ->where('status', 'registered_pending_license')->exists();
    }

    public function connect(): void
    {
        $this->assertCompany();
        $actor = Filament::auth()->user();
        abort_unless($actor instanceof User, 403);
        $this->statusMessage = null;
        if ($this->alreadyConnected()) {
            $this->enrollmentToken = '';
            $this->statusMessage = 'Installation is already registered. Open Licensing to verify a signed paid license.';
            return;
        }
        try {
            $data = $this->validate([
                'enrollmentId' => ['required', 'uuid'],
                'enrollmentToken' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{32,256}$/'],
                'vendorCustomerId' => ['required', 'uuid'],
                'verifiedDomain' => ['required', 'string', 'max:253',
                    'regex:/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/'],
            ]);
            app(CentralOwnerInstallationOnboarding::class)->enroll(
                $actor, $this->companyId, $data['enrollmentId'],
                $data['enrollmentToken'], $data['vendorCustomerId'],
                $data['verifiedDomain']
            );
            $this->statusMessage = 'Installation registered. A separately verified paid license is still required.';
        } catch (AuthorizationException) {
            abort(403);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (Throwable) {
            $this->statusMessage = 'Enrollment could not be completed. Contact HAFZATECH support.';
        } finally {
            $this->enrollmentToken = '';
        }
    }
}
