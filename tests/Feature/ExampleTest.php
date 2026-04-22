<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_la_aplicacion_responde_en_rutas_de_auth(): void
    {
        // 422 = la ruta existe y valida (no 404)
        $this->postJson('/api/v1/auth/login', [])->assertStatus(422);
    }
}
