<?php

namespace Tests\Unit;

use App\Support\QuestionText;
use Tests\TestCase;

/**
 * Le formateur est le seul endroit qui transforme un énoncé en HTML : ce qu'il
 * produit doit rester lisible, prudent sur les repères, et sûr.
 *
 * Les cas ci-dessous viennent tous d'un vrai énoncé : celui qui arrive d'un
 * fichier importé, collé en un seul bloc, et qu'un candidat doit pouvoir lire.
 */
class QuestionTextTest extends TestCase
{
    private function html(?string $text, string $prefix = ''): string
    {
        return (string) QuestionText::html($text, $prefix);
    }

    // ------------------------------------------------------- Paragraphes

    public function test_un_enonce_court_reste_un_simple_paragraphe(): void
    {
        $this->assertSame(
            '<p class="qt-p">Quelle est la complexité de la recherche dichotomique ?</p>',
            $this->html('Quelle est la complexité de la recherche dichotomique ?')
        );
    }

    public function test_une_ligne_vide_separe_deux_paragraphes(): void
    {
        $this->assertSame(
            '<p class="qt-p">Premier point.</p><p class="qt-p">Second point.</p>',
            $this->html("Premier point.\n\nSecond point.")
        );
    }

    public function test_un_retour_a_la_ligne_simple_devient_un_saut_de_ligne(): void
    {
        // Ce que l'enseignant écrit à la main garde ses lignes : c'est déjà une
        // mise en forme, elle ne doit pas être perdue.
        $this->assertSame(
            '<p class="qt-p">Premier point.<br>Second point.</p>',
            $this->html("Premier point.\nSecond point.")
        );
    }

    public function test_un_enonce_vide_ne_produit_rien(): void
    {
        $this->assertSame('', $this->html('   '));
        $this->assertSame('', $this->html(null));
    }

    // ------------------------------------------------------------ Listes

    public function test_une_enumeration_de_lettres_devient_une_liste(): void
    {
        $this->assertSame(
            '<ol type="a" class="qt-list qt-alpha">'
            ."<li>L&#039;utilisation de documents anciens</li>"
            .'<li>La codification incohérente</li>'
            .'<li>La politique intégrée</li>'
            .'</ol>',
            $this->html("a)L'utilisation de documents anciens ; b)La codification incohérente ; c)La politique intégrée")
        );
    }

    public function test_une_enumeration_numerotee_devient_une_liste(): void
    {
        $this->assertSame(
            '<p class="qt-p">Deux étapes :</p>'
            .'<ol class="qt-list qt-numbered"><li>Lire le sujet</li><li>Rédiger la réponse</li></ol>',
            $this->html('Deux étapes : 1. Lire le sujet 2. Rédiger la réponse')
        );
    }

    public function test_des_puces_sans_espace_apres_le_tiret_deviennent_une_liste(): void
    {
        $this->assertSame(
            '<p class="qt-p">Les documents à jour :</p>'
            .'<ul class="qt-list qt-bullets"><li>le manuel du SMI</li><li>la politique intégrée</li></ul>',
            $this->html('Les documents à jour : -le manuel du SMI ; -la politique intégrée')
        );
    }

    public function test_une_enumeration_ecrite_ligne_par_ligne_devient_une_liste(): void
    {
        // Même chose quand l'enseignant tape chaque élément sur sa ligne :
        // le retour à la ligne tenait lieu de liste, la liste le dit mieux.
        $this->assertSame(
            '<p class="qt-p">Expliquez la démarche.</p>'
            .'<ol class="qt-list qt-numbered"><li>Les documents</li><li>Les procédures</li></ol>',
            $this->html("Expliquez la démarche.\n\n1. Les documents\n2. Les procédures")
        );
    }

    public function test_des_lignes_de_familles_differentes_ne_font_pas_une_liste(): void
    {
        $this->assertSame(
            '<p class="qt-p">a) Les documents<br>2. Les procédures</p>',
            $this->html("a) Les documents\n2. Les procédures")
        );
    }

    public function test_une_ligne_vide_interrompt_une_enumeration(): void
    {
        // Deux éléments séparés par un paragraphe ne sont plus la même liste.
        $html = $this->html("1. Les documents\n\n2. Les procédures");

        $this->assertStringNotContainsString('<ol', $html);
        $this->assertSame(
            '<p class="qt-p">1. Les documents</p><p class="qt-p">2. Les procédures</p>',
            $html
        );
    }

