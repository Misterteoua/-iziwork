@extends('layouts.app')

@section('title', 'Espace de correction')

@section('content')
<div class="max-w-xl mx-auto px-4 sm:px-0">
    <div class="bg-white rounded-2xl shadow-card border border-slate-200/70 p-6 sm:p-8">
        <h1 class="text-xl font-bold tracking-tight text-slate-900">Espace de correction</h1>

        <p class="mt-3 text-sm text-slate-600">
            Cet espace est réservé aux correcteurs invités par l'organisation. Il s'ouvre par le
            <strong>lien personnel</strong> qui vous a été transmis — c'est ce lien qui identifie votre mission.
        </p>

        <div class="mt-5 rounded-xl bg-slate-50 border border-slate-200/70 px-4 py-3">
            <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Ce qu'il vous faut</p>
            <ul class="mt-2 space-y-1.5 text-sm text-slate-700 list-disc list-inside">
                <li>le lien personnel reçu (il ressemble à <span class="font-mono text-xs">…/correction/Ab12Cd34</span>) ;</li>
                <li>l'adresse email à laquelle il vous a été adressé ;</li>
                <li>votre référence de correction à dix caractères.</li>
            </ul>
        </div>

        <p class="mt-4 text-xs text-slate-500">
            Lien perdu, référence égarée ? Demandez-les à l'organisation : ni l'un ni l'autre ne se
            devine, et l'accès se ferme automatiquement à l'échéance de votre mission.
        </p>
    </div>
</div>
@endsection
