<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * L'assistant de mise à jour doit dire la vérité : une base créée par
 * setup.sql ne doit plus afficher douze migrations « en attente » alors que
 * le schéma est déjà complet.
 */
class UpdateControllerTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'jeton-de-test-1234567890';

    private ?string $previousToken = null;

    protected function setUp(): void
    {
        parent::setUp();

        $path = base_path('.update-token');
        $this->previousToken = is_file($path) ? (string) file_get_contents($path) : null;

        file_put_contents($path, self::TOKEN);
    }

    protected function tearDown(): void
    {
        $path = base_path('.update-token');

        if ($this->previousToken === null) {
            @unlink($path);
        } else {
            file_put_contents($path, $this->previousToken);
        }

        parent::tearDown();
    }

    public function test_the_page_refuses_an_invalid_token(): void
    {
        $this->get('/update?token=mauvais-jeton')->assertForbidden();
    }

    public function test_the_page_reports_everything_up_to_date_on_a_complete_install(): void
    {
        $this->get('/update?token='.self::TOKEN)
            ->assertOk()
            ->assertSee('Tout est à jour');
    }

    public function test_the_page_separates_migrations_already_present_in_the_database(): void
    {
        DB::table('migrations')->whereIn('migration', [
            '2024_01_01_000001_create_admin_users_table',
            '2024_01_01_000002_create_forms_table',
        ])->delete();

        $response = $this->get('/update?token='.self::TOKEN)->assertOk();

        // Ces migrations ne sont plus annoncées comme « à jouer » : leur
        // effet est déjà en base, elles seront simplement enregistrées.
        $response->assertSee('déjà en place dans la base');
        $response->assertDontSee('migration(s) à jouer');
        $response->assertDontSee('Tout est à jour');
    }

    public function test_the_page_lists_a_migration_whose_effect_cannot_be_confirmed(): void
    {
        // La migration d'énumération ne peut pas être vérifiée sur SQLite
        // (la colonne n'est pas un vrai ENUM) : elle reste donc à jouer,
        // ce qui est le comportement voulu.
        DB::table('migrations')
            ->where('migration', '2026_09_06_183939_update_field_type_enum_in_form_fields_table')
            ->delete();

        $this->get('/update?token='.self::TOKEN)
            ->assertOk()
            ->assertSee('migration(s) à jouer')
            ->assertSee('2026_09_06_183939_update_field_type_enum_in_form_fields_table');
    }
}
