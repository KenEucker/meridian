<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\DisabledUserException;
use App\Services\Auth\MagicLinkService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MagicLinkController extends Controller
{
    public function __construct(private readonly MagicLinkService $magicLinks)
    {
    }

    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
        ]);

        try {
            $this->magicLinks->sendLoginLink($validated['email']);
        } catch (\InvalidArgumentException) {
            return back()
                ->withInput()
                ->withErrors(['email' => 'Enter a valid email address.']);
        }

        return redirect()
            ->route('auth.magic-link.sent')
            ->with('magic_link_email', $this->magicLinks->normalizeEmail($validated['email']));
    }

    public function sent(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has('magic_link_email')) {
            return redirect()->route('login');
        }

        return view('auth.magic-link-sent', [
            'email' => $request->session()->get('magic_link_email'),
        ]);
    }

    public function verify(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
        ]);

        try {
            $this->magicLinks->authenticateFromVerifiedEmail($validated['email']);
        } catch (DisabledUserException) {
            return redirect()
                ->route('login')
                ->withErrors(['email' => 'This account is disabled.']);
        } catch (\InvalidArgumentException) {
            abort(403);
        }

        $request->session()->regenerate();

        return redirect()->intended(config('meridian.magic_link.post_login_redirect'));
    }
}
