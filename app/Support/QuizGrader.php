<?php

namespace App\Support;

use App\Models\Form;
use App\Models\FormField;
use App\Models\Grader;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Correction d'une évaluation.
 *
 * Le calcul vit ici, et non dans un contrôleur, pour deux raisons : il est
 * testable seul, et surtout il n'existe qu'un seul endroit qui décide d'une
 * note — la page de résultat, le PDF et la liste de l'administrateur lisent
 * tous la même valeur stockée, ils ne la recalculent jamais.
 *
 * Une seule chose fait changer une note déjà écrite : la modification de la
 * question qui l'a produite ({@see QuizGrader::regradeQuestion()}). La bonne
 * réponse d'une épreuve se corrige après coup, et la note doit suivre.
 *
 * Deux familles de questions, deux régimes :
 *   - les questions à propositions sont corrigées automatiquement à la remise ;
 *   - les questions ouvertes reçoivent leur note plus tard, de l'enseignant. Tant
 *     qu'elles n'en ont pas, `points_awarded` reste `null` : « en attente » n'est
 *     pas « zéro point », et c'est cette différence qui permet de compter les
 *     copies à corriger.
 *
 * Un troisième cas s'ajoute : une évaluation peut demander que ses questions à
 * propositions soient corrigées à la main ({@see Form::quizGradesChoiceManually()}).
 * Elles suivent alors le régime des questions ouvertes — pas de note
 * automatique, `points_awarded` reste `null` — sans que rien ne change pour les
 * évaluations qui ne l'ont pas demandé.
 */
final class QuizGrader
{
    /**
     * Corrige la participation et fige le résultat.
     *
     * Idempotent : une épreuve déjà corrigée n'est pas recorrigée, sinon un
     * double envoi (dernière question puis expiration du chrono, cas fréquent)
     * pourrait écraser une note par une autre.
     *
     * Ce n'est pas pour autant une note définitive : une modification de la
     * question ({@see regradeQuestion()}) la recalcule — mais seulement tant
     * qu'aucun humain ne l'a signée.
     */
    public function finalize(QuizAttempt $attempt, bool $expired = false): QuizAttempt
    {
        if ($attempt->isFinished()) {
            return $attempt;
        }

        $questions = $attempt->questions();
        $answers = $attempt->answers()->get()->keyBy('form_field_id');

        // Une évaluation peut retirer l'auto-correction de ses questions à
        // propositions : elles attendent alors une note, comme une réponse
        // rédigée. Le réglage est lu une seule fois, la décision ne peut donc
        // pas changer d'une question à l'autre dans la même copie.
        $manualChoice = $attempt->form->quizGradesChoiceManually();

        foreach ($questions as $question) {
            $answer = $answers[$question->id] ?? null;

            // Une question non répondue vaut zéro par absence : elle n'a pas de
            // ligne de réponse, il n'y a donc rien à écrire — exactement comme
            // avant ce lot.
            if ($answer === null) {
                continue;
            }

            // Question ouverte : aucune machine ne note un texte libre. Elle est
            // marquée « en attente » et non « zéro », sinon l'enseignant n'aurait
            // plus aucun moyen de retrouver ce qui lui reste à corriger.
            //
            // Même traitement pour une question à propositions quand
            // l'évaluation a demandé la correction manuelle : on efface toute
            // note qu'une auto-correction aurait pu laisser, pour qu'elle
            // rejoigne la file de correction sans ambiguïté.
            if ($question->isOpen() || ($manualChoice && $question->isChoice())) {
                $answer->update(['is_correct' => null, 'points_awarded' => null]);

                continue;
            }

            $isCorrect = $question->isCorrectChoice($answer->chosenIndexes());

            $answer->update([
                'is_correct' => $isCorrect,
                'points_awarded' => $isCorrect ? (float) $question->points : 0.0,
            ]);
        }

        $attempt->update([
            'score' => $this->computedScore($attempt),
            'max_score' => round((float) $questions->sum('points'), 2),
            'status' => $expired
                ? QuizAttempt::STATUS_EXPIRED
                : QuizAttempt::STATUS_SUBMITTED,
            'submitted_at' => Carbon::now(),
        ]);

        return $attempt;
    }

