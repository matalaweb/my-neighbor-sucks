<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
| Feature and Integration tests run against MySQL 8.4 (production-equivalent);
| see phpunit.xml. Integration tests that need committed data across
| connections (concurrency, real S3) opt out of RefreshDatabase and manage
| their own cleanup.
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Integration');

pest()->extend(TestCase::class)
    ->in('Unit');

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Browser');

pest()->browser()->timeout(10000);
