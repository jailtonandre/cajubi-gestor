<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function edit(Request $request): View
    {
        return view('account.profile', ['user' => $request->user()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($request->user())],
        ]);
        $user = $request->user();
        $user->fill($validated);
        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }
        $user->save();

        return to_route('account.edit')->with('success', 'Dados da conta atualizados.');
    }

    public function security(): View
    {
        return view('account.security');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'string', 'max:255', Password::min(12)->mixedCase()->numbers()->symbols(), 'confirmed', 'different:current_password'],
        ], ['current_password.current_password' => 'A senha atual está incorreta.']);

        $user = $request->user();
        $user->forceFill(['password' => Hash::make($validated['password']), 'remember_token' => Str::random(60)])->save();
        $request->session()->put('password_hash_web', $user->password);
        $request->session()->regenerate();

        return to_route('account.security')->with('success', 'Senha alterada com segurança.');
    }
}
