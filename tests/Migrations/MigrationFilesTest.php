<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use PHPUnit\Framework\TestCase;

final class MigrationFilesTest extends TestCase
{
    public function testCoreMigrationFilesExist(): void
    {
        $root = dirname(__DIR__, 2);
        self::assertFileExists($root . '/migrations/001_create_schema_migrations.sql');
        self::assertFileExists($root . '/config/schema.sql');
    }
}
