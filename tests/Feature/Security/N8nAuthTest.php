<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

class N8nAuthTest extends TestCase
{
    private const ROUTE = '/api/v1/whatsapp/commands/help';

    public function test_sin_secret_configurado_rechaza_peticion_sin_cabeceras(): void
    {
        config(['n8n.webhook_secret' => null]);

        $this->postJson(self::ROUTE)
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    public function test_secret_vacio_rechaza_aunque_envien_cabecera_vacia(): void
    {
        config(['n8n.webhook_secret' => '']);

        $this->postJson(self::ROUTE, [], ['X-Webhook-Secret' => ''])
            ->assertStatus(401);
    }

    public function test_secret_correcto_pasa(): void
    {
        config(['n8n.webhook_secret' => 's3cr3t-valido']);

        $this->postJson(self::ROUTE, [], ['X-Webhook-Secret' => 's3cr3t-valido'])
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_secret_incorrecto_es_rechazado(): void
    {
        config(['n8n.webhook_secret' => 's3cr3t-valido']);

        $this->postJson(self::ROUTE, [], ['X-Webhook-Secret' => 'otro-valor'])
            ->assertStatus(401);
    }

    public function test_secret_configurado_sin_cabecera_es_rechazado(): void
    {
        config(['n8n.webhook_secret' => 's3cr3t-valido']);

        $this->postJson(self::ROUTE)
            ->assertStatus(401);
    }

    public function test_bearer_token_valido_sigue_funcionando(): void
    {
        config(['n8n.webhook_secret' => null]);

        $org = $this->createOrganization();
        $user = $this->createUser($org, 'Operador');
        $token = $user->createToken('test')->plainTextToken;

        $this->postJson(self::ROUTE, [], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(200);
    }
}
