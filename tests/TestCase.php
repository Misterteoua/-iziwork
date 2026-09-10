<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        // phpunit.xml's <env> entries cannot override variables that are
        // already present in the real environment (some shells export e.g.
        // DB_DATABASE pointing at the real SQLite file, APP_ENV=local or a
        // Windows-specific SESSION_PATH). If those leak through, RefreshDatabase
        // would run migrate:fresh against the real database and wipe its data.
        // Force the test-safe values in every source Laravel's Env repository
        // reads from ($_ENV, $_SERVER and getenv), before the app boots.
        foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'SESSION_DRIVER' => 'array', 'SESSION_PATH' => '/'] as $key => $value) {
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv("$key=$value");
        }

        parent::setUp();
    }
}