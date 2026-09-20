{{--
    Chronomètre et surveillance, communs à la question et à la confirmation.

    Le compte à rebours affiché n'est qu'un confort : la décision appartient au
    serveur, qui compare l'heure de soumission à expires_at. Le JavaScript ne
    peut donc pas allonger une épreuve.

    Ce que fait la surveillance : bloque copier-coller / clic droit / sélection,
    propose le plein écran par un bouton dédié, et journalise les sorties
    réellement subies. Ce qu'elle ne peut pas faire, et ne prétend pas faire :
    interdire une capture d'écran ou l'ouverture d'un autre onglet.

    Ce qu'elle ne fait plus, volontairement : compter les transitions de plein
    écran ni les dialogues du navigateur comme des sorties, et empêcher une
    validation par un garde-fou de sortie de page. Le plein écran n'est jamais
    perdu entre deux questions, la page ne se rechargeant plus.
--}}
<div id="quiz-guard"
     data-infraction-url="{{ route('quiz.infraction', $quiz->token) }}"
     data-submit-url="{{ route('quiz.submit', $quiz->token) }}"
     data-remaining="{{ $remaining }}"
     data-proctoring="{{ $quiz->quizUsesProctoring() ? '1' : '0' }}"
     data-fullscreen-preferred="{{ ($fullscreenPreferred ?? false) ? '1' : '0' }}"
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

        <div class="flex flex-wrap items-center gap-2">
            {{-- Le plein écran se demande par ce geste, et par lui seul : un clic
                 de réponse ne doit jamais servir à autre chose qu'à répondre. --}}
            <button type="button" id="quiz-fullscreen-toggle" hidden
                    class="inline-flex items-center gap-2 h-9 px-3 rounded-xl border border-slate-300 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition-colors duration-150">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 8V4h4M20 8V4h-4M4 16v4h4m12-4v4h-4" />
                </svg>
                <span id="quiz-fullscreen-label">{{ ($fullscreenPreferred ?? false) ? 'Passer en plein écran (recommandé)' : 'Passer en plein écran' }}</span>
            </button>

            @if($quiz->quizUsesProctoring())
            <p class="inline-flex items-center gap-2 text-xs text-amber-700 bg-amber-50 border border-amber-200/70 px-3 py-2 rounded-xl">
                <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 9v2m0 4h.01M5 19h14a2 2 0 001.84-2.75L13.74 4a2 2 0 00-3.5 0L3.16 16.25A2 2 0 005 19z" />
                </svg>
                <span>
                    Fenêtre surveillée —
                    <span id="quiz-warnings" style="font-variant-numeric: tabular-nums">{{ $attempt->infraction_count }}</span>
                    sortie(s) enregistrée(s)
                </span>
            </p>
            @endif
        </div>
    </div>

    {{-- Ce que l'étudiant doit savoir sans qu'on le lui cache : ce qui est
         compté, et ce qui ne l'est pas. --}}
    @if($quiz->quizUsesProctoring())
    <p class="mb-4 text-xs text-slate-500">
        Sont comptées : le passage à un autre onglet ou à une autre application,
        et la sortie du plein écran. Valider une réponse n'est jamais compté.
    </p>
    @endif

    <div id="quiz-infraction-notice" hidden role="status" aria-live="polite"
         class="mb-4 rounded-xl border border-amber-200/70 bg-amber-50 px-4 py-2.5 text-sm text-amber-800"></div>

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
    const notice = document.getElementById('quiz-infraction-notice');
    const proctoring = guard.dataset.proctoring === '1';
    const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    let noticeTimer = null;
    let submitting = false;

    function showNotice(message) {
        if (!notice) { return; }

        notice.textContent = message;
        notice.hidden = false;

        clearTimeout(noticeTimer);
        noticeTimer = setTimeout(function () { notice.hidden = true; }, 6000);
    }

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
            submitting = true;
            document.getElementById('quiz-expire-form').submit();
        }
    }, 1000);

    // Exposé à la page de question : la réponse JSON recale le chronomètre sur
    // celui du serveur et remplace la carte sans recharger la page.
    window.QuizGuard = {
        refresh: function (seconds) {
            if (typeof seconds === 'number' && seconds >= 0) { left = seconds; }
            paint();
        },
        notify: showNotice
    };

    // --- Sortie de page ----------------------------------------------------
    // Un seul cas justifie d'avertir : quitter la page avec un texte rédigé,
    // tapé mais non validé. Auparavant, l'avertissement se déclenchait pendant
    // toute l'épreuve — donc à chaque validation — et le navigateur abandonnait
    // la navigation tant que le candidat n'avait pas confirmé un dialogue que
    // le plein écran rendait presque invisible.
    window.addEventListener('beforeunload', function (event) {
        if (submitting) { return; }

        const field = document.querySelector('textarea[name="answer_text"]');

        if (field === null || field.value.trim() === '') { return; }

        event.preventDefault();
        event.returnValue = '';
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

    // --- Sorties réellement subies ----------------------------------------
    // Une seule entrée par sortie : deux signaux pour un même événement (ou un
    // double déclenchement du navigateur) ne doivent pas peser double.
    const DEDUPE_MS = 2000;
    const lastReport = {};

    // Pendant une transition de plein écran, certaines plateformes signalent une
    // perte de visibilité qui n'en est pas une. On écoute, on ne compte pas.
    let mutedUntil = 0;

    // Sortie de plein écran demandée par notre bouton : ce n'est pas une sortie
    // subie, et le plein écran ne doit pas compter les gestes de l'application.
    let intentionalExit = false;

    function mute(ms) { mutedUntil = Date.now() + ms; }

    // `visibilitychange` couvre le changement d'onglet et le passage à une autre
    // application. Un navigateur peut en signaler plusieurs pour une même
    // sortie ; le dédoublonnage s'en charge.
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) { report('tab_hidden'); }
    });

    // Le seul signal fiable d'une sortie de plein écran. Il n'était pas écouté
    // auparavant : le serveur recevait `fullscreen_exit`… jamais.
    document.addEventListener('fullscreenchange', function () {
        const inFullscreen = document.fullscreenElement !== null;

        paintFullscreen(inFullscreen);

        if (inFullscreen) {
            intentionalExit = false;
            return;
        }

        if (intentionalExit) {
            intentionalExit = false;
            return;
        }

        report('fullscreen_exit');
    });

    function report(type, detail) {
        const now = Date.now();

        if (now < mutedUntil) { return; }
        if (lastReport[type] && now - lastReport[type] < DEDUPE_MS) { return; }

        lastReport[type] = now;

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
            // `recorded` est faux quand le serveur a reconnu un doublon : le
            // compteur affiché reste alors celui du serveur, jamais un total
            // gonflé côté navigateur.
            if (!payload || !payload.recorded) { return; }

            if (warnings) { warnings.textContent = payload.count; }
            if (payload.message) { showNotice(payload.message); }
        }).catch(function () {
            // Une trace perdue ne doit jamais interrompre l'épreuve.
        });
    }

    // --- Plein écran (nécessite un geste de l'utilisateur) ----------------
    const toggle = document.getElementById('quiz-fullscreen-toggle');
    const toggleLabel = document.getElementById('quiz-fullscreen-label');

    // La préférence vient du serveur : elle survit donc à un rechargement ou à
    // un changement de page, ce qu'un stockage du navigateur ne garantit pas.
    const preferred = guard.dataset.fullscreenPreferred === '1';

    function paintFullscreen(inFullscreen) {
        if (!toggle || !toggleLabel) { return; }

        toggleLabel.textContent = inFullscreen
            ? 'Quitter le plein écran'
            : (preferred ? 'Passer en plein écran (recommandé)' : 'Passer en plein écran');
    }

    if (toggle && document.documentElement.requestFullscreen) {
        toggle.hidden = false;
        paintFullscreen(document.fullscreenElement !== null);

        toggle.addEventListener('click', function () {
            if (document.fullscreenElement) {
                intentionalExit = true;
                mute(1500);

                document.exitFullscreen().catch(function () {
                    intentionalExit = false;
                    mute(0);
                });

                return;
            }

            mute(1500);

            document.documentElement.requestFullscreen().catch(function () {
                // Refusé par le navigateur : on continue, ce n'est pas bloquant.
                mute(0);
            });
        });
    }
})();
</script>
@endpush