    /**
     * Rejoue la correction automatique d'une question après modification de sa
     * définition — bonne réponse, barème, propositions, type.
     *
     * C'est la conséquence directe de l'enregistrement : la note suit la
     * définition, sans geste supplémentaire à demander à l'enseignant.
     *
     * @return int nombre de copies dont le total a été remis à jour
     */
    public function regradeQuestion(FormField $question): int
    {
        $this->regradeAnswers($question);

        return $this->refreshTotals($question->form);
    }

    /**
     * Rejoue la correction automatique de **toutes** les questions d'une
     * évaluation, et récapitule les totaux une seule fois.
     *
     * C'est le geste de rattrapage, et il existe pour ce que la renotation
     * automatique ne peut pas atteindre : des copies notées avant qu'elle
     * n'existe, ou une configuration corrigée autrement qu'à l'écran — un
     * import, une reprise directe en base. Les questions se succèdent, les
     * copies ne se récapitulent qu'à la fin.
     *
     * @return int nombre de copies dont le total a été remis à jour
     */
    public function regradeQuiz(Form $quiz): int
    {
        foreach ($quiz->quizQuestions()->get() as $question) {
            $this->regradeAnswers($question);
        }

        return $this->refreshTotals($quiz);
    }

    /**
     * Ce qu'un recalcul changerait, sans rien changer.
     *
     * Le geste suit le même chemin que {@see regradeQuiz()} — même ensemble de
     * réponses, même règle, même récapitulatif des totaux — mais il compte au
     * lieu d'écrire. Refaire le calcul de son côté, avec sa propre requête,
     * serait le seul moyen de se tromper : l'aperçu annoncerait alors autre chose
     * que ce que la confirmation ferait.
     *
     * Ce qui compte, c'est le total **visible**. Deux réponses peuvent se
     * compenser dans une même copie : la machine change deux fois, et la note
     * affichée à l'étudiant ne bouge pas. Ces cas sont comptés à part plutôt
     * qu'annoncés comme des renotations qu'ils ne sont pas.
     *
     * Rien n'est écrit ici : l'aperçu ne se contente pas de promettre, il ne
     * touche ni aux réponses ni aux totaux.
     *
     * @return array{
     *     rows: array<int, array{attempt: QuizAttempt, from: float|null, to: float, max_from: float|null, max_to: float, answers: int, released: int, reclaimed: int}>,
     *     copies: int, finished: int, answers: int, quiet: int, released: int, reclaimed: int, changes: bool
     * }
     */
    public function previewRegrade(Form $quiz): array
    {
        $questions = $quiz->quizQuestions()->get();

        // Chaque question est comparée à elle-même : on chiffre l'effet de la
        // configuration telle qu'elle est enregistrée.
        $targets = $questions
            ->map(fn (FormField $question): array => [$question, $question])
            ->all();

        return $this->previewTotals($quiz, $questions, $this->touchedAnswers($targets));
    }

    /**
     * Ce qu'enregistrer cette question changerait aux copies déjà rendues.
     *
     * Le pendant de {@see regradeQuestion()}, mais avant l'enregistrement : la
     * définition appliquée est celle **en cours de saisie**, et non celle qui est
     * en base. C'est la décision que l'enseignant est en train de prendre qu'il
     * faut chiffrer — une fois la question enregistrée, il n'y a plus rien à
     * prévenir, la renotation a déjà eu lieu.
     *
     * Rien n'est écrit : ni réponse, ni total. Le chemin est celui de
     * l'enregistrement — même ensemble de réponses, même règle, même
     * récapitulatif des totaux —, seul le résultat change de camp.
     *
     * @param  array<string, mixed>  $attributes  les attributs que la question porterait
     * @return array{
     *     rows: array<int, array{attempt: QuizAttempt, from: float|null, to: float, max_from: float|null, max_to: float, answers: int, released: int, reclaimed: int}>,
     *     copies: int, finished: int, answers: int, quiet: int, released: int, reclaimed: int, changes: bool
     * }
     */
    public function previewQuestionRegrade(FormField $question, array $attributes): array
    {
        $proposed = $this->proposedQuestion($question, $attributes);

        // Les questions de l'évaluation, celle-ci remplacée par ce qu'elle
        // serait : le barème d'une copie dépend des questions qu'elle a reçues,
        // et une question dont le barème change déplace donc le total de copies
        // qui ne l'ont même pas eue.
        $questions = $question->form->quizQuestions()->get()
            ->map(fn (FormField $candidate): FormField => $candidate->is($question) ? $proposed : $candidate);

        return $this->previewTotals(
            $question->form,
            $questions,
            $this->touchedAnswers([[$question, $proposed]])
        );
    }

