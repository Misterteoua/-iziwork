<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class BackupRun extends Command
{
    /**
     * Local disk used for backup storage. Deliberately 'local': the archive
     * must land in storage/app/private (never web-accessible).
     */
    private const DISK = 'local';

    private const BACKUP_DIR = 'backups';

    protected $signature = 'backup:run';

    protected $description = 'Sauvegarde la base de données et les dépôts étudiants dans storage/app/private/backups';

    public function handle(): int
    {
        $disk = Storage::disk(self::DISK);
        $timestamp = now()->format('Ymd_His');
        $base = 'backup_'.$timestamp;

        $sqlRelative = self::BACKUP_DIR.'/'.$base.'.sql';
        $zipRelative = self::BACKUP_DIR.'/'.$base.'.zip';

        $sql = $this->dumpDatabase();
        if ($sql === null) {
            $this->error('Pilote de base de données non supporté pour la sauvegarde (sqlite et mysql uniquement).');

            return self::FAILURE;
        }

        $disk->put($sqlRelative, $sql);
        $this->line('Base exportée : '.$sqlRelative.' ('.number_format(strlen($sql) / 1024, 1).' Ko)');

        $fileCount = $this->buildSubmissionsZip($disk->path($zipRelative));
        $this->line('Dépôts étudiants archivés : '.$zipRelative.' ('.$fileCount.' fichiers)');

        $this->info("Sauvegarde terminée : {$base}.sql + {$base}.zip");

        return self::SUCCESS;
    }

    /**
     * Pure-PHP dump: portable (works on shared hosts without mysqldump in
     * PATH) and testable. Structure (CREATE TABLE) + data (INSERT rows) for
     * every table of the current connection.
     */
    private function dumpDatabase(): ?string
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        if (! in_array($driver, ['sqlite', 'mysql'], true)) {
            return null;
        }

        $out = "-- Sauvegarde Iziwork\n";
        $out .= '-- Date : '.now()->format('Y-m-d H:i:s')."\n";
        $out .= '-- Pilote : '.$driver."\n\n";

        // MySQL-specific (SQLite has no FK enforcement to suspend, and any
        // SET statement is a syntax error there).
        if ($driver === 'mysql') {
            $out .= "SET FOREIGN_KEY_CHECKS=0;\n\n";
        }

        $tables = $connection->select(
            $driver === 'sqlite'
                ? "SELECT name AS table_name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
                : 'SHOW TABLES'
        );

        foreach ($tables as $table) {
            $tableName = $driver === 'sqlite'
                ? $table->table_name
                : array_values((array) $table)[0];

            $out .= '-- Table '.$tableName."\n";

            if ($driver === 'mysql') {
                $create = $connection->selectOne("SHOW CREATE TABLE `{$tableName}`");
                $createSql = array_values((array) $create)[1] ?? null;
                $out .= 'DROP TABLE IF EXISTS `'.$tableName."`;\n";
                $out .= ($createSql ?? '-- structure indisponible').";\n\n";
            } else {
                $create = $connection->selectOne(
                    "SELECT sql FROM sqlite_master WHERE type='table' AND name = ?",
                    [$tableName]
                );
                $out .= 'DROP TABLE IF EXISTS "'.$tableName."\";\n";
                $out .= ($create->sql ?? '-- structure indisponible').";\n\n";
            }

            $rows = $connection->table($tableName)->get();
            foreach ($rows as $row) {
                $values = array_map(
                    static fn ($value) => $value === null
                        ? 'NULL'
                        : $connection->getPdo()->quote((string) $value),
                    (array) $row
                );
                $columns = array_map(
                    static fn ($column) => $driver === 'mysql' ? "`{$column}`" : '"'.$column.'"',
                    array_keys($values)
                );

                $quotedTable = $driver === 'mysql'
                    ? '`'.$tableName.'`'
                    : '"'.$tableName.'"';

                $out .= 'INSERT INTO '.$quotedTable;
                $out .= ' ('.implode(', ', $columns).') VALUES ';
                $out .= '('.implode(', ', $values).");\n";
            }

            $out .= "\n";
        }

        if ($driver === 'mysql') {
            $out .= "SET FOREIGN_KEY_CHECKS=1;\n";
        }

        return $out;
    }

    /**
     * Zip every student submission file into the backup archive.
     */
    private function buildSubmissionsZip(string $zipPath): int
    {
        $disk = Storage::disk(self::DISK);

        $zip = new ZipArchive;
        $openResult = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($openResult !== true) {
            $this->error('Impossible de créer l\'archive ZIP de sauvegarde (code '.$openResult.').');

            return 0;
        }

        $count = 0;
        foreach ($disk->allFiles('submissions') as $file) {
            $absolute = $disk->path($file);
            if (is_file($absolute)) {
                $zip->addFile($absolute, $file);
                $count++;
            }
        }

        $zip->close();

        return $count;
    }
}
