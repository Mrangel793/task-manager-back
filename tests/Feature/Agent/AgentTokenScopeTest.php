<?php

namespace Tests\Feature\Agent;

use App\Models\Contact;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AgentTokenScopeTest extends TestCase
{
    private Organization $org;

    private User $admin;

    private User $operador;

    protected function setUp(): void
    {
        parent::setUp();
        config(['agent.hmac_secret' => 'test-agent-secret']);

        $this->org = $this->createOrganization();
        $this->admin = $this->createUser($this->org, 'Admin', [
            'whatsapp_phone' => '+573001110000', 'whatsapp_verified' => true,
        ]);
        $this->operador = $this->createUser($this->org, 'Operador', [
            'whatsapp_phone' => '+573002220000', 'whatsapp_verified' => true,
        ]);
    }

    private function agentToken(User $user): string
    {
        return $user->createToken(User::AGENT_TOKEN_NAME, ['agent'], now()->addMinutes(15))->plainTextToken;
    }

    private function as(string $token, string $method, string $uri, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->json($method, $uri, $data);
    }

    // ------------------------------------------------- lista blanca: rutas peligrosas

    public static function rutasPeligrosas(): array
    {
        return [
            'PATCH users/{propio} password' => ['operador', 'PATCH', 'users/{self}', ['password' => 'Nuevo1234', 'password_confirmation' => 'Nuevo1234']],
            'PATCH auth/me email' => ['operador', 'PATCH', 'auth/me', ['email' => 'atacante@evil.com']],
            'PATCH users/{otro} password (Admin)' => ['admin', 'PATCH', 'users/{other}', ['password' => 'Nuevo1234', 'password_confirmation' => 'Nuevo1234']],
            'POST users crea Admin (Admin)' => ['admin', 'POST', 'users', ['name' => 'X', 'email' => 'x@x.com', 'password' => 'Password1', 'password_confirmation' => 'Password1', 'role' => 'Admin']],
            'DELETE users/{otro} (Admin)' => ['admin', 'DELETE', 'users/{other}', []],
            'GET contacts/export (Admin)' => ['admin', 'GET', 'contacts/export', []],
            'POST contacts/grant-access (Admin)' => ['admin', 'POST', 'contacts/grant-access', ['user_id' => '{other}']],
            'GET google-calendar/connect' => ['admin', 'GET', 'google-calendar/connect', []],
            'POST auth/refresh-token' => ['operador', 'POST', 'auth/refresh-token', []],
            'POST auth/change-password' => ['operador', 'POST', 'auth/change-password', ['current_password' => 'Password1', 'password' => 'Password2', 'password_confirmation' => 'Password2']],
            'POST me/whatsapp-link-code' => ['operador', 'POST', 'me/whatsapp-link-code', ['phone' => '+573009990000']],
            'DELETE me/whatsapp' => ['operador', 'DELETE', 'me/whatsapp', []],
            'POST auth/logout' => ['operador', 'POST', 'auth/logout', []],
            'GET users' => ['admin', 'GET', 'users', []],
            'DELETE projects/{id} (Admin)' => ['admin', 'DELETE', 'projects/{project}', []],
            'DELETE contacts/{id} (Admin)' => ['admin', 'DELETE', 'contacts/{contact}', []],
            'POST push/subscribe' => ['operador', 'POST', 'push/subscribe', []],
            'POST tabs' => ['operador', 'POST', 'tabs', ['name' => 'x']],
            'POST notifications/mark-all-read' => ['operador', 'POST', 'notifications/mark-all-read', []],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rutasPeligrosas')]
    public function test_token_de_agente_no_puede_usar_rutas_fuera_de_la_lista_blanca(string $who, string $method, string $uri, array $data): void
    {
        $actor = $who === 'admin' ? $this->admin : $this->operador;
        $other = $who === 'admin' ? $this->operador : $this->admin;
        $project = Project::create(['organization_id' => $this->org->id, 'name' => 'Proyecto', 'created_by' => $this->admin->id]);
        $contact = Contact::create(['organization_id' => $this->org->id, 'name' => 'Contacto']);

        $replace = ['{self}' => $actor->id, '{other}' => $other->id, '{project}' => $project->id, '{contact}' => $contact->id];
        $uri = strtr($uri, $replace);
        $data = array_map(fn ($v) => is_string($v) ? strtr($v, $replace) : $v, $data);
        $before = User::withoutGlobalScopes()->findOrFail($actor->id)->only(['email', 'password']);

        $this->as($this->agentToken($actor), $method, "/api/v1/{$uri}", $data)
            ->assertStatus(403)
            ->assertJsonPath('message', 'Esta acción no está permitida con un token de agente.');

        $this->assertSame($before, User::withoutGlobalScopes()->findOrFail($actor->id)->only(['email', 'password']));
        $this->assertSame(2, User::withoutGlobalScopes()->where('organization_id', $this->org->id)->count());
    }

    public function test_token_normal_sigue_pudiendo_usar_rutas_fuera_de_la_lista(): void
    {
        $token = $this->operador->createToken('auth_token')->plainTextToken;

        $this->as($token, 'PATCH', '/api/v1/auth/me', ['name' => 'Nombre Nuevo'])->assertStatus(200);
        $this->as($token, 'POST', '/api/v1/me/whatsapp-link-code', ['phone' => '+573009990000'])->assertStatus(201);
    }

    // ------------------------------------------------- lista blanca: rutas permitidas

    public function test_token_de_agente_puede_usar_las_rutas_permitidas(): void
    {
        $token = $this->agentToken($this->admin);
        $task = $this->createTask($this->org, $this->admin, $this->operador);
        $project = Project::create(['organization_id' => $this->org->id, 'name' => 'Proyecto', 'created_by' => $this->admin->id]);
        $contact = Contact::create(['organization_id' => $this->org->id, 'name' => 'Contacto']);

        $this->as($token, 'GET', '/api/v1/auth/me')->assertStatus(200);

        $this->as($token, 'GET', '/api/v1/tasks')->assertStatus(200);
        $this->as($token, 'GET', "/api/v1/tasks/{$task->id}")->assertStatus(200);
        $this->as($token, 'POST', '/api/v1/tasks', ['title' => 'Creada por el agente', 'assignee_id' => $this->operador->id])->assertStatus(201);
        $this->as($token, 'PATCH', "/api/v1/tasks/{$task->id}", ['title' => 'Titulo editado'])->assertStatus(200);
        $this->as($token, 'GET', "/api/v1/tasks/{$task->id}/history")->assertStatus(200);
        $this->as($token, 'GET', "/api/v1/tasks/{$task->id}/comments")->assertStatus(200);
        $this->as($token, 'POST', "/api/v1/tasks/{$task->id}/comments", ['body' => 'Comentario'])->assertStatus(201);

        $this->as($token, 'GET', '/api/v1/projects')->assertStatus(200);
        $this->as($token, 'GET', '/api/v1/projects/summary')->assertStatus(200);
        $this->as($token, 'POST', '/api/v1/projects', ['name' => 'Proyecto del agente'])->assertStatus(201);
        $this->as($token, 'GET', "/api/v1/projects/{$project->id}")->assertStatus(200);
        $this->as($token, 'GET', "/api/v1/projects/{$project->id}/tasks")->assertStatus(200);
        $this->as($token, 'GET', "/api/v1/projects/{$project->id}/members")->assertStatus(200);
        $this->as($token, 'POST', "/api/v1/projects/{$project->id}/members", ['user_id' => $this->operador->id])->assertSuccessful();

        $this->as($token, 'GET', '/api/v1/contacts')->assertStatus(200);
        $this->as($token, 'GET', "/api/v1/contacts/{$contact->id}")->assertStatus(200);
        $this->as($token, 'POST', '/api/v1/contacts', ['name' => 'Contacto del agente'])->assertStatus(201);

        $this->as($token, 'GET', '/api/v1/notifications')->assertStatus(200);
        $this->as($token, 'GET', '/api/v1/notifications/unread-count')->assertStatus(200);

        $this->as($token, 'POST', "/api/v1/tasks/{$task->id}/reassign", ['assignee_id' => $this->admin->id])
            ->assertJsonMissing(['message' => 'Esta acción no está permitida con un token de agente.']);
        $this->as($token, 'DELETE', "/api/v1/tasks/{$task->id}")->assertStatus(200);
    }

    // ------------------------------------------------- SetOrganizationContext

    public function test_usuario_inactivo_con_token_normal_recibe_403(): void
    {
        $token = $this->operador->createToken('auth_token')->plainTextToken;
        User::withoutGlobalScopes()->whereKey($this->operador->id)->update(['is_active' => false]);

        $this->as($token, 'GET', '/api/v1/tasks')->assertStatus(403);
    }

    public static function estadosQueInvalidanElTokenDeAgente(): array
    {
        return [
            'perdio whatsapp_verified' => [fn (User $u) => User::withoutGlobalScopes()->whereKey($u->id)->update(['whatsapp_verified' => false])],
            'usuario inactivo' => [fn (User $u) => User::withoutGlobalScopes()->whereKey($u->id)->update(['is_active' => false])],
            'organizacion inactiva' => [fn (User $u) => Organization::whereKey($u->organization_id)->update(['is_active' => false])],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('estadosQueInvalidanElTokenDeAgente')]
    public function test_token_de_agente_se_borra_y_responde_401(\Closure $romper): void
    {
        $token = $this->agentToken($this->operador);
        $this->as($token, 'GET', '/api/v1/tasks')->assertStatus(200);

        $romper($this->operador);

        $this->as($token, 'GET', '/api/v1/tasks')->assertStatus(401);
        $this->assertNull(PersonalAccessToken::findToken($token));
    }

    // ------------------------------------------------- revocación al cambiar el número

    public function test_admin_cambia_whatsapp_phone_y_se_revocan_tokens_de_agente(): void
    {
        $agentToken = $this->agentToken($this->operador);
        $normalToken = $this->operador->createToken('auth_token')->plainTextToken;

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/users/{$this->operador->id}", ['whatsapp_phone' => '+573007770000'])
            ->assertStatus(200);

        $this->assertNull(PersonalAccessToken::findToken($agentToken));
        $this->assertNotNull(PersonalAccessToken::findToken($normalToken));
    }

    public function test_usuario_cambia_su_whatsapp_desde_users_y_se_revocan_tokens_de_agente(): void
    {
        $agentToken = $this->agentToken($this->operador);

        $this->actingAs($this->operador, 'sanctum')
            ->patchJson("/api/v1/users/{$this->operador->id}", ['whatsapp_phone' => '+573007770000'])
            ->assertStatus(200);

        $this->assertNull(PersonalAccessToken::findToken($agentToken));
    }

    public function test_admin_quita_la_verificacion_y_se_revocan_tokens_de_agente(): void
    {
        $agentToken = $this->agentToken($this->operador);

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/users/{$this->operador->id}", ['whatsapp_verified' => false])
            ->assertStatus(200);

        $this->assertNull(PersonalAccessToken::findToken($agentToken));
    }

    // ------------------------------------------------- soft-delete

    public function test_borrar_usuario_libera_su_whatsapp_y_revoca_tokens_de_agente(): void
    {
        $agentToken = $this->agentToken($this->operador);

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/users/{$this->operador->id}")
            ->assertStatus(200);

        $deleted = User::withoutGlobalScopes()->withTrashed()->findOrFail($this->operador->id);
        $this->assertNotNull($deleted->deleted_at);
        $this->assertNull($deleted->whatsapp_phone);
        $this->assertFalse($deleted->whatsapp_verified);
        $this->assertNull($deleted->email);
        $this->assertNull(PersonalAccessToken::findToken($agentToken));

        // El número liberado puede vincularse a otro usuario.
        $nuevo = $this->createUser($this->org, 'Operador', ['whatsapp_phone' => '+573002220000']);
        $this->assertSame('+573002220000', $nuevo->whatsapp_phone);
    }
}
