<?php

namespace Tests\Unit;

use App\Support\Import\QuestionSplitter;
use Tests\TestCase;

/**
 * Le découpage transforme un cas pratique en plusieurs questions : il ne doit
 * jamais scinder un énoncé qui ne fait que citer un numéro, ni produire une
 * question vide, ni dépasser la longueur autorisée par la saisie manuelle.
 */
class QuestionSplitterTest extends TestCase
{
    // --------------------------------------------------------------- Découpage

    public function test_un_cas_pratique_est_decoupe_et_le_contexte_est_repete(): void
    {
        $label = 'Cas pratique : révisez le système documentaire.'
            .' Question 1 Indiquer les documents à jour.'
            .' Question 2 Définir la nouvelle méthode.';

        $split = QuestionSplitter::split($label);

        $this->assertNotNull($split);
        $this->assertSame('Cas pratique : révisez le système documentaire.', $split['chapeau']);
        $this->assertCount(2, $split['questions']);

        // Le contexte est recopié au début de chaque question : chacune se lit
        // seule, sans revenir en arrière.
        $this->assertSame(
            "Cas pratique : révisez le système documentaire.\n\nIndiquer les documents à jour.",
            $split['questions'][0]
        );
        $this->assertSame(
            "Cas pratique : révisez le système documentaire.\n\nDéfinir la nouvelle méthode.",
            $split['questions'][1]
        );
    }

    public function test_les_numeros_en_guise_de_separateur_sont_retires(): void
    {
        $label = 'Question 1 Première étape ; Question 2 Seconde étape';

        $split = QuestionSplitter::split($label);

        $this->assertNotNull($split);
        $this->assertSame('', $split['chapeau']);
        $this->assertSame(['Première étape', 'Seconde étape'], $split['questions']);
    }

    public function test_un_deux_points_avant_le_repere_ouvre_le_decoupage(): void
    {
        // « Répondez aux questions suivantes : Question 1 … » : le deux-points
        // introduit bien les questions, et le contexte est recopié tel quel.
        $split = QuestionSplitter::split('Répondez aux questions suivantes : Question 1 Une. Question 2 Deux.');

        $this->assertNotNull($split);
        $this->assertSame('Répondez aux questions suivantes :', $split['chapeau']);
        $this->assertSame(
            ["Répondez aux questions suivantes :\n\nUne.", "Répondez aux questions suivantes :\n\nDeux."],
            $split['questions']
        );
    }

    public function test_des_numeros_qui_ne_se_suivent_pas_ne_sont_pas_un_decoupage(): void
    {
        // Un énoncé qui revient sur « Question 1 » ne raconte pas un cas
        // pratique : le scinder ferait perdre le sens.
        $label = 'Question 3 Détaillez la méthode. Question 1 Reprenez le contexte.';

        $this->assertNull(QuestionSplitter::split($label));
    }

    public function test_les_questions_peuvent_etre_ecrites_chacune_sur_sa_ligne(): void
    {
        $label = "Cas pratique :\n\nQuestion 1 Les documents\nQuestion 2 Les procédures";

        $split = QuestionSplitter::split($label);

        $this->assertNotNull($split);
        $this->assertSame('Cas pratique :', $split['chapeau']);
        $this->assertSame(
            ["Cas pratique :\n\nLes documents", "Cas pratique :\n\nLes procédures"],
            $split['questions']
        );
    }

    // ---------------------------------------------------------------- Scission

    public function test_une_coupure_seule_decoupe_l_enonce_en_deux(): void
    {
        $parts = QuestionSplitter::cut("Première partie.\n---\nSeconde partie.");

        $this->assertSame(['Première partie.', 'Seconde partie.'], $parts);
    }

    public function test_la_coupure_accepte_asterisques_et_soulignes(): void
    {
        $this->assertSame(['Une.', 'Deux.'], QuestionSplitter::cut("Une.\n***\nDeux."));
        $this->assertSame(['Une.', 'Deux.'], QuestionSplitter::cut("Une.\n___\nDeux."));
    }

    public function test_plusieurs_coupures_donnent_plusieurs_morceaux(): void
    {
        $parts = QuestionSplitter::cut("Une.\n---\nDeux.\n---\nTrois.");

        $this->assertSame(['Une.', 'Deux.', 'Trois.'], $parts);
    }

    public function test_un_enonce_sans_coupure_n_est_pas_scinde(): void
    {
        $this->assertSame([], QuestionSplitter::cut('Une question ordinaire.'));
    }

    public function test_une_coupure_en_fin_de_texte_ne_scinde_rien(): void
    {
        // Un seul morceau : il n'y a pas deux questions à séparer.
        $this->assertSame([], QuestionSplitter::cut("Une seule question.\n---"));
    }

    public function test_des_tirets_dans_une_phrase_ne_sont_pas_une_coupure(): void
    {
        $this->assertSame([], QuestionSplitter::cut('Une question --- avec des tirets au milieu.'));
    }

    // ---------------------------------------------------------------- Recousure

    public function test_recoudre_deux_morceaux_ne_repete_pas_le_contexte(): void
    {
        $left = "Cas : révisez le système.\n\nUne première partie.";
        $right = "Cas : révisez le système.\n\nDeuxième partie.";

        $this->assertSame(
            "Cas : révisez le système.\n\nUne première partie.\n\nDeuxième partie.",
            QuestionSplitter::join($left, $right)
        );
    }

    public function test_recoudre_deux_enonces_sans_contexte_commun_les_juxtapose(): void
    {
        $this->assertSame(
            "Première question.\n\nSeconde question.",
            QuestionSplitter::join('Première question.', 'Seconde question.')
        );
    }

    // -------------------------------------------------------------- Garde-fous

    public function test_un_seul_repere_ne_decoupe_rien(): void
    {
        $this->assertNull(QuestionSplitter::split('Question 5 Indiquer la nouvelle méthode.'));
    }

    public function test_une_citation_en_pleine_phrase_ne_decoupe_rien(): void
    {
        // « la Question 1 » est précédée d'un mot : ce n'est pas un titre.
        $label = 'Reprenez la Question 1 du sujet précédent. Question 2 Traitez ce cas.';

        $this->assertNull(QuestionSplitter::split($label));
    }

    public function test_la_casse_est_respectee(): void
    {
        $this->assertNull(QuestionSplitter::split('question 1 premier point. question 2 second point.'));
    }

    public function test_un_repere_sans_contenu_ne_produit_pas_de_decoupage(): void
    {
        // « Question 1 ; Question 2 … » : le premier repère n'annonce rien. On
        // préfère ne pas découper plutôt que créer une question vide.
        $this->assertNull(QuestionSplitter::split('Question 1 ; Question 2 Deux points.'));
    }

    public function test_un_contexte_repete_trop_long_annule_le_decoupage(): void
    {
        // Le contexte recopié s'ajoute au morceau : si la question produite
        // dépasse la limite, mieux vaut ne rien découper que créer une question
        // que la saisie manuelle aurait refusée.
        $chapeau = str_repeat('Contexte détaillé. ', 30);

        $split = QuestionSplitter::split($chapeau.'Question 1 Une. Question 2 Deux.', 200);

        $this->assertNull($split);
    }

    public function test_la_limite_est_verifiee_par_question_produite(): void
    {
        $split = QuestionSplitter::split('Question 1 Une ; Question 2 Deux', 2000);

        $this->assertNotNull($split);
        $this->assertCount(2, $split['questions']);
    }
}
