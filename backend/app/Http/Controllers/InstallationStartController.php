<?php
namespace App\Http\Controllers;

use App\Services\OwnerBootstrapService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Token-gated one-time browser start for the first company. */
final class InstallationStartController
{
    public function show()
    {
        $this->guard();
        $checks = $this->readiness();
        $installed = $this->installedWhenReachable($checks);
        if ($installed === true) {
            return redirect('/admin/login');
        }
        return response()->view('site.install.start', [
            'installed' => false,
            'checks' => $checks,
            'ready' => collect($checks)->every(fn (array $check): bool => $check['ok'])
                && $installed === false,
        ]);
    }

    public function store(Request $request, OwnerBootstrapService $owners)
    {
        $this->guard();
        $checks = $this->readiness();
        abort_unless(collect($checks)->every(fn (array $check): bool => $check['ok']), 503,
            'Server readiness checks have not passed.');
        $installed = $this->installedWhenReachable($checks);
        if ($installed === true) {
            return redirect('/admin/login');
        }
        abort_if($installed === null, 503, 'Installation state could not be verified.');
        $data = $request->validate([
            'operator_token' => ['required', 'string', 'max:256'],
            'company_name' => ['required', 'string', 'min:2', 'max:120'],
            'owner_name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email', 'max:254'],
        ]);
        $expected = (string) config('installation.operator_token');
        if ($expected === '' || ! hash_equals($expected, $data['operator_token'])) {
            abort(403, 'A valid installation token is required.');
        }
        try {
            $issued = $owners->issue($data['company_name'], $data['owner_name'], $data['email']);
        } catch (ConflictHttpException) {
            abort(410, 'This installation already has an Owner.');
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors(['installation' => $exception->getMessage()])->withInput();
        }
        return redirect()->route('installation.owner.show', ['token' => $issued['token']]);
    }

    /** @param array<int,array{label:string,ok:bool,detail:string}> $checks */
    private function installedWhenReachable(array $checks): ?bool
    {
        if (! collect($checks)->contains(fn (array $check): bool => $check['label'] === 'PostgreSQL connection' && $check['ok'])) {
            return null;
        }
        try {
            return $this->installed();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<int,array{label:string,ok:bool,detail:string}> */
    private function readiness(): array
    {
        $dbOk = false;
        $dbDetail = 'Connection failed';
        try {
            DB::select('select 1');
            $dbOk = true;
            $dbDetail = 'PostgreSQL connection succeeded';
        } catch (\Throwable) {
            $dbDetail = 'Database connection failed';
        }
        $storage = storage_path('framework');
        $extensions = ['pdo_pgsql', 'mbstring', 'openssl', 'fileinfo', 'gd'];
        $missing = array_values(array_filter($extensions, fn (string $ext): bool => ! extension_loaded($ext)));
        $production = app()->environment('production');
        return [
            ['label' => 'PHP 8.3 or newer', 'ok' => version_compare(PHP_VERSION, '8.3.0', '>='),
                'detail' => $production ? (version_compare(PHP_VERSION, '8.3.0', '>=') ? 'Supported version' : 'Unsupported version') : PHP_VERSION],
            ['label' => 'Required PHP extensions', 'ok' => $missing === [],
                'detail' => $missing === [] ? 'pdo_pgsql, mbstring, openssl, fileinfo, gd' : 'Missing: '.implode(', ', $missing)],
            ['label' => 'PostgreSQL connection', 'ok' => $dbOk, 'detail' => $dbDetail],
            ['label' => 'Writable storage', 'ok' => is_dir($storage) && is_writable($storage),
                'detail' => is_dir($storage) && is_writable($storage) ? 'Writable' : 'Not writable'],
            ['label' => 'Application key', 'ok' => filled(config('app.key')), 'detail' => filled(config('app.key')) ? 'Configured' : 'APP_KEY missing'],
            ['label' => 'Installation token', 'ok' => $this->tokenConfigured(), 'detail' => $this->tokenConfigured() ? 'Configured' : 'OPERATOR TOKEN missing or too short'],
        ];
    }

    private function tokenConfigured(): bool
    {
        $token = (string) config('installation.operator_token');
        $minimum = app()->environment('production') ? 32 : 16;
        return strlen($token) >= $minimum;
    }

    private function guard(): void
    {
        abort_unless((bool) config('installation.enabled', true), 404);
        abort_unless($this->tokenConfigured(), 404);
    }

    private function installed(): bool
    {
        return DB::table('company_memberships')->where('role', 'owner')->exists();
    }
}
