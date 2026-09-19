<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Réconcilie la table `migrations` avec un schéma créé hors de Laravel.
 *
 * Le schéma de production a été créé par `database/setup.sql` ou importé
 * depuis un autre outil : les tables existent, mais la table `migrations`
 * ne contient rien. Laravel croit donc que les douze migrations sont à
 * jouer, et chacune échoue sur « table already exists » ou « duplicate
 * column » — ce qui laisse la mise à jour éternellement « en attente ».
 *
 * Cette classe observe le schéma réel pour décider, migration par migration,
 * si son effet est déjà présent :
 *
 *   create_<table>_table              → la table existe ?
 *   add_<colonne>_to_<table>_table    → la colonne existe ?
 *   update_<colonne>_enum_in_<table>_table
 *                                     → la colonne accepte déjà les valeurs
 *                                       déclarées par la migration ?
 *   make_<colonne>_nullable[…]        → la colonne est déjà nullable ?
 *
 * Seules les migrations dont l'effet est constaté sont marquées comme
 * appliquées. Les autres restent en attente et sont réellement jouées par
 * `migrate` : une colonne absente est ajoutée, une colonne déjà là ne
 * provoque aucune erreur.
 */
class MigrationReconciliation
{
    /**
     * Contenu des fichiers de migration, mis en cache à la première lecture.
     *
     * @var array<string, string|null>
     */
    private array $sources = [];

    /**
     * Toutes les migrations présentes dans `database/migrations`.
     *
     * @return array<int, string>
     */
    public function files(): array
    {
        $files = glob(database_path('migrations/*.php')) ?: [];

        return array_values(collect($files)
            ->map(fn (string $path): string => basename($path, '.php'))
            ->sort()
            ->all());
    }

    /**
     * Migrations déjà enregistrées comme jouées.
     *
     * @return array<int, string>
     */
    public function recorded(): array
    {
        return DB::table('migrations')->pluck('migration')->all();
    }

    /**
     * Migrations qui doivent réellement être jouées : absentes de la table
     * ET dont l'effet n'est pas déjà visible dans le schéma.
     *
     * @return array<int, string>
     */
    public function pending(): array
    {
        return array_values(array_filter(
            $this->unrecorded(),
            fn (string $migration): bool => ! $this->isSatisfied($migration)
        ));
    }

    /**
     * Migrations non enregistrées dont l'effet est pourtant déjà en base.
     *
     * @return array<int, string>
     */
    public function satisfiable(): array
    {
        return array_values(array_filter(
            $this->unrecorded(),
            fn (string $migration): bool => $this->isSatisfied($migration)
        ));
    }

    /**
     * Marque comme appliquées les migrations dont l'effet est déjà en base.
     *
     * @return int Nombre de migrations enregistrées.
     */
    public function reconcile(): int
    {
        $satisfiable = $this->satisfiable();

        if ($satisfiable === []) {
            return 0;
        }

        // La table `migrations` de Laravel ne contient que `migration` et
        // `batch` : y insérer une colonne `created_at` ferait échouer tout le
        // mécanisme (Unknown column), ce qui laissait les migrations « en
        // attente » indéfiniment.
        DB::table('migrations')->insert(
            array_map(
                fn (string $migration): array => ['migration' => $migration, 'batch' => 1],
                $satisfiable
            )
        );

        return count($satisfiable);
    }

    /**
     * L'effet de cette migration est-il déjà présent dans le schéma ?
     */
    public function isSatisfied(string $migration): bool
    {
        if (preg_match('/_create_(\w+)_table$/', $migration, $matches) === 1) {
            return Schema::hasTable($matches[1]);
        }

        if (preg_match('/_add_(\w+)_to_(\w+)_table$/', $migration, $matches) === 1) {
            return Schema::hasColumn($matches[2], $matches[1]);
        }

        if (preg_match('/_update_(\w+)_enum_in_(\w+)_table$/', $migration, $matches) === 1) {
            return $this->enumAlreadyAccepts($matches[2], $matches[1], $migration);
        }

        if (preg_match('/_make_(\w+)_nullable/', $migration, $matches) === 1) {
            $table = $this->tableFromSource($migration);

            return $table !== null && $this->columnIsNullable($table, $matches[1]);
        }

        // Migration inconnue : on ne devine rien, elle sera jouée.
        return false;
    }

    /**
     * @return array<int, string>
     */
    private function unrecorded(): array
    {
        return array_values(array_diff($this->files(), $this->recorded()));
    }

    /**
     * La colonne accepte-t-elle déjà toutes les valeurs que la migration
     * déclare ? Seul MySQL expose une définition de type exploitable
     * (`enum('text','email',…)`) : ailleurs on répond « non », la migration
     * étant de toute façon idempotente.
     */
    private function enumAlreadyAccepts(string $table, string $column, string $migration): bool
    {
        $values = $this->enumValuesFromSource($migration, $column);

        if ($values === []) {
            return false;
        }

        $definition = strtolower($this->columnDefinition($table, $column));

        if ($definition === '') {
            return false;
        }

        foreach ($values as $value) {
            if (! str_contains($definition, "'".strtolower($value)."'")) {
                return false;
            }
        }

        return true;
    }

    private function columnIsNullable(string $table, string $column): bool
    {
        foreach ($this->columns($table) as $definition) {
            if (($definition['name'] ?? null) === $column) {
                return (bool) ($definition['nullable'] ?? false);
            }
        }

        return false;
    }

    private function columnDefinition(string $table, string $column): string
    {
        foreach ($this->columns($table) as $definition) {
            if (($definition['name'] ?? null) === $column) {
                return trim(((string) ($definition['type'] ?? '')).' '.((string) ($definition['type_name'] ?? '')));
            }
        }

        return '';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function columns(string $table): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        try {
            return Schema::getColumns($table);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Extrait les valeurs d'un `->enum('colonne', ['a', 'b'])` du fichier.
     *
     * @return array<int, string>
     */
    private function enumValuesFromSource(string $migration, string $column): array
    {
        $source = $this->source($migration);

        if ($source === null) {
            return [];
        }

        $pattern = '/->enum\(\s*[\'"]'.preg_quote($column, '/').'[\'"]\s*,\s*\[([^\]]*)\]/s';

        if (preg_match($pattern, $source, $matches) !== 1) {
            return [];
        }

        preg_match_all('/[\'"]([^\'"]+)[\'"]/', $matches[1], $values);

        return $values[1] ?? [];
    }

    /**
     * Table ciblée par un `Schema::table('…')` du fichier de migration.
     */
    private function tableFromSource(string $migration): ?string
    {
        $source = $this->source($migration);

        if ($source === null) {
            return null;
        }

        if (preg_match('/Schema::table\(\s*[\'"]([^\'"]+)[\'"]/', $source, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function source(string $migration): ?string
    {
        if (! array_key_exists($migration, $this->sources)) {
            $path = database_path('migrations/'.$migration.'.php');

            $this->sources[$migration] = is_file($path)
                ? (string) file_get_contents($path)
                : null;
        }

        return $this->sources[$migration];
    }
}