    /**
     * Ce que la suppression d'une question changerait aux copies déjà rendues.
     *
     * Un cas à part, et le seul où la machine ne recalcule rien : les réponses de
     * la question tombent avec elle (la clé étrangère est en cascade), donc chaque
     * copie perd exactement ce que cette question lui avait rapporté, et son
     * barème perd les points de la question. Aucun tri sur l'auteur de la note ne
     * s'applique ici — une réponse qui n'existe plus ne peut plus être comptée,
     * même signée par un humain —, et c'est ce qui distingue cette passe des
     * autres.
     *
     * Le calcul passe par {@see previewTotals()} comme les autres aperçus :
     * l'avant/après annoncé est celui de {@see refreshTotals()}, la passe qui suit
     * réellement la suppression.
     *
     * @return array{
     *     rows: array<int, array{attempt: QuizAttempt, from: float|null, to: float, max_from: float|null, max_to: float, answers: int, released: int, reclaimed: int}>,
     *     copies: int, finished: int, answers: int, quiet: int, released: int, reclaimed: int, changes: bool
     * }
     */
    public function previewQuestionRemoval(FormField $question): array
    {
        $questions = $question->form->quizQuestions()->get()
            ->reject(fn (FormField $candidate): bool => $candidate->is($question));

        $touched = [];

        // Seules les copies rendues sont concernées : une épreuve en cours sera
        // corrigée sans cette question, et son total suivra à sa remise.
        QuizAnswer::query()
            ->where('form_field_id', $question->id)
            ->whereHas('attempt', fn ($attempt) => $attempt->whereIn('status', [
                QuizAttempt::STATUS_SUBMITTED,
                QuizAttempt::STATUS_EXPIRED,
            ]))
            ->chunkById(500, function (Collection $answers) use (&$touched): void {
                foreach ($answers as $answer) {
                    $before = $answer->awardedPoints();

                    // Une réponse jamais notée n'enlève rien au total : seul le
                    // barème de la copie disparaît, et c'est la comparaison des
                    // totaux qui la fera apparaître.
                    if ($before === null || $before === 0.0) {
                        continue;
                    }

                    $id = (int) $answer->quiz_attempt_id;

                    $touched[$id] ??= ['answers' => 0, 'delta' => 0.0, 'released' => 0, 'reclaimed' => 0];
                    $touched[$id]['answers']++;
                    $touched[$id]['delta'] -= $before;
                }
            });

        return $this->previewTotals($question->form, $questions, $touched);
    }

    /**
     * Les réponses qu'un recalcul atteindrait, et ce qui changerait pour chacune.
     *
     * La question enregistrée désigne les réponses — ce sont celles qui existent
     * —, et la définition qui l'accompagne dit avec quelle règle les renoter. Les
     * deux sont la même sauf à l'aperçu d'une question en cours de modification,
     * où l'une est en base et l'autre à l'écran.
     *
     * @param  array<int, array{0: FormField, 1: FormField}>  $targets  [question enregistrée, définition appliquée]
     * @return array<int, array{answers: int, delta: float, released: int, reclaimed: int}>
     */
    private function touchedAnswers(array $targets): array
    {
        $touched = [];

        foreach ($targets as [$stored, $definition]) {
            $automatic = $this->machineDecides($definition);

            $this->regradableAnswers($stored)
                ->chunkById(500, function (Collection $answers) use ($definition, $automatic, &$touched): void {
                    foreach ($answers as $answer) {
                        $points = $this->derivedPoints($answer, $definition, $automatic);
                        $before = $answer->awardedPoints();

                        // Exactement le tri de {@see regradeAnswers()} : ce qui
                        // est déjà juste ne sera pas réécrit, et n'a donc rien à
                        // faire dans un aperçu.
                        if ($before === $points) {
                            continue;
                        }

                        $id = (int) $answer->quiz_attempt_id;

                        $touched[$id] ??= ['answers' => 0, 'delta' => 0.0, 'released' => 0, 'reclaimed' => 0];
                        $touched[$id]['answers']++;
                        $touched[$id]['delta'] += ($points ?? 0.0) - ($before ?? 0.0);

                        // Une note effacée renvoie la copie dans la file de
                        // correction ; une note posée sur une réponse qui
                        // attendait l'en sort.
                        if ($points === null) {
                            $touched[$id]['released']++;
                        } elseif ($before === null) {
                            $touched[$id]['reclaimed']++;
                        }
                    }
                });
        }

        return $touched;
    }

