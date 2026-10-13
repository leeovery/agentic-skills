<?php

declare(strict_types=1);

use Tests\TenantTestCase;
use Tests\TestCase;

assign_test_cases('Feature', 'Unit');

// Pest throws when a directory and a directory inside it are given different test cases,
// so each file is assigned its own: TenantTestCase under any Tenanted/ folder, TestCase elsewhere.
function assign_test_cases(string ...$directories): void
{
    foreach ($directories as $directory) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__.'/'.$directory, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $testCase = str_contains($file->getPathname(), '/Tenanted/') ? TenantTestCase::class : TestCase::class;

            pest()->extend($testCase)->in($file->getPathname());
        }
    }
}
