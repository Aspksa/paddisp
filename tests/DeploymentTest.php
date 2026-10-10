<?php

declare(strict_types=1);

namespace Tests;

final class DeploymentTest extends TestCase
{
    public function testNoNodeServerOrSecretsInRepository(): void
    {
        $this->assertTrue(!is_file(BASE_PATH . '/package.json'), 'package.json не нужен: приложение — PHP без Node.js');
        $this->assertTrue(is_file(BASE_PATH . '/public/index.php'));
        $gitignore = (string) file_get_contents(BASE_PATH . '/.gitignore');
        $this->assertContains('/.env', $gitignore);
    }

    public function testMigrationSplitterHandlesSchema(): void
    {
        $sql = (string) file_get_contents(BASE_PATH . '/database/migrations/001_initial_schema.sql');
        $this->assertSame(12, count(\App\Core\Migrator::statements($sql)));
    }
}