    /**
     * La question telle qu'elle serait, sans être enregistrée.
     *
     * Les attributs viennent de l'appelant, et ce sont **exactement** ceux qu'il
     * écrit au moment d'enregistrer : l'aperçu porte ainsi sur la définition qui
     * serait réellement posée, et non sur une reconstitution qui pourrait en
     * différer d'un champ.
     *
     * La copie garde l'identifiant et l'évaluation de l'originale : les réponses
     * sont retrouvées par lui, et la règle de correction manuelle se lit sur
     * l'évaluation.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function proposedQuestion(FormField $question, array $attributes): FormField
    {
        $proposed = new FormField($attributes + ['form_id' => $question->form_id]);
        $proposed->id = $question->id;
        $proposed->setRelation('form', $question->form);

        return $proposed;
    }

    /**
     * Les copies dont le recalcul déplacerait la note ou le barème.
     *
     * La comparaison est celle de {@see refreshTotalsFor()} — la même, ligne pour
     * ligne — pour que l'aperçu ne puisse pas annoncer un changement que
     * l'écriture ne ferait pas, ni taire celui qu'elle ferait.
     *
     * @param  Collection<int, FormField>  $questions
     * @param  array<int, array{answers: int, delta: float, released: int, reclaimed: int}>  $touched
     * @return array{
     *     rows: array<int, array{attempt: QuizAttempt, from: float|null, to: float, max_from: float|null, max_to: float, answers: int, released: int, reclaimed: int}>,
     *     copies: int, finished: int, answers: int, quiet: int, released: int, reclaimed: int, changes: bool
     * }
     */
    private function previewTotals(Form $quiz, Collection $questions, array $touched): array
    {
        $attempts = $quiz->attempts()
            ->whereIn('status', [QuizAttempt::STATUS_SUBMITTED, QuizAttempt::STATUS_EXPIRED])
            ->orderBy('reference')
            ->get();

        // La note d'avant vient de la base, pas d'un recalcul : c'est ce que
        // l'enseignant et les étudiants lisent en ce moment, et une copie dont la
        // note enregistrée ne correspond déjà plus à ses réponses compte donc
        // comme une copie que le recalcul répare.
        $scores = $this->scoresFor($attempts->modelKeys());

        $rows = [];
        $shown = 0;

        foreach ($attempts as $attempt) {
            $reached = $touched[$attempt->id] ?? null;

            $score = round((float) $scores->get($attempt->id, 0.0) + ($reached['delta'] ?? 0.0), 2);
            $max = round((float) QuizAttempt::questionsFrom($questions, $attempt->question_order)->sum('points'), 2);

            $from = $attempt->score === null ? null : (float) $attempt->score;
            $maxFrom = $attempt->max_score === null ? null : (float) $attempt->max_score;

            // Le tri de {@see refreshTotalsFor()} : il n'y a rien à écrire quand
            // la note et le barème sont déjà ceux du calcul.
            if ($from !== null && $from === $score && $maxFrom !== null && $maxFrom === $max) {
                continue;
            }

            $shown += $reached['answers'] ?? 0;

            $rows[] = [
                'attempt' => $attempt,
                'from' => $from,
                'to' => $score,
                'max_from' => $maxFrom,
                'max_to' => $max,
                'answers' => $reached['answers'] ?? 0,
                'released' => $reached['released'] ?? 0,
                'reclaimed' => $reached['reclaimed'] ?? 0,
            ];
        }

        $answers = (int) array_sum(array_column($touched, 'answers'));

        return [
            'rows' => $rows,
            'copies' => count($rows),
            'finished' => $attempts->count(),
            'answers' => $answers,
            // Ce que le recalcul écrirait : une note qui bouge, ou seulement une
            // réponse renotée. Les deux comptent — un verdict corrigé se lit sur
            // la copie de l'étudiant, même quand le total ne change pas.
            'changes' => $rows !== [] || $answers > 0,
            // Renotations sans effet visible : elles ont lieu, elles sont
            // comptées, mais aucune note ne bouge — le tableau ne peut pas les
            // montrer.
            'quiet' => $answers - $shown,
            'released' => count(array_filter($rows, static fn (array $row): bool => $row['released'] > 0)),
            'reclaimed' => count(array_filter($rows, static fn (array $row): bool => $row['released'] === 0 && $row['reclaimed'] > 0)),
        ];
    }

