<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    public $mockConsoleOutput = false;

    public function createApplication(): Application
    {
        $app = parent::createApplication();
        if (! $app->environment('testing') || ! str_ends_with(DB::connection()->getDatabaseName(), '_test')) {
            throw new \RuntimeException('A suíte de API exige ambiente testing e banco PostgreSQL dedicado com sufixo _test.');
        }
        return $app;
    }
}
