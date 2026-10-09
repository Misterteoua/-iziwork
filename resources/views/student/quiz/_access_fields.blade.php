{{--
    Les champs d'identification de la page d'accès : référence, nom, email,
    filière.

    Partagés par les deux écrans qui les demandent — la salle d'attente, où ils
    sont facultatifs et servent seulement à ne pas tout retaper, et le formulaire
    d'accès, où ils font entrer dans l'épreuve. Deux copies de ce bloc
    divergeraient : une règle ajoutée d'un côté manquerait de l'autre, et c'est
    l'entrée de l'étudiant qui en paierait le prix.

    Attend :
      - $usesReferences : l'épreuve est en mode « liste préparée » ;
      - $prepared       : ce que la salle d'attente a retenu (facultatif) ;
      - $optional       : aucun champ n'est obligatoire (salle d'attente) ;
      - $disabled       : champs inertes (aperçu enseignant).

    `old()` passe avant ce qui a été retenu : après un refus, l'étudiant doit
    retrouver ce qu'il vient de taper, et non ce qu'il avait saisi plus tôt.
--}}
@php
    $prepared = $prepared ?? [];
    $optional = $optional ?? false;
    $disabled = $disabled ?? false;
@endphp

@if($usesReferences)
<div>
    <label for="reference" class="block text-sm font-medium text-slate-700 mb-1.5">
        Votre référence @unless($optional)<span class="text-red-500" aria-hidden="true">*</span>@endunless
    </label>
    <input type="text" name="reference" id="reference"
           @unless($optional) required @endunless
           @if($disabled) disabled aria-disabled="true" @else autofocus @endif
           maxlength="10" autocapitalize="characters" autocomplete="off" spellcheck="false"
           value="{{ old('reference', $prepared['reference'] ?? null) }}"
           placeholder="10 caractères"
           class="w-full px-4 py-3 border border-slate-300 rounded-xl text-lg font-mono tracking-widest text-slate-900 placeholder-slate-300 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150">
    <p class="mt-1.5 text-xs text-slate-500">La référence qui vous a été remise. Elle tient lieu de numéro d'anonymat.</p>
    @error('reference')<p class="mt-1.5 text-sm text-red-600" role="alert">{{ $message }}</p>@enderror
</div>
@endif

@unless($quiz->is_anonymous)
<div>
    <label for="student_name" class="block text-sm font-medium text-slate-700 mb-1.5">
        Nom complet @unless($optional)<span class="text-red-500" aria-hidden="true">*</span>@endunless
    </label>
    <input type="text" name="student_name" id="student_name"
           @if(! $optional && ! $usesReferences) required @endif
           @if($disabled) disabled aria-disabled="true" @endif
           value="{{ old('student_name', $prepared['student_name'] ?? null) }}"
           class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm text-slate-900 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150">
    @error('student_name')<p class="mt-1.5 text-sm text-red-600" role="alert">{{ $message }}</p>@enderror
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
    <div>
        <label for="student_email" class="block text-sm font-medium text-slate-700 mb-1.5">Email</label>
        <input type="email" name="student_email" id="student_email"
               @if($disabled) disabled aria-disabled="true" @endif
               value="{{ old('student_email', $prepared['student_email'] ?? null) }}"
               class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm text-slate-900 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150">
        @error('student_email')<p class="mt-1.5 text-sm text-red-600" role="alert">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="student_major" class="block text-sm font-medium text-slate-700 mb-1.5">Filière</label>
        <input type="text" name="student_major" id="student_major"
               @if($disabled) disabled aria-disabled="true" @endif
               value="{{ old('student_major', $prepared['student_major'] ?? null) }}"
               class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm text-slate-900 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150">
    </div>
</div>
@endunless
