<?php

namespace App\Http\Controllers;

use App\Models\AdminUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    private const NO_ADMIN_MESSAGE = "Aucun compte administrateur n'existe encore. "
        .'Exécutez « php artisan db:seed » pour créer le compte par défaut.';

    public function showLogin()
    {
        if (session()->has('admin_user')) {
            return redirect()->route('admin.dashboard');
        }

        return view('auth.login', [
            'noAdminAccount' => ! AdminUser::query()->exists(),
            'noAdminMessage' => self::NO_ADMIN_MESSAGE,
        ]);
    }

    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email|max:255',
            'password' => 'required|string|max:255',
        ]);

        // Without an account there is nothing to authenticate against: send
        // the user back to the login page, which explains how to create one
        // instead of showing a misleading "wrong password" error.
        if (! AdminUser::query()->exists()) {
            return redirect()->route('login');
        }

        $email = Str::lower(trim($validated['email']));
        $user = AdminUser::where('email', $email)->first();

        if (! $user || ! Hash::check($validated['password'], $user->password_hash)) {
            return back()->withErrors([
                'email' => 'Email ou mot de passe incorrect.',
            ])->onlyInput('email');
        }

        $request->session()->regenerate();
        $request->session()->put('admin_user', [
            'id' => $user->id,
            'username' => $user->username,
            'email' => $user->email,
            'role' => $user->role,
        ]);

        return redirect()->intended(route('admin.dashboard'));
    }

    public function logout(Request $request)
    {
        $request->session()->forget('admin_user');
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
