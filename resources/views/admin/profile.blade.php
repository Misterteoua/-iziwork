@extends('layouts.app')

@section('title', 'Mon profil')

@section('content')

<div class="max-w-2xl mx-auto">

    {{-- Page header --}}
    <div class="mb-8">
        <h1 class="text-xl sm:text-2xl font-bold text-slate-900 tracking-tight">Mon profil</h1>
        <p class="mt-1 text-sm text-slate-500">Gérez votre compte administrateur</p>
    </div>

    {{-- Account information --}}
    <div class="bg-white rounded-2xl shadow-card border border-slate-200/70 p-5 sm:p-8 mb-6">
        <h2 class="text-base font-semibold text-slate-900 mb-5">Informations du compte</h2>

        <dl class="space-y-4">
            <div class="flex items-center justify-between gap-4">
                <dt class="text-sm text-slate-500">Nom d'utilisateur</dt>
                <dd class="text-sm font-medium text-slate-900">{{ $admin->username }}</dd>
            </div>
            <div class="flex items-center justify-between gap-4">
                <dt class="text-sm text-slate-500">Adresse email</dt>
                <dd class="text-sm font-medium text-slate-900">{{ $admin->email }}</dd>
            </div>
            <div class="flex items-center justify-between gap-4">
                <dt class="text-sm text-slate-500">Rôle</dt>
                <dd class="text-sm font-medium text-slate-900 capitalize">{{ $admin->role }}</dd>
            </div>
        </dl>
    </div>

    {{-- Password change --}}
    <div class="bg-white rounded-2xl shadow-card border border-slate-200/70 p-5 sm:p-8">
        <h2 class="text-base font-semibold text-slate-900 mb-1">Changer le mot de passe</h2>
        <p class="text-sm text-slate-500 mb-6">Minimum 12 caractères. Utilisez le même mot de passe que votre compte de production si vous souhaitez rester cohérent entre local et prod.</p>

        <form method="POST" action="{{ route('admin.profile.password') }}" class="space-y-5" novalidate>
            @csrf
            @method('PATCH')

            <div>
                <label for="current_password" class="block text-sm font-medium text-slate-700 mb-1.5">Mot de passe actuel</label>
                <input id="current_password"
                       name="current_password"
                       type="password"
                       required
                       autocomplete="current-password"
                       class="w-full px-4 py-2.5 border rounded-xl text-sm text-slate-900 placeholder-slate-400 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150 {{ $errors->has('current_password') ? 'border-red-300 bg-red-50/30' : 'border-slate-300' }}"
                       placeholder="••••••••">
                @error('current_password')
                <p class="mt-1.5 text-sm text-red-600" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-slate-700 mb-1.5">Nouveau mot de passe</label>
                <input id="password"
                       name="password"
                       type="password"
                       required
                       minlength="12"
                       autocomplete="new-password"
                       class="w-full px-4 py-2.5 border rounded-xl text-sm text-slate-900 placeholder-slate-400 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150 {{ $errors->has('password') ? 'border-red-300 bg-red-50/30' : 'border-slate-300' }}"
                       placeholder="12 caractères minimum">
                @error('password')
                <p class="mt-1.5 text-sm text-red-600" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password_confirmation" class="block text-sm font-medium text-slate-700 mb-1.5">Confirmer le nouveau mot de passe</label>
                <input id="password_confirmation"
                       name="password_confirmation"
                       type="password"
                       required
                       autocomplete="new-password"
                       class="w-full px-4 py-2.5 border rounded-xl text-sm text-slate-900 placeholder-slate-400 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150 {{ $errors->has('password_confirmation') ? 'border-red-300 bg-red-50/30' : 'border-slate-300' }}"
                       placeholder="••••••••">
                @error('password_confirmation')
                <p class="mt-1.5 text-sm text-red-600" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <div class="pt-2">
                <button type="submit"
                        class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-6 py-2.5 border border-transparent text-sm font-semibold rounded-xl text-white gradient-bg hover:opacity-95 hover:shadow-card-hover focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-brand-500 transition-all duration-150">
                    Mettre à jour le mot de passe
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                </button>
            </div>
        </form>
    </div>

</div>

@endsection
