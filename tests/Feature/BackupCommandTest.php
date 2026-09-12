<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackupCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Clean slate before each test: the "local" disk points at the real
        // storage/app tree, and earlier runs (manual or previous tests) may
        // have left backups behind.
        Storage::disk('local')->deleteDirectory('backups');
    }

    protected function tearDown(): void
    {
        // Never leak generated backups between tests.
        Storage::disk('local')->deleteDirectory('backups');

        parent::tearDown();
    }

    public function test_backup_run_creates_sql_and_zip_pair(): void
    {
        $this->artisan('backup:run')->assertSuccessful();

        $files = Storage::disk('local')->files('backups');
        $sql = array_filter($files, fn (string $file) => str_ends_with($file, '.sql'));
        $zip = array_filter($files, fn (string $file) => str_ends_with($file, '.zip'));

        $this->assertCount(1, $sql);
        $this->assertCount(1, $zip);

        // Both halves share the same timestamp stem.
        $this->assertSame(
            Str::before(basename(array_values($sql)[0]), '.'),
            Str::before(basename(array_values($zip)[0]), '.')
        );
    }

    public function test_backup_sql_contains_schema_and_data(): void
    {
        $this->artisan('backup:run');

        $sql = array_values(array_filter(
            Storage::disk('local')->files('backups'),
            fn (string $file) => str_ends_with($file, '.sql')
        ))[0];

        $content = Storage::disk('local')->get($sql);

        $this->assertStringContainsString('CREATE TABLE', $content);
        $this->assertStringContainsString('admin_users', $content);
        $this->assertStringContainsString('INSERT INTO', $content);
        // MySQL-only pragmas must not leak into a SQLite dump.
        $this->assertStringNotContainsString('SET FOREIGN_KEY_CHECKS', $content);
    }

    public function test_backup_zip_contains_student_submissions(): void
    {
        Storage::disk('local')->put('submissions/9/1/test_backup.pdf', '%PDF-1.4 test');

        $this->artisan('backup:run');

        $zipPath = array_values(array_filter(
            Storage::disk('local')->files('backups'),
            fn (string $file) => str_ends_with($file, '.zip')
        ))[0];

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path($zipPath)) === true);
        $this->assertNotFalse($zip->locateName('submissions/9/1/test_backup.pdf'));
        $zip->close();
    }

    public function test_backup_clean_keeps_only_the_newest_pairs(): void
    {
        $disk = Storage::disk('local');

        // Three pairs with distinct, ordered timestamps.
        foreach (['20260101_010101', '20260102_010101', '20260103_010101'] as $ts) {
            $disk->put("backups/backup_{$ts}.sql", "-- {$ts}");
            $disk->put("backups/backup_{$ts}.zip", 'zip');
        }

        $this->artisan('backup:clean', ['--keep' => 2])->assertSuccessful();

        $remaining = collect($disk->files('backups'))->map(fn (string $f) => basename($f))->sort()->values();

        $this->assertSame([
            'backup_20260102_010101.sql',
            'backup_20260102_010101.zip',
            'backup_20260103_010101.sql',
            'backup_20260103_010101.zip',
        ], $remaining->all());
    }

    public function test_backup_clean_ignores_foreign_files(): void
    {
        $disk = Storage::disk('local');
        $disk->put('backups/not-a-backup.sql', 'x');
        $disk->put('backups/notes.txt', 'y');

        $this->artisan('backup:clean')->assertSuccessful();

        // Nothing matching the backup_ pattern existed: nothing was deleted.
        $this->assertTrue($disk->exists('backups/not-a-backup.sql'));
        $this->assertTrue($disk->exists('backups/notes.txt'));
    }
}