    /**
     * La part dérivée d'une question : ce que la machine décide, ou ce qu'elle
     * ne peut plus décider.
     *
     * Une note produite par la machine est une donnée calculée : elle se
     * recalcule. Une note signée par un humain ne se touche jamais. Le tri se
     * fait donc sur l'**auteur** de la note, jamais sur sa date — c'est lui qui
     * distingue une déduction d'une décision.
     */
    private function regradeAnswers(FormField $question): void
    {
        $automatic = $this->machineDecides($question);

        $this->regradableAnswers($question)
            ->chunkById(500, function (Collection $answers) use ($question, $automatic): void {
                // Les réponses sont classées par verdict avant d'être écrites :
                // cinq cents copies justes font une requête, pas cinq cents.
                $groups = [];

                foreach ($answers as $answer) {
                    $points = $this->derivedPoints($answer, $question, $automatic);
                    // Le barème d'une question ne descend pas sous 0,5 point :
                    // « juste » et « la totalité des points » sont donc la même
                    // chose, et une note nulle est une réponse fausse.
                    $isCorrect = $points === null ? null : $points > 0;

                    // La comparaison porte sur la note seule : pour une question
                    // à propositions, « juste » et « la totalité des points »
                    // sont la même chose. Rien n'est donc écrit quand la note est
                    // déjà la bonne — changer le seul énoncé ne touche à aucune
                    // ligne, l'horodatage des réponses reste intact.
                    if ($answer->awardedPoints() === $points) {
                        continue;
                    }

                    $key = $isCorrect === null ? 'pending' : ($isCorrect ? 'right' : 'wrong');

                    $groups[$key] ??= ['is_correct' => $isCorrect, 'points_awarded' => $points, 'ids' => []];
                    $groups[$key]['ids'][] = $answer->id;
                }

                foreach ($groups as $group) {
                    QuizAnswer::whereIn('id', $group['ids'])->update([
                        'is_correct' => $group['is_correct'],
                        'points_awarded' => $group['points_awarded'],
                    ]);
                }
            });

    }

    /**
     * La machine décide-t-elle des notes de cette question ?
     *
     * Elle ne le fait que si la question est un QCM et que l'évaluation n'a pas
     * demandé la correction manuelle des QCM. Sinon, comme à la remise d'une
     * copie, la note retombe « en attente » : une note automatique devenue sans
     * objet ne doit pas passer pour une décision, et la copie doit revenir dans
     * la file de correction.
     */
    private function machineDecides(FormField $question): bool
    {
        return $question->isChoice() && ! $question->form->quizGradesChoiceManually();
    }

    /**
     * La note que la machine attribue à une réponse, ou `null` si elle ne peut
     * plus trancher.
     *
     * Une seule règle, lue deux fois : l'écriture l'applique, l'aperçu la
     * compare. Deux calculs séparés finiraient par diverger, et l'aperçu
     * annoncerait alors autre chose que ce que la confirmation ferait.
     */
    private function derivedPoints(QuizAnswer $answer, FormField $question, bool $automatic): ?float
    {
        if (! $automatic) {
            return null;
        }

        return $question->isCorrectChoice($answer->chosenIndexes()) ? (float) $question->points : 0.0;
    }

    /**
     * Les réponses qu'un recalcul peut atteindre.
     *
     * Celles d'une copie rendue, et qu'aucun humain n'a signées : ces colonnes
     * d'attribution sont exactement ce qui désigne une décision, et une décision
     * ne se recalcule pas. Le filtre vit ici, et non dans chaque appelant, pour
     * que l'aperçu et l'écriture regardent le même ensemble.
     *
     * @return Builder<QuizAnswer>
     */
    private function regradableAnswers(FormField $question): Builder
    {
        return QuizAnswer::query()
            ->where('form_field_id', $question->id)
            ->whereNull('graded_by_grader_id')
            ->whereNull('graded_by_admin_id')
            // Une épreuve en cours ne se renote pas : elle sera corrigée à sa
            // remise, avec la définition en vigueur à ce moment-là.
            ->whereHas('attempt', fn ($attempt) => $attempt->whereIn('status', [
                QuizAttempt::STATUS_SUBMITTED,
                QuizAttempt::STATUS_EXPIRED,
            ]));
    }

