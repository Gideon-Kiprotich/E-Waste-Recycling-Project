<?php

namespace Tests\Feature;

use Tests\TestCase;

class WelcomeTest extends TestCase
{
    public function test_the_project_entry_routes_to_sign_in(): void
    {
        $this->withoutVite()
            ->get('/')
            ->assertRedirect('/login');
    }
}
