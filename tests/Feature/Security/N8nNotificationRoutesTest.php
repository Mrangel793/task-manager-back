<?php

namespace Tests\Feature\Security;

use App\Models\Notification;
use App\Models\Organization;
use App\Models\User;
use Tests\TestCase;

class N8nNotificationRoutesTest extends TestCase
{
    private const SECRET = 's3cr3t-valido';

    protected function setUp(): void
    {
        parent::setUp();
        config(['n8n.webhook_secret' => self::SECRET]);
    }

    private function createPendingWhatsApp(Organization $org, User $user): Notification
    {
        return Notification::withoutGlobalScopes()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'task_id' => null,
            'channel' => 'whatsapp',
            'type' => 'task_assigned',
            'title' => 'Notificacion org B',
            'message' => 'Mensaje privado de la org B',
            'data' => ['phone' => '+573001234567'],
            'status' => 'pending',
        ]);
    }

    private function bearerFor(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    public function test_operador_con_bearer_no_lee_pending_whatsapp(): void
    {
        $orgA = $this->createOrganization();
        $orgB = $this->createOrganization();
        $operadorA = $this->createUser($orgA, 'Operador');
        $this->createPendingWhatsApp($orgB, $this->createUser($orgB, 'Operador'));

        $this->getJson('/api/v1/notifications/pending-whatsapp', $this->bearerFor($operadorA))
            ->assertStatus(401)
            ->assertDontSee('Mensaje privado de la org B');
    }

    public function test_operador_con_bearer_no_cambia_estado_de_notificacion(): void
    {
        $orgA = $this->createOrganization();
        $orgB = $this->createOrganization();
        $operadorA = $this->createUser($orgA, 'Operador');
        $notification = $this->createPendingWhatsApp($orgB, $this->createUser($orgB, 'Operador'));

        $this->patchJson(
            "/api/v1/notifications/{$notification->id}/status",
            ['status' => 'sent'],
            $this->bearerFor($operadorA)
        )->assertStatus(401);

        $this->assertSame('pending', Notification::withoutGlobalScopes()->find($notification->id)->status);
    }

    public function test_n8n_con_secret_lee_pending_whatsapp(): void
    {
        $org = $this->createOrganization();
        $this->createPendingWhatsApp($org, $this->createUser($org, 'Operador'));

        $this->getJson('/api/v1/notifications/pending-whatsapp', ['X-Webhook-Secret' => self::SECRET])
            ->assertStatus(200)
            ->assertJsonPath('data.count', 1);
    }

    public function test_n8n_con_secret_cambia_estado(): void
    {
        $org = $this->createOrganization();
        $notification = $this->createPendingWhatsApp($org, $this->createUser($org, 'Operador'));

        $this->patchJson(
            "/api/v1/notifications/{$notification->id}/status",
            ['status' => 'sent'],
            ['X-Webhook-Secret' => self::SECRET]
        )->assertStatus(200);

        $this->assertSame('sent', Notification::withoutGlobalScopes()->find($notification->id)->status);
    }

    public function test_comandos_whatsapp_siguen_aceptando_bearer(): void
    {
        $org = $this->createOrganization();
        $operador = $this->createUser($org, 'Operador');

        $this->postJson('/api/v1/whatsapp/commands/help', [], $this->bearerFor($operador))
            ->assertStatus(200);
    }
}
