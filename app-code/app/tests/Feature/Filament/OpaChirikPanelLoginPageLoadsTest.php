<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use Tests\TestCase;

class OpaChirikPanelLoginPageLoadsTest extends TestCase
{
    public function test_opa_chirik_login_page_loads_without_server_error(): void
    {
        $response = $this->get('/opa-chirik/login');

        $response->assertOk();
    }
}
