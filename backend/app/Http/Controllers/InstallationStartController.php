<?php
namespace App\Http\Controllers;

use App\Services\OwnerBootstrapService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Token-gated browser start for the first company; never a public installer. */
final class InstallationStartController
{
    public function show()
    {
        $this->guard();
        if ($this->installed()) {
            return redirect('/admin/login');
        }
        $checks = $this->readiness();
        return response()->view('site.install.start', [
            'installed' => false,
            'checks' => $checks,
            'ready' => collect($checks)->every(fn (array $check): bool => $check['ok']),
        ]);
    }

    public function store(Request $request, OwnerBootstrapService $owners)
    {
        $this->guard();
        if ($this->installed()) {
            return redirect('/admin/login');
        }
        $checks = $this->readiness();
        abort_unless(collect($checks)->every(fn (array $check): bool => $check['ok']), 503, 'Server readiness checks have not passed.');
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

    /** @return array<int,array{label:string,ok:bool,detail:string}> */
    private function readiness(): array
    {
        $dbOk = false;
        $dbDetail = 'Connection failed';
        try {
            DB::select('select 1');
            $dbOk = true;
            $dbDetail = 'PostgreSQL connection succeeded';
        } catch (\Throwable $e) {
            $dbDetail = 'Database connection failed';
        }
        $storage = storage_path('framework');
        $extensions = ['pdo_pgsql', 'mbstring', 'openssl', 'fileinfo', 'gd'];
        $missing = array_values(array_filter($extensions, fn (string $ext): bool => ! extension_loaded($ext)));
        return [
            ['label' => 'PHP 8.3 or newer', 'ok' => version_compare(PHP_VERSION, '8.3.0', '>='), 'detail' => PHP_VERSION],
            ['label' => 'Required PHP extensions', 'ok' => $missing === [], 'detail' => $missing === [] ? 'pdo_pgsql, mbstring, openssl, fileinfo, gd' : 'Missing: '.implode(', ', $missing)],
            ['label' => 'PostgreSQL connection', 'ok' => $dbOk, 'detail' => $dbDetail],
            ['label' => 'Writable storage', 'ok' => is_dir($storage) && is_writable($storage), 'detail' => $storage],
            ['label' => 'Application key', 'ok' => filled(config('app.key')), 'detail' => filled(config('app.key')) ? 'Configured' : 'APP_KEY missing'],
            ['label' => 'Installation token', 'ok' => (string) config('installation.operator_token') !== '', 'detail' => (string) config('installation.operator_token') !== '' ? 'Configured' : 'OPERATOR TOKEN missing'],
        ];
    }

    private function guard(): void
    {
        abort_if(config('installation.operator_token') === '', 404);
        abort_unless(app()->environment(['local', 'testing']), 404);
    }

    private function installed(): bool
    {
        return DB::table('company_memberships')->where('role', 'owner')->exists();
    }
}
