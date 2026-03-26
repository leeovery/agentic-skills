<?php

declare(strict_types=1);

use Tests\TenantTestCase;
use Tests\TestCase;

// Setup test cases based on directory structure
setupTestCases('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Helper Functions
|--------------------------------------------------------------------------
*/

function setupTestCases(string ...$baseDirs): void
{
    foreach ($baseDirs as $baseDir) {
        foreach (getAllTestFiles(__DIR__.DIRECTORY_SEPARATOR.$baseDir) as $file) {
            (match (true) {
                str_contains($file, '/Tenanted/') => fn () => pest()
                    ->extend(TenantTestCase::class)
                    ->in($file),
                str_contains($file, '/Central/') => fn () => pest()
                    ->extend(TestCase::class)
                    ->in($file),
                default => fn () => pest()
                    ->extend(TestCase::class)
                    ->in($file),
            })();
        }
    }
}

function getAllTestFiles(string $directory): array
{
    $files = [];
    $dir = new RecursiveDirectoryIterator($directory);
    $iterator = new RecursiveIteratorIterator($dir);

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    return $files;
}

// Tenant helper
function create_tenant(?string $tenantId = null, array $attributes = []): Tenant
{
    if ($tenantId && ! isset($attributes['id'])) {
        $attributes['id'] = $tenantId;
    }

    return test()->createTenant($attributes);
}
