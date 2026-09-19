{{--
    Chronomètre et surveillance, communs à la question et à la confirmation.

    Le compte à rebours affiché n'est qu'un confort : la décision appartient au
    serveur, qui compare l'heure de soumission à expires_at. Le JavaScript ne
    peut donc pas allonger une épreuve.

    Ce que fait la surveillance : bloque copier-coller / clic droit / sélection,
    propose le plein écran, et journalise chaque sortie de fenêtre. Ce qu'elle ne
    peut pas faire, et ne prétend pas faire : interdire une capture d'écran ou
    l'ouverture d'un autre onglet.
--}}
<div id="quiz-guard"
     data-infraction-url="{{ route('quiz.infraction', $quiz->token) }}"
     data-submit-url="{{ route('quiz.submit', $quiz->token) }}"
     data-remaining="{{ $remaining }}"
     data-proctoring="{{ $quiz->quizUsesProctoring() ? '1' : '0' }}"
     data-warnings="{{ $attempt->infraction_count }}">

    <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
        <div class="flex items-center gap-2" role="timer" aria-label="Temps restant">
            <span class="inline-flex h-9 items-center gap-2 px-3 rounded-xl bg-slate-900 text-white font-semibold"
                  style="font-variant-numeric: tabular-nums">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span id="quiz-timer">{{ gmdate('i:s', $remaining) }}</span>
            </span>
            <span class="text-xs text-slate-500">Temps restant (chronométré par le serveur)</span>
        </div>

        @if($quiz->quizUsesProctoring())
        <p class="inline-flex items-center gap-2 text-xs text-amber-700 bg-amber-50 border border-amber-200/70 px-3 py-2 rounded-xl">
            <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 9v2m0 4h.01M5 19h14a2 2 0 001.84-2.75L13.74 4a2 2 0 00-3.5 0L3.16 16.25A2 2 0 005 19z" />
            </svg>
            <span>
                Fenêtre surveillée —
                <span id="quiz-warnings" style="font-variant-numeric: tabular-nums">{{ $attempt->infraction_count }}</span>
                sortie(s) de fenêtre enregistrée(s)
            </span>
        </p>
        @endif
    </div>

    {{-- Soumission automatique à l'expiration du temps --}}
    <form method="POST" action="{{ route('quiz.submit', $quiz->token) }}" id="quiz-expire-form" class="hidden">
        @csrf
    </form>
</div>

@push('scripts')
<script>
(function () {
    const guard = document.getElementById('quiz-guard');
    if (!guard) { return; }

    const remaining = parseInt(guard.dataset.remaining, 10) || 0;
    const timer = document.getElementById('quiz-timer');
    const warnings = document.getElementById('quiz-warnings');
    const proctoring = guard.dataset.proctoring === '1';
    const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    // --- Chronomètre -------------------------------------------------------
    let left = remaining;

    function paint() {
        const minutes = String(Math.floor(left / 60)).padStart(2, '0');
        const seconds = String(left % 60).padStart(2, '0');
        if (timer) { timer.textContent = minutes + ':' + seconds; }
    }

    paint();

    const tick = setInterval(function () {
        left -= 1;
        paint();

        if (left <= 0) {
            clearInterval(tick);
            // Le serveur refuse toute réponse arrivée après l'échéance : cette
            // soumission clôt l'épreuve proprement plutôt que de laisser la
            // page ouverte sur un temps écoulé.
            document.getElementById('quiz-expire-form').submit();
        }
    }, 1000);

    // --- Soumission à la fermeture de l'onglet ----------------------------
    window.addEventListener('beforeunload', function (event) {
        if (left > 0) {
            event.preventDefault();
            event.returnValue = '';
        }
    });

    if (!proctoring) { return; }

    // --- Copier-coller, clic droit, sélection -----------------------------
    ['copy', 'cut', 'paste', 'contextmenu', 'dragstart'].forEach(function (eventName) {
        document.addEventListener(eventName, function (event) {
            event.preventDefault();
            report('copy_attempt', eventName);
        });
    });

    document.addEventListener('selectstart', function (event) {
        // La saisie dans un champ de réponse doit rester possible.
        if (!event.target.closest('input, textarea')) {
            event.preventDefault();
        }
    });

    // --- Sorties de fenêtre -----------------------------------------------
    let focused = true;

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            focused = false;
            report('tab_hidden');
        } else {
            focused = true;
        }
    });

    window.addEventListener('blur', function () {
        if (!focused) { return; }
        focused = false;
        report('window_blur');
    });

    window.addEventListener('focus', function () { focused = true; });

    function report(type, detail) {
        fetch(guard.dataset.infractionUrl, {
            method: 'POST',
            keepalive: true,
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
                'Accept': 'application/json'
            },
            body: JSON.stringify({ type: type, detail: detail || null })
        }).then(function (response) {
            return response.ok ? response.json() : null;
        }).then(function (payload) {
            if (payload && payload.recorded && warnings) {
                warnings.textContent = payload.count;
            }
        }).catch(function () {
            // Une trace perdue ne doit jamais interrompre l'épreuve.
        });
    }

    // --- Plein écran (nécessite un geste de l'utilisateur) ----------------
    document.addEventListener('click', function once() {
        document.removeEventListener('click', once);

        const element = document.documentElement;
        if (element.requestFullscreen && !document.fullscreenElement) {
            element.requestFullscreen().catch(function () {
                // Refusé par le navigateur : on continue, ce n'est pas bloquant.
            });
        }
    }, { once: true });
})();
</script>
@endpush
