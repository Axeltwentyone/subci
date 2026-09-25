<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Aucun appel réseau réel pendant les tests.
        \Illuminate\Support\Facades\Http::preventStrayRequests();
    }

    //
}
