@extends('layouts.app')

@section('title', $quiz->title)

@section('content')
<div class="px-4 sm:px-0 max-w-2xl mx-auto">
    <div class="bg-white rounded-2xl shadow-card border border-slate-200/70 p-6 sm:p-8">
        @if($preview ?? false)
        {{-- Aperçu enseignant : la page qu'il a sous les yeux est celle de
             l'étudiant ; ce bandeau est la seule chose que l'étudiant ne verra
             jamais. --}}
        <div class="mb-6 rounded-xl bg-slate-900 px-4 py-3 text-sm text-slate-100" role="status">
            <p class="font-semibold">Aperçu de la page d'attente</p>
            <p class="mt-1 text-slate-300">
                Exactement ce que voit l'étudiant avant l'ouverture. Le décompte tourne, mais rien
                ne sonne et la page ne se recharge pas ; les champs sont inactifs, et aucune
                participation n'est créée.
                @if($previewSimulated ?? false)
                Aucune ouverture n'est programmée : le décompte ci-dessous est seulement simulé,
                pour montrer la carte.
                @endif
            </p>
        </div>
        @endif

        <h1 class="text-2xl font-bold tracking-tight text-slate-900">{{ $quiz->title }}</h1>

        @if($quiz->description)
        <p class="mt-3 text-sm text-slate-600 whitespace-pre-line">{{ $quiz->description }}</p>
        @endif

        <dl class="grid grid-cols-2 sm:grid-cols-3 gap-3 mt-6">
            <div class="rounded-xl bg-slate-50 px-4 py-3">
                <dt class="text-xs text-slate-500">Durée</dt>
                <dd class="text-lg font-bold text-slate-900" style="font-variant-numeric: tabular-nums">{{ $quiz->quizDurationMinutes() }} min</dd>
            </div>
            <div class="rounded-xl bg-slate-50 px-4 py-3">
                <dt class="text-xs text-slate-500">Questions</dt>
                <dd class="text-lg font-bold text-slate-900" style="font-variant-numeric: tabular-nums">{{ $questionsCount }}</dd>
            </div>
            <div class="rounded-xl bg-slate-50 px-4 py-3">
                <dt class="text-xs text-slate-500">Total</dt>
                <dd class="text-lg font-bold text-slate-900" style="font-variant-numeric: tabular-nums">{{ $maxScore }} pt</dd>
            </div>
        </dl>

        <ul class="mt-6 space-y-2 text-sm text-slate-600">
            <li class="flex items-start gap-2">
                <span class="text-brand-600" aria-hidden="true">•</span>
                Une seule question s'affiche à la fois. Une fois validée, elle n'est plus modifiable.
            </li>
            <li class="flex items-start gap-2">
                <span class="text-brand-600" aria-hidden="true">•</span>
                Le temps est décompté par le serveur dès que vous commencez : fermer la page ne l'arrête pas.
            </li>
            @if($quiz->quizUsesProctoring())
            <li class="flex items-start gap-2">
                <span class="text-amber-600" aria-hidden="true">•</span>
                La fenêtre est surveillée : le copier-coller est bloqué, et sont enregistrés le passage à un
                autre onglet et la sortie du plein écran. Valider une réponse n'est jamais compté.
            </li>
            @endif
            @if($quiz->quizShowsScore())
            <li class="flex items-start gap-2">
                <span class="text-brand-600" aria-hidden="true">•</span>
                Votre note et la correction s'affichent dès que vous rendez votre copie.
            </li>
            @endif
        </ul>

        @if($finishedAttempt ?? null)
        {{-- Salle informatique : la copie précédente ne doit pas confisquer le
             poste. Elle reste accessible par ce lien, et le formulaire
             ci-dessous est celui du candidat suivant. --}}
        <div class="mt-6 rounded-xl bg-slate-50 border border-slate-200 px-4 py-3">
            <p class="text-sm text-slate-700">
                Une copie a déjà été rendue sur cet appareil
                (référence <span class="font-mono font-semibold">{{ $finishedAttempt->reference }}</span>).
                <a href="{{ route('quiz.result', $quiz->token) }}" class="font-semibold text-brand-700 hover:text-brand-800">Voir ce résultat</a>
            </p>
            <p class="mt-2 text-xs text-slate-500">
                Vous êtes l'étudiant suivant ? Remplissez le formulaire ci-dessous : vous obtiendrez
                votre propre copie, avec votre propre référence.
            </p>
        </div>
        @endif

        {{-- Aperçu enseignant : on montre toujours la salle d'attente, même sur une
             épreuve déjà ouverte — c'est cette carte-là qu'il vient relire. --}}
        @if(($preview ?? false) || ! $quiz->quizIsOpen())
        @unless($preview ?? false)
        <div class="mt-6 rounded-xl bg-slate-100 border border-slate-200 px-4 py-3 text-sm text-slate-700" role="alert">
            Cette évaluation n'est pas ouverte actuellement.
        </div>
        @endunless

        @if($opensInSeconds !== null && $opensAt !== null)
        {{-- Ouverture à venir : c'est le seul cas de fermeture où attendre a un
             sens, et où il y a une heure à annoncer. Les secondes viennent du
             serveur — l'horloge du poste ne décide de rien — et le script
             ci-dessous recharge la page à l'échéance, pour que le formulaire
             d'accès apparaisse sans intervention du candidat. --}}
        @php
            // Valeur de départ, rendue par le serveur : le candidat ne voit pas
            // clignoter un « --:--:-- » le temps que le script démarre. Au-delà
            // de vingt-quatre heures, on compte en jours (« 3 j 04:12:07 ») —
            // un total d'heures empilées (« 72:12:07 ») ne se lit plus. Le
            // script reprend exactement la même règle : voir en dessous.
            $openDays = intdiv($opensInSeconds, 86400);
            $openClock = sprintf(
                '%02d:%02d:%02d',
                intdiv($opensInSeconds % 86400, 3600),
                intdiv($opensInSeconds % 3600, 60),
                $opensInSeconds % 60
            );
            $openLabel = $openDays > 0 ? $openDays.' j '.$openClock : $openClock;

            // Le même temps, en toutes lettres : c'est lui que lit un lecteur
            // d'écran — « 3 j 04:12:07 » se prononce « 3 j, 4, 12, 07 », ce qui ne
            // dit rien à personne. Deux unités suffisent (« 1 heure 15 minutes ») :
            // au-delà, l'annonce se perd dans les détails, et les minutes d'une
            // attente de trois jours n'apprennent rien à personne.
            //
            // Le script reprend exactement la même règle et le même tour de phrase
            // (voir ANNOUNCE_STEPS, plus bas) : les paliers qu'il annonce ensuite
            // doivent se lire comme cette première phrase.
            $openWords = [];
            $openRest = $opensInSeconds;

            foreach ([[86400, 'jour', 'jours'], [3600, 'heure', 'heures'], [60, 'minute', 'minutes'], [1, 'seconde', 'secondes']] as [$openSize, $openSingular, $openPlural]) {
                if (count($openWords) === 2) {
                    break;
                }

                $openCount = intdiv($openRest, $openSize);
                $openRest -= $openCount * $openSize;

                if ($openCount > 0) {
                    $openWords[] = $openCount.' '.($openCount > 1 ? $openPlural : $openSingular);
                } elseif ($openWords !== []) {
                    break;
                }
            }

            // Moins d'une seconde : la page va se recharger d'elle-même, et le dire
            // ainsi vaut mieux que « 0 seconde ».
            $openSpoken = $opensInSeconds < 1 ? "moins d'une seconde" : implode(' ', $openWords);

            // L'aperçu simulé ne fait attendre personne : l'annonce le dit, pour
            // qu'un lecteur d'écran ne prenne pas la démonstration pour une
            // échéance réelle.
            $openAnnouncement = ($previewSimulated ?? false)
                ? 'Aperçu : le décompte ci-dessous est simulé.'
                : 'Temps restant avant l\'ouverture : '.$openSpoken.'.';
        @endphp
        <div class="mt-4 rounded-xl bg-brand-50/70 border border-brand-200/70 px-4 py-6 text-center"
             id="quiz-open-countdown"
             data-seconds="{{ $opensInSeconds }}"
             data-sound="{{ $playsOpeningSound ? '1' : '0' }}"
             @if($preview ?? false) data-preview="1" @else data-notice-key="quiz_opened.{{ $quiz->id }}" @endif
             role="group" aria-label="Temps restant avant l'ouverture de l'épreuve">
            <p class="text-xs font-semibold uppercase tracking-wider text-brand-700">L'épreuve n'a pas encore commencé</p>

            {{-- Le compteur change chaque seconde : masqué aux lecteurs d'écran,
                 qui l'annonceraient sans fin — et « 3 j 04:12:07 » se prononce
                 « 3 j, 4, 12, 07 ». Sa version parlée est juste en dessous, et
                 c'est elle seule qui sera mise à jour. --}}
            <p class="mt-3 text-4xl sm:text-5xl font-bold text-slate-900" id="quiz-open-clock"
               aria-hidden="true"
               style="font-variant-numeric: tabular-nums">{{ $openLabel }}</p>

            {{-- La région « live » du décompte : elle porte, dès l'affichage, le
                 temps restant en toutes lettres — la page est donc lisible sans
                 JavaScript — et le script ne la remplace qu'aux paliers utiles.
                 C'est la seule chose du décompte qui soit annoncée. --}}
            <p id="quiz-open-announce" class="sr-only" role="status" aria-live="polite">{{ $openAnnouncement }}</p>

            <p class="mt-4 text-sm text-slate-700">
                Ouverture le
                <span class="font-semibold" style="font-variant-numeric: tabular-nums">{{ $opensAt->format('d/m/Y à H:i') }}</span>
            </p>

            {{-- Sur le fond teinté de la carte (brand-50 à 70 %), « slate-500 » ne
                 tient que 4,48:1 : sous le minimum. « slate-600 » y monte à
                 7,1:1, et reste un texte secondaire. --}}
            <p class="mt-2 text-xs text-slate-600">
                Vous pouvez laisser cette page ouverte : elle se rafraîchit d'elle-même à l'heure dite,
                et le formulaire d'accès s'affichera automatiquement.
            </p>

            {{-- Un navigateur ne laisse jouer un son qu'après un geste de
                 l'utilisateur : la page le dit, et confirme une fois le signal
                 armé — plutôt que de promettre un son qui ne viendrait pas.
                 Absente quand l'épreuve a retiré le signal : inviter à toucher la
                 page pour un son qui ne viendra pas serait pire que rien. --}}
            @if($playsOpeningSound)
            <p class="mt-3 text-xs text-slate-600" id="quiz-sound-state" aria-live="polite"
               data-active="Signal sonore activé : il retentira à l'ouverture."
               data-unavailable="Signal sonore indisponible sur ce navigateur."
               data-preview-note="Aperçu : le signal sonore ne se déclenche pas ici.">
                Cliquez ou touchez cette page pour être prévenu par un signal sonore à l'ouverture.
            </p>
            @endif
        </div>

        {{-- Salle d'attente : ce que l'étudiant saisit ici est retenu en session
             (voir prepare()), et le formulaire d'accès le retrouve pré-rempli à
             l'ouverture. Rien n'est enregistré côté épreuve : aucune copie n'est
             créée tant que l'heure n'a pas sonné. En aperçu, la même carte est
             montrée avec des champs inertes. --}}
        <form method="POST"
              @unless($preview ?? false) action="{{ route('quiz.prepare', $quiz->token) }}" @endunless
              @if($preview ?? false) onsubmit="return false" @endif
              class="mt-4 rounded-xl bg-white border border-slate-200 px-4 py-5 sm:px-5 space-y-4">
            @csrf

            <div>
                <h2 class="text-sm font-semibold text-slate-900">Préparez votre entrée</h2>
                <p class="mt-1 text-xs text-slate-500">
                    Facultatif. Vos informations sont retenues sur cette page : à l'ouverture, le
                    formulaire d'accès s'affichera déjà rempli, et vous n'aurez rien à retaper au
                    moment où le chronomètre démarre.
                </p>
            </div>

            @include('student.quiz._access_fields', [
                'optional' => true,
                'disabled' => $preview ?? false,
                'prepared' => $prepared ?? [],
            ])

            <button type="submit" @disabled($preview ?? false)
                    class="w-full inline-flex items-center justify-center px-5 py-2.5 border border-slate-300 text-sm font-semibold rounded-xl text-slate-700 hover:bg-slate-50 transition-colors duration-150 disabled:opacity-50 disabled:cursor-not-allowed">
                Retenir mes informations
            </button>
        </form>
        @endif
        @else
        {{-- Ouverture : c'est cette page-ci qui l'annonce. Le signal sonore, lui,
             a retenti juste avant le rechargement — un son ne survit pas plus
             qu'un titre à un changement de page. Le bandeau est rendu masqué : il
             ne s'affiche que si l'étudiant vient réellement d'attendre. --}}
        <div id="quiz-opened-notice" data-notice-key="quiz_opened.{{ $quiz->id }}" hidden
             class="mt-6 rounded-xl bg-emerald-50 border border-emerald-200/70 px-4 py-3 text-sm text-emerald-800" role="status">
            <p class="font-semibold">L'épreuve vient d'ouvrir</p>
            <p class="mt-1">Vous pouvez commencer maintenant.</p>
        </div>

        <form method="POST" action="{{ route('quiz.begin', $quiz->token) }}" class="mt-8 space-y-5">
            @csrf

            {{-- Les champs sont partagés avec la salle d'attente : ici, ils sont
                 obligatoires selon les mêmes règles qu'avant, et ils portent ce
                 que l'attente a retenu (voir $prepared). --}}
            @include('student.quiz._access_fields', [
                'prepared' => $prepared ?? [],
            ])

            @if($quiz->quizUsesProctoring())
            {{-- Le plein écran exige un geste de l'utilisateur, et la navigation en
                 fait tomber un : le clic ci-dessous ne peut donc pas le transporter
                 jusqu'à l'épreuve. Le choix est mémorisé côté serveur, et c'est la
                 page de question qui le rétablit à la première action du candidat
                 (voir _proctoring). --}}
            <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                <label for="fullscreen-choice" class="flex items-start gap-3 cursor-pointer">
                    <input type="checkbox" name="fullscreen" id="fullscreen-choice" value="1" checked
                           class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus-visible:ring-2 focus-visible:ring-brand-500/40">
                    <span class="text-sm text-slate-700">
                        <span class="font-semibold">Passer l'épreuve en plein écran</span> (recommandé)
                        <span class="mt-0.5 block text-xs text-slate-500">
                            Le navigateur n'accorde le plein écran qu'à la première action de
                            l'étudiant : l'épreuve s'ouvrira donc en plein écran dès votre premier
                            clic. Vous pourrez en sortir à tout moment, et cela n'empêche jamais de
                            valider une réponse.
                        </span>
                    </span>
                </label>
            </div>
            @endif

            @if($questionsCount === 0)
            <div class="rounded-xl bg-amber-50 border border-amber-200/70 px-4 py-3 text-sm text-amber-800" role="alert">
                Cette évaluation ne contient encore aucune question.
            </div>
            @endif

            <button type="submit" @disabled($questionsCount === 0)
                    class="w-full inline-flex items-center justify-center px-5 py-3 border border-transparent text-sm font-semibold rounded-xl text-white gradient-bg hover:opacity-95 transition-all duration-150 disabled:opacity-50 disabled:cursor-not-allowed">
                Commencer l'évaluation
            </button>
            <p class="text-xs text-slate-500 text-center">Le chronomètre démarre dès que vous cliquez.</p>
        </form>
        @endif
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    const notice = document.getElementById('quiz-opened-notice');
    const box = document.getElementById('quiz-open-countdown');
    const clock = document.getElementById('quiz-open-clock');
    const hint = document.getElementById('quiz-sound-state');
    const spokenRegion = document.getElementById('quiz-open-announce');

    // Aperçu enseignant : la page est la vraie, seul le comportement change. Le
    // drapeau vient du serveur, jamais de l'adresse — rien dans l'URL ne permet
    // à un étudiant de se placer dans ce mode.
    const preview = box !== null && box.dataset.preview === '1';

    // Le signal sonore est un réglage d'évaluation : quand l'épreuve l'a retiré,
    // la page ne l'annonce pas et ne l'attend pas. Le rechargement de l'heure
    // dite, lui, garde exactement le même chemin — c'est la seule chose qui
    // change, et elle ne change que le son.
    const sound = box !== null && box.dataset.sound === '1';

    // Trace laissée par la page d'attente, propre à l'évaluation : deux épreuves
    // ouvertes dans le même navigateur ne se réveillent pas l'une l'autre.
    const keyHolder = box || notice;
    const noticeKey = keyHolder ? keyHolder.dataset.noticeKey : null;

    // --- Le titre qui clignote ------------------------------------------------
    // Il ne clignote que tant que l'onglet n'est pas regardé : c'est là qu'il
    // sert, et le laisser clignoter sous les yeux de l'étudiant pendant l'épreuve
    // serait insupportable.
    const BASE_TITLE = document.title;

    let announced = false;
    let blinkTimer = null;

    function startBlink() {
        if (blinkTimer !== null) { return; }

        let on = false;

        blinkTimer = window.setInterval(function () {
            on = !on;
            document.title = on ? '\u{1F514} ' + BASE_TITLE : BASE_TITLE;
        }, 1200);
    }

    function stopBlink() {
        if (blinkTimer === null) { return; }

        window.clearInterval(blinkTimer);
        blinkTimer = null;
        document.title = BASE_TITLE;
    }

    function syncBlink() {
        if (!announced) { return; }

        if (document.hidden) { startBlink(); } else { stopBlink(); }
    }

    // --- 1. La page qui vient de s'ouvrir l'annonce ---------------------------
    // Un titre ne survit pas à un rechargement : le clignotement doit donc naître
    // ici, sur la page ouverte, et pas sur celle qui attendait.
    function announce(element) {
        announced = true;
        element.hidden = false;

        syncBlink();
    }

    if (notice && noticeKey) {
        let trace = null;

        try {
            trace = sessionStorage.getItem(noticeKey);
            sessionStorage.removeItem(noticeKey);
        } catch (error) {
            // Stockage refusé (navigation privée) : l'étudiant voit simplement la
            // page ouverte, sans le rappel.
        }

        if (trace !== null) { announce(notice); }
    }

    document.addEventListener('visibilitychange', syncBlink);
    window.addEventListener('focus', stopBlink);
    document.addEventListener('pointerdown', stopBlink);
    document.addEventListener('keydown', stopBlink);

    if (!box || !clock) { return; }

    const seconds = parseInt(box.dataset.seconds, 10);

    if (!Number.isFinite(seconds) || seconds < 0) { return; }

    // Échéance absolue, et non un simple décrément seconde par seconde : un
    // onglet en arrière-plan (ou un poste mis en veille) voit ses minuteries
    // ralenties, et le compteur prendrait alors du retard sur l'heure réelle. En
    // repartant de l'horloge locale à chaque affichage, il se recale tout seul —
    // la durée à attendre, elle, reste celle annoncée par le serveur.
    const deadline = Date.now() + seconds * 1000;

    let reloaded = false;

    // Même règle que la valeur de départ rendue par le serveur : sous
    // vingt-quatre heures, un simple HH:MM:SS ; au-delà, les jours s'affichent,
    // parce que des heures qui s'empilent (« 72:12:07 ») ne se lisent plus.
    function label(left) {
        const days = Math.floor(left / 86400);
        const hours = String(Math.floor((left % 86400) / 3600)).padStart(2, '0');
        const minutes = String(Math.floor((left % 3600) / 60)).padStart(2, '0');
        const secs = String(left % 60).padStart(2, '0');

        const time = hours + ':' + minutes + ':' + secs;

        return days > 0 ? days + ' j ' + time : time;
    }

    function paint() {
        const left = Math.max(0, Math.round((deadline - Date.now()) / 1000));

        clock.textContent = label(left);

        return left;
    }

    // --- Ce que le décompte dit à un lecteur d'écran --------------------------
    // Le compteur visible change chaque seconde. Tant qu'il vivait dans une région
    // « live », un lecteur d'écran annonçait donc l'heure toutes les secondes,
    // sans fin et sans rien apprendre à personne. On annonce à la place aux seuls
    // moments qui servent, et en toutes lettres.
    //
    // La région « live » porte déjà la phrase calculée par le serveur (voir le
    // @php de la vue) : la page est lisible sans JavaScript, et les paliers
    // reprennent exactement le même tour de phrase.
    const ANNOUNCE_STEPS = [3600, 1800, 600, 300, 60, 30, 10];

    // Paliers encore à venir : ceux qui sont sous le temps restant à l'affichage.
    // Ceux déjà franchis avant l'arrivée sur la page sont écartés — annoncer
    // « 30 secondes » alors qu'il en reste huit serait faux.
    const steps = ANNOUNCE_STEPS.filter(function (step) { return step < seconds; });

    const UNITS = [
        [86400, 'jour', 'jours'],
        [3600, 'heure', 'heures'],
        [60, 'minute', 'minutes'],
        [1, 'seconde', 'secondes']
    ];

    // Même règle que le serveur : deux unités au plus, et jamais de zéro de queue
    // — « 1 heure 15 minutes » se dit, « 1 heure 15 minutes 0 seconde » ne se dit
    // pas.
    function spelled(left) {
        const parts = [];
        let rest = left;

        for (let index = 0; index < UNITS.length && parts.length < 2; index++) {
            const count = Math.floor(rest / UNITS[index][0]);

            rest -= count * UNITS[index][0];

            if (count > 0) {
                parts.push(count + ' ' + (count > 1 ? UNITS[index][2] : UNITS[index][1]));
            } else if (parts.length > 0) {
                break;
            }
        }

        return parts.join(' ');
    }

    function speak(left) {
        // Aperçu enseignant : personne n'attend, il n'y a rien à annoncer.
        if (preview || spokenRegion === null || left <= 0) { return; }

        // Un onglet laissé de côté voit ses minuteries ralenties : plusieurs
        // paliers peuvent être franchis d'un coup. On annonce alors le plus proche
        // — c'est lui qui compte — et jamais la file entière.
        let reached = null;

        while (steps.length > 0 && left <= steps[0]) {
            reached = steps.shift();
        }

        if (reached !== null) {
            spokenRegion.textContent = 'Temps restant avant l\'ouverture : ' + spelled(reached) + '.';
        }
    }

    // --- 2. Le signal sonore --------------------------------------------------
    // Le son est fabriqué par le navigateur — deux notes douces — plutôt que
    // servi depuis un fichier : moins d'une seconde de son ne mérite pas un
    // fichier à héberger, et il n'y a donc rien à télécharger pour l'entendre.
    //
    // Un navigateur ne laisse jouer un son qu'après un geste de l'utilisateur :
    // d'où l'indice affiché dans la carte, qui invite à toucher la page.
    let audio = null;
    let armed = false;

    function arm() {
        if (armed) { return; }

        armed = true;

        try {
            const Ctx = window.AudioContext || window.webkitAudioContext;

            audio = Ctx ? new Ctx() : null;

            // Un contexte créé pendant un geste naît débloqué ; certains
            // navigateurs le laissent malgré tout « suspendu ».
            if (audio && typeof audio.resume === 'function') { audio.resume(); }
        } catch (error) {
            audio = null;
        }

        if (hint) {
            hint.textContent = audio === null ? hint.dataset.unavailable : hint.dataset.active;
        }
    }

    // Deux notes douces, avec une enveloppe qui évite tout clic.
    const NOTES = [[660, 0, 0.28], [880, 0.3, 0.42]];

    function beep() {
        if (audio === null) { return false; }

        try {
            const start = audio.currentTime;

            NOTES.forEach(function (note) {
                const frequency = note[0];
                const offset = note[1];
                const duration = note[2];

                const oscillator = audio.createOscillator();
                const gain = audio.createGain();

                oscillator.type = 'sine';
                oscillator.frequency.value = frequency;

                gain.gain.setValueAtTime(0.0001, start + offset);
                gain.gain.exponentialRampToValueAtTime(0.08, start + offset + 0.04);
                gain.gain.exponentialRampToValueAtTime(0.0001, start + offset + duration);

                oscillator.connect(gain);
                gain.connect(audio.destination);

                oscillator.start(start + offset);
                oscillator.stop(start + offset + duration + 0.02);
            });

            return true;
        } catch (error) {
            return false;
        }
    }

    if (preview) {
        // Aperçu : l'indice garde sa place — c'est la page réelle — mais il dit ce
        // qui est vrai, plutôt que de promettre un son qui ne viendra pas.
        if (hint) { hint.textContent = hint.dataset.previewNote; }
    } else if (sound) {
        document.addEventListener('pointerdown', arm, { once: true });
        document.addEventListener('keydown', arm, { once: true });
        document.addEventListener('touchstart', arm, { once: true });
    }

    // --- 3. À l'heure dite ----------------------------------------------------
    // Le rechargement laisse au signal le temps de finir : il est court, mais le
    // quitter en pleine note le rendrait inaudible. Sans signal, rien ne justifie
    // d'attendre.
    const HOLD_MS = 1300;

    function openNow() {
        // Un seul rechargement par affichage : si les horloges divergent, la page
        // revient avec un nouveau décompte — celui du serveur — et ne boucle
        // jamais sur elle-même.
        //
        // L'aperçu enseignant ne recharge jamais : la page se relancerait sous ses
        // yeux, et la boucle n'aurait pas de fin.
        if (reloaded || preview) { return; }

        reloaded = true;

        // Signal retiré par l'épreuve : rien à jouer, et donc rien à attendre —
        // le rechargement part tout de suite, au lieu de laisser passer le temps
        // d'une note qui ne viendra pas.
        const sounded = sound && beep();

        // De quoi retrouver la nouvelle page : elle seule sait qu'elle vient de
        // s'ouvrir, et c'est elle qui clignote et qui affiche le bandeau.
        if (noticeKey) {
            try {
                sessionStorage.setItem(noticeKey, sounded ? 'son' : 'visuel');
            } catch (error) {
                // Sans trace, le rechargement a quand même lieu : l'étudiant voit
                // la page ouverte, sans le rappel.
            }
        }

        window.setTimeout(function () {
            window.location.reload();
        }, sounded ? HOLD_MS : 0);
    }

    if (paint() === 0) {
        openNow();

        return;
    }

    const tick = setInterval(function () {
        const left = paint();

        speak(left);

        if (left === 0) {
            clearInterval(tick);

            openNow();
        }
    }, 1000);

    // Retour depuis le cache de navigation (bouton « précédent », onglet
    // restauré) : l'échéance est recalculée, et si l'heure est passée
    // entre-temps le rechargement est immédiat plutôt qu'attendu une seconde.
    window.addEventListener('pageshow', function () {
        if (paint() === 0) {
            clearInterval(tick);
            openNow();
        }
    });

    // Point d'observation, dans le même esprit que window.QuizGuard côté
    // épreuve : la page dit si le signal est armé, dans quel état est le son, et
    // permet de l'essayer.
    window.QuizOpening = {
        armed: function () { return armed; },
        state: function () { return audio === null ? 'indisponible' : audio.state; },
        beep: beep
    };
})();
</script>
@endpush

