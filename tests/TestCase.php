<?php

namespace Tests;

use App\Ai\Support\SecretRedactor;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Tests praten nooit met echte externe diensten (AI-provider, Gotenberg, PDOK).
        Http::preventStrayRequests();
        SecretRedactor::flush();
    }
}
