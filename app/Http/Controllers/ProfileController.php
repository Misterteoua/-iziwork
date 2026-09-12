<?php

namespace App\Http\Controllers;

use App\Models\AdminUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class ProfileController extends Controller
{
    /**
     * Same minimum as the install wizard and admin:reset-password : local,
     * prod and browser-changed passwords must all share one strength rule.
     */
    private const MIN_PASSWORD_LENGTH = 12;

    public function edit()
    {
        $admin = $this->currentAdmin();

        return view('admin.profile', compact('admin'));
    }

    public function updatePassword(Request $request)
    {
        $admin = $this->currentAdmin();

        $validated = $request->validate([
            'current_password' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:'.self::MIN_PASSWORD_LENGTH, 'max:255', 'confirmed'],
        ], [
            'current_password.required' => 'Le mot de passe actuel est obligatoire.',
            'password.required' => 'Le nouveau mot de passe est obligatoire.',
            'password.min' => 'Le nouveau mot de passe doit faire au moins '.self::MIN_PASSWORD_LENGTH.' caractères.',
            'password.confirmed' => 'La confirmation ne correspond pas au nouveau mot de passe.',
        ]);

        // Manual check rather than the current_password rule: the app uses a
        // custom session guard, not the default user provider.
        if (! Hash::check($validated['current_password'], $admin->password_hash)) {
            return back()
                ->withErrors(['current_password' => 'Le mot de passe actuel est incorrect.'])
                ->onlyInput('current_password');
        }

        // The model's 'hashed' cast bcrypts the value on save.
        $admin->password_hash = $validated['password'];
        $admin->save();

        // A password change invalidates the current session fingerprint:
        // regenerate the ID and refresh the cached session payload.
        $request->session()->regenerate();
        $request->session()->put('admin_user', [
            'id' => $admin->id,
            'username' => $admin->username,
            'email' => $admin->email,
            'role' => $admin->role,
        ]);

        return redirect()->route('admin.profile')
            ->with('success', 'Mot de passe mis à jour avec succès !');
    }

    private function currentAdmin(): AdminUser
    {
        $adminId = session('admin_user.id');
        \assert(is_numeric($adminId));

        return AdminUser::findOrFail((int) $adminId);
    }
}
