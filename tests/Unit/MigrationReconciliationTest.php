<?php

namespace Tests\Unit;

use App\Support\MigrationReconciliation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * La réconciliation décide quelles migrations doivent réellement être jouées
 * quand la base a été créée hors de Laravel (setup.sql) : la table
 * `migrations` est vide alors que le schéma existe déjà.
 */
class MigrationReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private function forget(string ...$migrations): void
    {
        DB::table('migrations')->whereIn('migration', $migrations)->delete();
    }

    private function reconciliation(): MigrationReconciliation
    {
        return app(MigrationReconciliation::class);
    }

    public function test_a_create_table_migration_is_satisfied_when_the_table_exists(): void
    {
        $this->forget('2024_01_01_000001_create_admin_users_table');

        $reconciliation = $this->reconciliation();

        $this->assertTrue($reconciliation->isSatisfied('2024_01_01_000001_create_admin_users_table'));
        $this->assertContains('2024_01_01_000001_create_admin_users_table', $reconciliation->satisfiable());
        $this->assertNotContains('2024_01_01_000001_create_admin_users_table', $reconciliation->pending());
    }

    public function test_an_add_column_migration_is_satisfied_when_the_column_exists(): void
    {
        $this->forget('2026_09_09_000001_add_field_label_to_submission_files_table');

        $this->assertTrue(
            $this->reconciliation()->isSatisfied('2026_09_09_000001_add_field_label_to_submission_files_table')
        );
    }

    public function test_a_make_nullable_migration_is_satisfied_when_the_column_is_already_nullable(): void
    {
        // C'est exactement le cas de la production : student_email est créée
        // NULL par setup.sql, la migration n'a donc rien à modifier.
        $this->forget('2026_09_12_000001_make_student_email_nullable_and_drop_unique');

        $this->assertTrue(
            $this->reconciliation()->isSatisfied('2026_09_12_000001_make_student_email_nullable_and_drop_unique')
        );
    }

    public function test_a_migration_whose_effect_is_absent_is_not_satisfied(): void
    {
        $reconciliation = $this->reconciliation();

        $this->assertFalse($reconciliation->isSatisfied('2099_01_01_000000_create_ghost_table'));
        $this->assertFalse($reconciliation->isSatisfied('2099_01_01_000000_add_ghost_column_to_forms_table'));
    }

    public function test_reconcile_records_satisfied_migrations_only_once(): void
    {
        $this->forget(
            '2024_01_01_000001_create_admin_users_table',
            '2024_01_01_000005_create_submission_files_table',
        );

        $this->assertSame(2, $this->reconciliation()->reconcile());

        $recorded = DB::table('migrations')->pluck('migration')->all();
        $this->assertContains('2024_01_01_000001_create_admin_users_table', $recorded);
        $this->assertContains('2024_01_01_000005_create_submission_files_table', $recorded);

        // Deuxième passage : plus rien à enregistrer.
        $this->assertSame(0, $this->reconciliation()->reconcile());
    }

    public function test_reconcile_writes_only_the_columns_laravel_created(): void
    {
        // La table `migrations` ne contient que (id, migration, batch) :
        // insérer une colonne `created_at` ferait échouer silencieusement
        // toute la synchronisation, laissant les migrations « en attente »
        // pour toujours. Ce test verrouille ce comportement.
        $this->assertSame(
            ['id', 'migration', 'batch'],
            Schema::getColumnListing('migrations')
        );

        $this->forget('2024_01_01_000002_create_forms_table');

        $this->assertSame(1, $this->reconciliation()->reconcile());
    }

    public function test_every_migration_is_accounted_for_after_a_full_install(): void
    {
        $reconciliation = $this->reconciliation();

        $this->assertSame([], $reconciliation->satisfiable());
        $this->assertSame([], $reconciliation->pending());
    }
}