    /**
     * Remet d'aplomb la note et le barème des copies déjà rendues.
     *
     * Deux cas l'appellent : une question modifiée — le barème d'une copie
     * change, y compris pour celles qui n'ont pas répondu à cette question — et
     * une question supprimée, dont les réponses tombent avec elle (la clé
     * étrangère est en cascade) alors que le total des copies continuerait de
     * les compter.
     *
     * @return int nombre de copies dont le total a effectivement changé
     */
    public function refreshTotals(Form $quiz): int
    {
        $ids = $quiz->attempts()
            ->whereIn('status', [QuizAttempt::STATUS_SUBMITTED, QuizAttempt::STATUS_EXPIRED])
            ->orderBy('id')
            ->pluck('id')
            ->all();

        return $this->refreshTotalsFor($ids);
    }

    /**
     * Le total de chaque copie, par paquets.
     *
     * @param  array<int, int>  $attemptIds
     */
    private function refreshTotalsFor(array $attemptIds): int
    {
        $changed = 0;

        foreach (array_chunk($attemptIds, 200) as $ids) {
            $attempts = QuizAttempt::query()
                ->with('form')
                ->whereIn('id', $ids)
                ->get()
                ->groupBy('form_id');

            foreach ($attempts as $sameQuiz) {
                // Les questions de l'évaluation sont chargées une fois pour
                // toutes les copies du paquet : le barème d'une copie dépend des
                // questions qu'elle a réellement reçues (tirage aléatoire
                // compris), et une requête par copie serait le seul coût réel de
                // cette passe.
                $questions = $sameQuiz->first()->form->quizQuestions()->get();
                $scores = $this->scoresFor($sameQuiz->modelKeys());

                // Les copies sont regroupées par total avant d'être écrites :
                // une promotion qui tombe au même score fait une requête, pas
                // une par candidat. Le nombre d'écritures suit les totaux
                // distincts, jamais l'effectif.
                $groups = [];

                foreach ($sameQuiz as $attempt) {
                    $score = (float) $scores->get($attempt->id, 0.0);
                    $max = round((float) QuizAttempt::questionsFrom(
                        $questions,
                        $attempt->question_order
                    )->sum('points'), 2);

                    if ($attempt->score !== null && (float) $attempt->score === $score
                        && $attempt->max_score !== null && (float) $attempt->max_score === $max) {
                        continue;
                    }

                    $key = $score.'|'.$max;

                    $groups[$key] ??= ['score' => $score, 'max_score' => $max, 'copies' => 0, 'ids' => []];
                    $groups[$key]['copies']++;
                    $groups[$key]['ids'][] = $attempt->id;
                }

                foreach ($groups as $group) {
                    QuizAttempt::whereIn('id', $group['ids'])->update([
                        'score' => $group['score'],
                        'max_score' => $group['max_score'],
                    ]);

                    $changed += $group['copies'];
                }
            }
        }

        return $changed;
    }

