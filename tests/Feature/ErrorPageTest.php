<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/**
 * Une erreur serveur en production doit se lire, pas rester blanche.
 *
 * Jusqu'ici, `APP_DEBUG=false` affichait la page anglaise minimale de Symfony
 * (« 500 | SERVER ERROR ») : l'enseignant ne savait ni ce qui s'était passé, ni
 * s'il pouvait réessayer, ni qui prévenir. C'est exactement ce qui a rendu le
 * bogue d'import des questions si coûteux à diagnostiquer.
 */
class ErrorPageTest extends TestCase
{
    /**
     * Une route qui échoue, comme le ferait une écriture refusée par la base.
     */
    private function failingRoute(): void
    {
        Route::get('/iziwork-panne-500', static function (): never {
            throw new RuntimeException('Panne volontaire, message technique à ne jamais montrer.');
        });
    }

    public function test_une_erreur_inattendue_affiche_une_page_en_francais(): void
    {
        // La configuration de la production.
        config(['app.debug' => false]);

        $this->failingRoute();

        $response = $this->get('/iziwork-panne-500');

        $response->assertStatus(500);
        $response->assertSee('Une erreur inattendue est survenue');
        $response->assertSee("L'incident a été enregistré", false);
        // Ni la page anglaise par défaut, ni la moindre trace technique.
        $response->assertDontSee('SERVER ERROR');
        $response->assertDontSee('Panne volontaire');
        $response->assertDontSee('RuntimeException');
    }

    public function test_en_developpement_la_page_de_diagnostic_reste_affichee(): void
    {
        // APP_DEBUG=true : la page sobre ne doit pas priver le développeur du
        // message technique, qui est justement ce qui manquait en production.
        config(['app.debug' => true]);

        $this->failingRoute();

        $response = $this->get('/iziwork-panne-500');

        $response->assertStatus(500);
        $response->assertSee('Panne volontaire');
    }
}
