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
        return response()->view('site.install.start', ['installed' => false]);
    }

    public function store(Request $request, OwnerBootstrapService $owners)
    {
        $this->guard();
        if ($this->installed()) {
            return redirect('/admin/login');
        }
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
