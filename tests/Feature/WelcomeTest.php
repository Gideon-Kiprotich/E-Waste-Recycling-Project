<?php

namespace Tests\Feature;

use Tests\TestCase;

class WelcomeTest extends TestCase
{
    public function test_the_project_welcome_page_is_available(): void
    {
        $this->withoutVite()
            ->get('/')
            ->assertOk()
            ->assertSee('Smart E-Waste Collection, Tracking and Recycling Management Platform');
    }
}
