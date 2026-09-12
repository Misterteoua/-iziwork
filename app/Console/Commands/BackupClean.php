<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class BackupClean extends Command
{
    private const DISK = 'local';

    private const BACKUP_DIR = 'backups';

    protected $signature = 'backup:clean {--keep=8 : Nombre de sauvegardes à conserver}';

    protected $description = 'Supprime les sauvegardes les plus anciennes au-delà du nombre conservé';

    public function handle(): int
    {
        $keep = max(1, (int) $this->option('keep'));

        $disk = Storage::disk(self::DISK);

        // Group by timestamp: a backup is the pair base.{sql,zip}. We keep
        // the $keep most recent pairs and delete the rest.
        $groups = [];

        foreach ($disk->allFiles(self::BACKUP_DIR) as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);
            // Only pair files shaped like backup_<timestamp>.<ext>
            if (preg_match('/^backup_\d{8}_\d{6}$/', $name) === 1) {
                $groups[$name][] = $file;
            }
        }

        if ($groups === []) {
            $this->line('Aucune sauvegarde à nettoyer.');

            return self::SUCCESS;
        }

        krsort($groups); // newest timestamp first

        $stale = array_slice(array_keys($groups), $keep);

        foreach ($stale as $name) {
            foreach ($groups[$name] as $file) {
                $disk->delete($file);
                $this->line('Supprimé : '.$file);
            }
        }

        $this->info(count($groups).' sauvegardes présentes, '.count($stale).' supprimée(s) (conservées : '.$keep.').');

        return self::SUCCESS;
    }
}