    /**
     * Enregistre une décision de correction, en archivant celle qu'elle remplace.
     *
     * C'est le point d'entrée normal : noter, commenter ou reprendre une note
     * passent tous par ici, et une seule règle décide de ce qui est journalisé.
     *
     * Trois cas, et rien d'autre :
     *   - rien ne change et aucun motif n'est donné : on ne touche à rien, il n'y
     *     a rien à raconter ;
     *   - une note déjà posée change (ou l'administration la confirme après
     *     examen, avec un motif) : l'ancienne note est **archivée avant d'être
     *     remplacée** ;
     *   - la réponse attendait encore sa note : rien à archiver, on écrit.
     *
     * `$reviewedBy` est l'administrateur qui relit ; il reste vide quand c'est un
     * correcteur qui retouche sa propre note, ce qui distingue une relecture
     * d'administration d'un simple changement d'avis.
     *
     * Une note de zéro est une note : elle s'écrit comme une autre, et la
     * réponse cesse d'être en attente. « En attente » ne s'obtient qu'en
     * laissant le champ vide — c'est cette distinction, et non la valeur
     * elle-même, qui dit si un enseignant a tranché.
     */
    public function record(
        QuizAnswer $answer,
        ?float $points,
        ?string $comment,
        Grader|int|null $by = null,
        ?string $reason = null,
        ?int $reviewedBy = null,
    ): QuizAnswer {
        $reason = self::normalizeComment($reason);
        $comment = self::normalizeComment($comment);

        // La comparaison passe par {@see QuizAnswer::awardedPoints()} : une
        // réponse jamais notée rend `null`, une réponse notée zéro rend 0.0.
        // Comparer sur la seule conversion en nombre confondait les deux — un
        // zéro attribué à une réponse en attente passait alors pour « rien n'a
        // changé », et la note était perdue sans un mot.
        $changesPoints = $points !== null && $answer->awardedPoints() !== $points;
        $changesComment = $answer->grader_comment !== $comment;

        if (! $changesPoints && ! $changesComment) {
            // Rien à réécrire. Il reste un cas : l'administration a examiné la
            // note et la **maintient** en donnant un motif. La relecture est
            // alors journalisée sans que l'auteur de la note change — une note
            // confirmée reste la note du correcteur qui l'a posée.
            if ($reason !== null) {
                $this->archive($answer, $reason, $reviewedBy);
            }

            return $answer;
        }

        // Archiver *avant* d'écrire : au moment où cette ligne est créée, les
        // colonnes de la réponse décrivent encore la note remplacée.
        $this->archive($answer, $reason, $reviewedBy);

        if ($points === null) {
            return $this->comment($answer, $comment, $by);
        }

        return $this->award($answer, $points, $comment, $by);
    }

    /**
     * Journalise une relecture : ce qui était enregistré avant qu'on y touche.
     *
     * Rien n'est écrit quand la réponse n'était pas encore notée : il n'y a pas
     * de note remplacée à raconter, et une ligne vide au journal ferait croire à
     * une reprise là où l'administration a simplement posé la première note.
     */
    private function archive(QuizAnswer $answer, ?string $reason, ?int $reviewedBy): void
    {
        if (! $answer->isGraded()) {
            return;
        }

        $answer->reviews()->create([
            'previous_points' => $answer->points_awarded,
            'previous_comment' => $answer->grader_comment,
            'previous_grader_id' => $answer->graded_by_grader_id,
            'previous_admin_id' => $answer->graded_by_admin_id,
            'reviewed_by_admin_id' => $reviewedBy,
            'reason' => $reason,
        ]);
    }

    /**
     * Attribue les points d'une réponse rédigée, et son commentaire.
     *
     * La note est bornée au barème de la question : accepter davantage donnerait
     * une épreuve notée au-dessus de son total, ce qu'aucun jury n'accepte. Une
     * note partielle est possible — c'est l'intérêt d'une correction humaine — et
     * dans ce cas la réponse n'est pas déclarée « juste » : seul le barème entier
     * l'est.
     *
     * Le commentaire suit la note : le formulaire renvoie l'état complet de la
     * réponse, donc un champ vidé efface le commentaire précédent. C'est ce qui
     * permet de retirer une appréciation qu'on regrette, plutôt que de laisser
     * un texte qu'on ne peut plus enlever.
     *
     * `$by` désigne l'auteur de la note — un correcteur externe, ou un
     * administrateur (son identifiant). La distinction n'est pas décorative :
     * c'est elle qui permet à un correcteur de ne retrouver dans son export que
     * les copies qu'il a effectivement corrigées, et à une note contestée
     * d'avoir un auteur.
     */
    public function award(QuizAnswer $answer, float $points, ?string $comment = null, Grader|int|null $by = null): QuizAnswer
    {
        $question = $answer->field;
        $max = $question === null ? 0.0 : (float) $question->points;

        $points = max(0.0, min($points, $max));

        $answer->update([
            'points_awarded' => round($points, 2),
            'is_correct' => $points >= $max && $max > 0,
            'grader_comment' => self::normalizeComment($comment),
        ] + $this->attribution($answer, $by, overwrite: true));

        return $answer;
    }