    // -------------------------------------------------------- Prudence

    public function test_un_marqueur_isole_ne_fabrique_pas_de_liste(): void
    {
        // Un seul « a) » au milieu d'une phrase ne fait pas une énumération.
        $this->assertSame(
            "<p class=\"qt-p\">Le candidat répond en cochant a) la première case.</p>",
            $this->html('Le candidat répond en cochant a) la première case.')
        );
    }

    public function test_une_liste_s_arrete_avant_l_element_qui_ne_suit_plus(): void
    {
        // Cas réel : « c) … d) … » puis la question suivante qui rouvre sa propre
        // énumération (« b) »). La liste doit s'arrêter à la rupture, pas
        // disparaître en emportant c) et d) avec elle.
        $html = $this->html('Les faiblesses : c)La politique est floue ; d)Les logigrammes manquent. Question 5 b)Quelles sont les étapes ?');

        $this->assertStringContainsString(
            '<ol type="a" start="3" class="qt-list qt-alpha"><li>La politique est floue</li><li>Les logigrammes manquent.</li></ol>',
            $html
        );

        // Le repère de la question suivante a quitté la liste pour son paragraphe.
        $this->assertStringContainsString('<p class="qt-p">Question 5 b) Quelles sont les étapes ?</p>', $html);
    }

    public function test_une_enumeration_qui_commence_plus_loin_garde_ses_lettres(): void
    {
        // L'énoncé renvoie parfois à « la réponse c) » : la liste ne peut pas se
        // permettre de renuméroter c) et d) en a) et b).
        $this->assertStringContainsString(
            '<ol type="a" start="3" class="qt-list qt-alpha"><li>La politique est floue</li>',
            $this->html('c)La politique est floue ; d)Les logigrammes manquent')
        );

        $this->assertStringContainsString(
            '<ol start="3" class="qt-list qt-numbered"><li>Troisième étape</li>',
            $this->html('3. Troisième étape ; 4. Quatrième étape')
        );
    }

    public function test_des_numeros_qui_ne_se_suivent_pas_ne_font_pas_une_liste(): void
    {
        $html = $this->html('Voir 1. le premier point et 3. le troisième point.');

        $this->assertStringNotContainsString('<ol', $html);
        $this->assertStringNotContainsString('<ul', $html);
    }

    public function test_les_decimaux_et_les_abreviations_ne_sont_pas_des_marqueurs(): void
    {
        // « 2.5 » (pas d'espace), « N°1. » (collé) et « QSE) » ne doivent
        // déclencher aucune liste.
        $html = $this->html('Le seuil est de 2.5 % selon le référentiel N°1. La politique (QSE) reste applicable.');

        $this->assertStringNotContainsString('<ol', $html);
        $this->assertStringNotContainsString('<ul', $html);
        $this->assertStringContainsString('2.5 % selon le référentiel N°1.', $html);
    }

    public function test_les_mots_colles_au_marqueur_ne_perdent_pas_leur_espace(): void
    {
        // « N°1 » s'écrivait collé : la liste des puces précédente ne doit pas
        // le rendre « N° 1 » en recollant le marqueur rejeté à son texte.
        $html = $this->html('-la procédure du processus de management N°1. c)La politique intégrée ; d)Les logigrammes');

        $this->assertStringContainsString('management N°1.', $html);
        $this->assertStringNotContainsString('N° 1.', $html);
    }

    // ------------------------------------------------------------ Sûreté

    public function test_le_texte_est_echappe(): void
    {
        $html = $this->html('<script>alert(1)</script> a) <img src=x onerror=alert(1)> ; b) la fin');

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        $this->assertStringContainsString('<li>la fin</li>', $html);
    }

    // ----------------------------------------------- Numéro et cas pratiques

    public function test_le_numero_de_la_question_reste_colle_a_l_enonce(): void
    {
        $this->assertSame(
            '<p class="qt-p"><span class="qt-index">13.</span> Cas pratiques : l&#039;entreprise AVG…</p>',
            $this->html('Cas pratiques : l\'entreprise AVG…', '13.')
        );
    }

