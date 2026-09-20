@extends('layouts.app')

@section('title', 'Correcteurs - ' . $quiz->title)

@section('content')
<div class="px-4 sm:px-0">
    <div class="mb-8">
        <a href="{{ route('admin.quizzes.results', $quiz) }}"
           class="inline-flex items-center text-sm font-medium text-slate-500 hover:text-brand-600 transition-colors duration-150">
            <svg class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
            Retour aux résultats
        </a>

        <h1 class="mt-3 text-2xl font-bold tracking-tight text-slate-900">Correcteurs</h1>
        <p class="mt-1 text-sm text-slate-500">
            {{ $quiz->title }} · {{ $pending }} copie(s) attendent une correction
        </p>
    </div>

    {{-- Création : un nom, un email, un délai. La référence et le lien sont
         engendrés par l'application, jamais choisis. --}}
    <div class="bg-white rounded-2xl shadow-card border border-slate-200/70 p-6 sm:p-8 mb-8">
        <h2 class="text-base font-semibold text-slate-900">Assigner à un correcteur</h2>
        <p class="mt-1 text-sm text-slate-500">
            Le correcteur recevra un lien personnel et une référence à dix caractères. Le lien seul
            n'ouvre aucune copie : transmettez la référence par un autre canal (téléphone, remise en main propre).
        </p>

        <form method="POST" action="{{ route('admin.quizzes.graders.store', $quiz) }}" class="mt-6 grid grid-cols-1 sm:grid-cols-12 gap-4">
            @csrf

            <div class="sm:col-span-5">
                <label for="grader-name" class="block text-sm font-medium text-slate-700">Nom du correcteur</label>
                <input id="grader-name" name="name" type="text" required maxlength="120"
                       value="{{ old('name') }}"
                       class="mt-1.5 w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm text-slate-900 placeholder-slate-400 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150">
                @error('name')<p class="mt-1.5 text-sm text-red-600" role="alert">{{ $message }}</p>@enderror
            </div>

            <div class="sm:col-span-4">
                <label for="grader-email" class="block text-sm font-medium text-slate-700">Adresse email</label>
                <input id="grader-email" name="email" type="email" required maxlength="190" spellcheck="false"
                       value="{{ old('email') }}"
                       class="mt-1.5 w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm text-slate-900 placeholder-slate-400 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150">
                @error('email')<p class="mt-1.5 text-sm text-red-600" role="alert">{{ $message }}</p>@enderror
            </div>

            <div class="sm:col-span-3">
                <label for="grader-days" class="block text-sm font-medium text-slate-700">Délai (jours)</label>
                <input id="grader-days" name="days" type="number" required
                       min="{{ $minDays }}" max="{{ $maxDays }}"
                       value="{{ old('days', $defaultDays) }}"
                       class="mt-1.5 w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm text-slate-900 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150">
                @error('days')<p class="mt-1.5 text-sm text-red-600" role="alert">{{ $message }}</p>@enderror
                <p class="mt-1 text-xs text-slate-500">À l'échéance, l'accès se ferme tout seul.</p>
            </div>

            <div class="sm:col-span-12">
                <button type="submit"
                        class="inline-flex items-center justify-center px-5 py-2.5 border border-transparent text-sm font-semibold rounded-xl text-white bg-brand-600 hover:bg-brand-700 transition-colors duration-150">
                    Assigner à un correcteur
                </button>
            </div>
        </form>
    </div>

    @if($graders->isEmpty())
    <div class="bg-white rounded-2xl shadow-card border border-slate-200/70 px-6 py-8">
        <p class="text-sm text-slate-500">Aucun correcteur pour cette évaluation pour l'instant.</p>
    </div>
    @else
    <div class="space-y-4">
        @foreach($graders as $grader)
        @php($expired = $grader->isExpired())
        @php($done = $progress[$grader->id] ?? 0)

        <div class="bg-white rounded-2xl shadow-card border border-slate-200/70 p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <h3 class="text-base font-semibold text-slate-900">{{ $grader->name }}</h3>
                    <p class="mt-0.5 text-sm text-slate-500 break-all">{{ $grader->email }}</p>

                    <p class="mt-2 text-xs">
                        @if($expired)
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full font-medium bg-amber-50 text-amber-700">Échéance dépassée — accès fermé</span>
                        @else
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full font-medium bg-emerald-50 text-emerald-700">
                            Accès ouvert @if($grader->expires_at) jusqu'au {{ $grader->expires_at->format('d/m/Y à H:i') }} @else sans échéance @endif
                        </span>
                        @endif

                        <span class="ml-2 text-slate-500">
                            {{ $done }} copie(s) portent sa trace sur cette évaluation
                        </span>

                        @if($grader->last_seen_at)
                        <span class="ml-2 text-slate-400">
                            · dernière activité {{ $grader->last_seen_at->format('d/m/Y à H:i') }}
                            @if($grader->last_ip) depuis {{ $grader->last_ip }} @endif
                        </span>
                        @else
                        <span class="ml-2 text-slate-400">· jamais connecté</span>
                        @endif
                    </p>
                </div>

                <div class="text-right">
                    <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Référence à lui transmettre</p>
                    <p class="mt-1 font-mono text-lg font-bold tracking-widest text-brand-700">{{ $grader->reference }}</p>
                </div>
            </div>

            {{-- Le lien et son QR code : le QR est calculé à partir du lien, donc
                 il suit automatiquement une régénération. --}}
            <div class="mt-5 flex flex-col sm:flex-row gap-5 sm:items-center">
                <img src="{{ $qrCodes[$grader->id] }}" width="140" height="140"
                     alt="QR code du lien de correction de {{ $grader->name }}"
                     class="shrink-0 rounded-xl border border-slate-200 bg-white p-1">

                <div class="min-w-0 flex-1">
                    <label for="grader-link-{{ $grader->id }}" class="block text-sm font-medium text-slate-700">Son lien de correction</label>
                    <input type="text" id="grader-link-{{ $grader->id }}" readonly value="{{ $grader->link() }}"
                           onfocus="this.select()"
                           class="mt-1.5 w-full px-4 py-2.5 border border-slate-300 rounded-xl text-xs text-slate-700 bg-slate-50 font-mono">

                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <button type="button" data-copy-target="#grader-link-{{ $grader->id }}"
                                onclick="copyField(this.dataset.copyTarget, this)"
                                class="inline-flex items-center justify-center px-3.5 py-2 border border-slate-300 text-xs font-semibold rounded-xl text-slate-700 hover:bg-slate-50 transition-colors duration-150">
                            <span data-copy-label>Copier</span>
                        </button>

                        <a href="{{ $grader->link() }}" target="_blank" rel="noopener"
                           class="inline-flex items-center justify-center px-3.5 py-2 border border-slate-300 text-xs font-semibold rounded-xl text-slate-700 hover:bg-slate-50 transition-colors duration-150">
                            Ouvrir
                        </a>

                        <a href="{{ route('admin.quizzes.graders.mission', [$quiz, $grader]) }}"
                           class="inline-flex items-center justify-center px-3.5 py-2 border border-slate-300 text-xs font-semibold rounded-xl text-slate-700 hover:bg-slate-50 transition-colors duration-150">
                            Fiche de mission (PDF)
                        </a>

                        <a href="{{ route('admin.quizzes.graders.mission', [$quiz, $grader, 'reference' => 1]) }}"
                           class="inline-flex items-center justify-center px-3.5 py-2 border border-slate-300 text-xs font-semibold rounded-xl text-slate-700 hover:bg-slate-50 transition-colors duration-150"
                           title="Pour une remise en main propre : la fiche porte alors aussi la référence.">
                            Fiche + référence
                        </a>
                    </div>

                    <p class="mt-2 text-xs text-slate-500">
                        Le QR code s'imprime et se scanne ; la fiche PDF le reprend en haute résolution.
                        Par défaut, elle ne porte pas la référence : une fiche qui contient le lien
                        <em>et</em> la clé ouvre tout à elle seule.
                    </p>
                </div>
            </div>

            {{-- Actions : régénérer, prolonger, suspendre, retirer. Chacune est un
                 formulaire à part, donc rien ne part par un simple clic. --}}
            <div class="mt-5 pt-5 border-t border-slate-100 flex flex-wrap items-end gap-4">
                <form method="POST" action="{{ route('admin.quizzes.graders.extend', [$quiz, $grader]) }}" class="flex items-end gap-2">
                    @csrf
                    @method('PATCH')
                    <div>
                        <label for="extend-{{ $grader->id }}" class="block text-xs font-medium text-slate-600">Prolonger de</label>
                        <input id="extend-{{ $grader->id }}" name="days" type="number" min="{{ $minDays }}" max="{{ $maxDays }}"
                               value="{{ $defaultDays }}"
                               class="mt-1 w-24 px-3 py-2 border border-slate-300 rounded-xl text-sm text-slate-900">
                    </div>
                    <button type="submit"
                            class="inline-flex items-center justify-center px-3.5 py-2 border border-slate-300 text-xs font-semibold rounded-xl text-slate-700 hover:bg-slate-50 transition-colors duration-150">
                        Prolonger
                    </button>
                </form>

                <form method="POST" action="{{ route('admin.quizzes.graders.link', [$quiz, $grader]) }}"
                      onsubmit="return confirm('Régénérer le lien ? L’ancien cessera de fonctionner immédiatement.');">
                    @csrf
                    <button type="submit"
                            class="inline-flex items-center justify-center px-3.5 py-2 border border-slate-300 text-xs font-semibold rounded-xl text-slate-700 hover:bg-slate-50 transition-colors duration-150">
                        ↻ Régénérer le lien
                    </button>
                </form>

                @unless($expired)
                <form method="POST" action="{{ route('admin.quizzes.graders.suspend', [$quiz, $grader]) }}"
                      onsubmit="return confirm('Suspendre maintenant l’accès de ce correcteur ?');">
                    @csrf
                    @method('PATCH')
                    <button type="submit"
                            class="inline-flex items-center justify-center px-3.5 py-2 border border-slate-300 text-xs font-semibold rounded-xl text-slate-700 hover:bg-slate-50 transition-colors duration-150">
                        Suspendre l'accès
                    </button>
                </form>
                @endunless

                <form method="POST" action="{{ route('admin.quizzes.graders.detach', [$quiz, $grader]) }}"
                      onsubmit="return confirm('Retirer cette évaluation de son périmètre ?');">
                    @csrf
                    @method('DELETE')
                    <button type="submit"
                            class="inline-flex items-center justify-center px-3.5 py-2 border border-red-200 text-xs font-semibold rounded-xl text-red-700 hover:bg-red-50 transition-colors duration-150">
                        Retirer de cette évaluation
                    </button>
                </form>
            </div>
        </div>
        @endforeach
    </div>

    <p class="mt-6 text-xs text-slate-500">
        Un correcteur n'a jamais de compte d'administration : son espace est séparé, et il n'existe
        aucune page de gestion depuis son lien. Ses notes gardent son nom en trace, même après
        suspension.
    </p>
    @endif
</div>
@endsection
