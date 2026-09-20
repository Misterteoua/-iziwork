@extends('layouts.app')

@section('title', $quiz->title)

@section('content')
<div class="px-4 sm:px-0 max-w-2xl mx-auto" id="quiz-question"
     data-next-url="{{ route('quiz.question', $quiz->token) }}"
     data-finish-url="{{ route('quiz.submit.page', $quiz->token) }}">
    @include('student.quiz._proctoring', ['quiz' => $quiz, 'attempt' => $attempt, 'remaining' => $remaining])

    {{-- Cette zone est remplacée sans rechargement par la réponse JSON : c'est
         ce qui permet au plein écran de survivre d'une question à l'autre. --}}
    <div id="quiz-card">
        @include('student.quiz._question_card', [
            'quiz' => $quiz,
            'question' => $question,
            'options' => $options,
            'position' => $position,
            'total' => $total,
        ])
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    const container = document.getElementById('quiz-question');
    const card = document.getElementById('quiz-card');

    if (!container || !card) { return; }

    // Chaque carte neuve apporte son propre champ : sans ce rattachement, le
    // compteur de caractères resterait celui de la question précédente.
    function bindCard() {
        const field = document.getElementById('answer_text');
        const counter = document.getElementById('answer-counter');

        if (!field || !counter) { return; }

        function refresh() { counter.textContent = field.value.length; }

        field.addEventListener('input', refresh);
        refresh();
    }

    bindCard();

    let busy = false;

    function showError(message) {
        const box = card.querySelector('[data-quiz-error]');

        if (box) {
            box.textContent = message;
            box.classList.remove('hidden');
        }
    }

    function release(form) {
        busy = false;

        const button = form.querySelector('button[type="submit"]');

        if (button) {
            button.disabled = false;
            button.removeAttribute('aria-busy');
        }
    }

    container.addEventListener('submit', function (event) {
        const form = event.target;

        if (!form.matches('[data-quiz-answer-form]')) { return; }

        // Double clic ou double appui : une seule réponse doit partir.
        if (busy) {
            event.preventDefault();
            return;
        }

        // Pas de fetch (navigateur ancien) : la voie classique reste entière.
        if (!window.fetch || !window.FormData) { return; }

        event.preventDefault();
        busy = true;

        const button = form.querySelector('button[type="submit"]');
        if (button) {
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
        }

        fetch(form.action, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: new FormData(form)
        }).then(function (response) {
            // Réponse invalide (aucune proposition cochée, texte vide) : la zone
            // d'erreur est réutilisée telle quelle.
            if (response.status === 422) {
                return response.json().then(function (payload) {
                    const errors = payload.errors || {};
                    const first = Object.keys(errors)[0];

                    showError(first ? errors[first][0] : 'Réponse invalide.');
                    release(form);
                });
            }

            const type = response.headers.get('content-type') || '';

            if (!response.ok || type.indexOf('application/json') === -1) {
                // Le serveur a renvoyé autre chose (redirection suivie, page
                // d'accès…) : l'envoi classique achèvera le parcours, et sa
                // propre réponse s'affichera.
                form.submit();
                return;
            }

            return response.json().then(function (payload) { apply(payload, form); });
        }).catch(function () {
            // Réseau coupé : le formulaire repart nativement plutôt que de
            // laisser le candidat devant un bouton muet.
            form.submit();
        });
    });

    function apply(payload, form) {
        // L'épreuve s'est terminée (temps écoulé, copie déjà rendue) : le
        // serveur indique où aller, la page suit.
        if (payload.navigate) {
            window.location.assign(payload.navigate);
            return;
        }

        if (payload.error) {
            showError(payload.error);
            release(form);
            return;
        }

        if (!payload.html) {
            form.submit();
            return;
        }

        card.innerHTML = payload.html;
        bindCard();
        busy = false;

        // Le chronomètre est recalé sur celui du serveur : c'est lui qui décide,
        // et une suite de questions ne doit pas faire dériver l'affichage.
        if (window.QuizGuard) { window.QuizGuard.refresh(payload.remaining); }
    }
})();
</script>
@endpush
