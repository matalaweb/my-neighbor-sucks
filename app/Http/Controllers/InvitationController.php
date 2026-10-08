<?php

namespace App\Http\Controllers;

use App\Models\Invitation;
use App\Services\Accounts\MembershipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class InvitationController extends Controller
{
    public function show(string $token): View
    {
        $invitation = Invitation::query()->where('token_hash', hash('sha256', $token))->first();

        return view('invitations.accept', [
            'invitation' => $invitation?->isUsable() ? $invitation : null,
            'token' => $token,
        ]);
    }

    public function accept(Request $request, string $token, MembershipService $memberships): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(12)],
        ]);

        $user = $memberships->accept($token, $data['name'], $data['password']);
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect('/app');
    }
}