    public function test_un_cas_pratique_suivant_commence_un_paragraphe(): void
    {
        $html = $this->html('d)Les logigrammes ne sont pas conformes ; Question 5 De la directive AVG, vous êtes désigné.');

        $this->assertStringContainsString('<p class="qt-p">Question 5 De la directive AVG, vous êtes désigné.</p>', $html);
    }

    public function test_une_instruction_apres_une_fin_de_phrase_commence_un_paragraphe(): void
    {
        $html = $this->html('Vous souhaitez changer de méthode (autre que physique). a) Indiquer et définir la nouvelle méthode');

        $this->assertStringContainsString('<p class="qt-p">a) Indiquer et définir la nouvelle méthode</p>', $html);
    }

    // ------------------------------------------------- L'énoncé réel complet

    /**
     * L'énoncé de la capture : un cas pratique de 1 500 caractères, importé d'un
     * fichier et arrivé en une seule ligne, énumérations comprises.
     */
    private function casPratique(): string
    {
        return 'Cas pratiques : L\'entreprise AVG spécialisée dans la production et vente des équipements médicaux, '
            .'a organisé une rencontre-bilan de son Système de Management Intégré (qualité, sécurité-santé au travail '
            .'et environnement) le 15 juillet 2026. Au cours de ce séminaire, des faiblesses ont été relevées au '
            .'niveau du système documentaire, à savoir : a)L\'utilisation de certains documents non à jour du système ; '
            .'b)La codification non cohérente de plusieurs documents du système ; c)La politique intégrée (QSE) ; '
            .'-le manuel du SMI (QSE) ; -le processus Management N°1 « Gérer la Qualité et les Risques » ; '
            .'-la procédure de Traitement des non-conformités N°2 « Traitement des non-conformités » du processus de '
            .'management N°1. c)La politique intégrée et le manuel du SMI ne sont pas bien élaborés ; d)Les '
            .'logigrammes décrivant l\'enchaînement des tâches de certains processus et procédures ne sont pas '
            .'conformes et harmonisés ; Question 5 De la directive AVG conscients de la quantité importante des '
            .'documents physiques à gérer, vous désigne en qualité de Responsable de l\'amélioration du Système '
            .'documentaire. Pour ce faire, vous souhaitez convaincre le comité de direction de passer à une nouvelle '
            .'méthode de gestion des documents (autre que physique). a) Indiquer et définir la nouvelle méthode pour '
            .'une gestion efficace des documents';
    }

    public function test_un_enonce_importe_d_un_seul_bloc_est_structures(): void
    {
        $html = $this->html($this->casPratique(), '13.');

        // L'introduction reste un paragraphe, avec son numéro devant.
        $this->assertStringContainsString(
            '<p class="qt-p"><span class="qt-index">13.</span> Cas pratiques : L&#039;entreprise AVG',
            $html
        );

        // Deux énumérations de lettres et une liste de puces : a, b, c puis les
        // trois documents à revoir, puis c) et d).
        $this->assertSame(2, substr_count($html, '<ol'));
        $this->assertSame(1, substr_count($html, '<ul'));
        $this->assertSame(8, substr_count($html, '<li>'));

        $this->assertStringContainsString(
            '<ol type="a" class="qt-list qt-alpha"><li>L&#039;utilisation de certains documents non à jour du système</li>',
            $html
        );
        $this->assertStringContainsString(
            '<ul class="qt-list qt-bullets"><li>le manuel du SMI (QSE)</li><li>le processus Management N°1',
            $html
        );
        $this->assertStringContainsString('<p class="qt-p">Question 5 De la directive AVG conscients', $html);
        $this->assertStringContainsString('<p class="qt-p">a) Indiquer et définir la nouvelle méthode', $html);

        // Les « ; » qui séparaient les éléments ont disparu avec la liste.
        $this->assertStringNotContainsString('documentaire ; ', $html);
        $this->assertStringNotContainsString('système ;', $html);
    }

    public function test_le_meme_enonce_sans_marqueur_reste_intact(): void
    {
        // Sans énumération, la mise en forme ne touche à rien : le texte est
        // seulement échappé. C'est la garantie qu'un cas imprévu ne déforme pas
        // un énoncé existant.
        $text = 'Expliquez la saponification en deux ou trois lignes, sans oublier les produits formés.';

        $this->assertSame('<p class="qt-p">'.$text.'</p>', $this->html($text));
    }
}
