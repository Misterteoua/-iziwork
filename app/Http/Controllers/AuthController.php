<?php

namespace App\Http\Controllers;

use App\Models\AdminUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function showLogin()
    {
        if (session()->has('admin_user')) {
            return redirect()->route('admin.dashboard');
        }

        return view('auth.login');
    }

    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email|max:255',
            'password' => 'required|string|max:255',
        ]);

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