    /**
     * Enregistre un commentaire sans attribuer de note.
     *
     * Un correcteur peut vouloir écrire une remarque avant de décider de la
     * note : la perdre silencieusement serait le pire des comportements. La
     * réponse reste donc « en attente » — le commentaire, lui, est conservé.
     *
     * L'attribution ne change que si la réponse n'en avait aucune : c'est le
     * premier qui a travaillé sur la copie qui reste l'auteur tant qu'une note
     * n'a pas été posée par quelqu'un d'autre.
     */
    public function comment(QuizAnswer $answer, ?string $comment, Grader|int|null $by = null): QuizAnswer
    {
        $answer->update([
            'grader_comment' => self::normalizeComment($comment),
        ] + $this->attribution($answer, $by, overwrite: false));

        return $answer;
    }

    /**
     * Le commentaire tel qu'il est retenu : espaces retirés, vide valant rien.
     *
     * Public parce que ceux qui comparent une décision à l'état enregistré —
     * pour décider s'il y a lieu de journaliser — doivent comparer la même chose
     * que ce qui sera réellement écrit.
     */
    public static function normalizeComment(?string $comment): ?string
    {
        $comment = trim((string) $comment);

        return $comment === '' ? null : $comment;
    }

    /**
     * Colonnes d'attribution d'une note, selon qui l'a posée.
     *
     * `null` en retour signifie « ne touche à rien » : une réponse corrigée par
     * un correcteur, puis seulement commentée par un administrateur, garde son
     * auteur d'origine — sans quoi elle disparaîtrait de l'export du correcteur
     * sans que personne ne s'en aperçoive.
     *
     * @return array<string, int|null>
     */
    private function attribution(QuizAnswer $answer, Grader|int|null $by, bool $overwrite): array
    {
        if ($by === null) {
            return [];
        }

        $alreadyAttributed = $answer->graded_by_grader_id !== null
            || $answer->graded_by_admin_id !== null;

        if (! $overwrite && $alreadyAttributed) {
            return [];
        }

        return $by instanceof Grader
            ? ['graded_by_grader_id' => $by->getKey(), 'graded_by_admin_id' => null]
            : ['graded_by_grader_id' => null, 'graded_by_admin_id' => $by];
    }

    /**
     * Recalcule la note d'une copie après une correction manuelle.
     *
     * `score` est la somme de ce qui a été attribué, QCM automatiques compris :
     * une seule valeur stockée, donc aucun risque que l'affichage et l'export
     * racontent deux histoires différentes.
     */
    public function recompute(QuizAttempt $attempt): QuizAttempt
    {
        $attempt->update(['score' => $this->computedScore($attempt)]);

        return $attempt;
    }

    /**
     * Une question non répondue vaut zéro : la réponse est simplement absente
     * du bulletin, elle n'est pas comptée comme fausse dans un total d'erreurs.
     */
    public function isAnswered(QuizAttempt $attempt, FormField $question): bool
    {
        return $attempt->answers()->where('form_field_id', $question->id)->exists();
    }

    /**
     * Somme des points attribués à une copie.
     */
    private function computedScore(QuizAttempt $attempt): float
    {
        return (float) $this->scoresFor([$attempt->id])->get($attempt->id, 0.0);
    }

    /**
     * Somme des points attribués, copie par copie, en une seule requête.
     *
     * Une requête agrégée plutôt qu'une par copie : renoter une question touche
     * parfois toute une promotion, et la note provisoire doit rester lisible
     * même sur une épreuve à plusieurs milliers de candidats.
     *
     * Les réponses non encore corrigées sont ignorées — et non comptées zéro —
     * pour que la note provisoire annonce ce qui est acquis, pas ce qui reste à
     * décider.
     *
     * @param  array<int, int>  $attemptIds
     * @return Collection<int, float> la note de chaque copie, indexée par son identifiant
     */
    private function scoresFor(array $attemptIds): Collection
    {
        if ($attemptIds === []) {
            return collect();
        }

        return QuizAnswer::query()
            ->whereIn('quiz_attempt_id', $attemptIds)
            ->whereNotNull('points_awarded')
            ->groupBy('quiz_attempt_id')
            ->selectRaw('quiz_attempt_id, SUM(points_awarded) as total')
            ->get()
            ->mapWithKeys(static fn (QuizAnswer $row): array => [
                (int) $row->quiz_attempt_id => round((float) $row->total, 2),
            ]);
    }
}
