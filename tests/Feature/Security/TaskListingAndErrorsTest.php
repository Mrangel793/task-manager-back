<?php

namespace Tests\Feature\Security;

use App\Services\TaskService;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class TaskListingAndErrorsTest extends TestCase
{
    public function test_sort_by_invalido_devuelve_422(): void
    {
        $org = $this->createOrganization();
        $admin = $this->createUser($org, 'Admin');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/tasks?sort_by=foo')
            ->assertStatus(422)
            ->assertJsonValidationErrors('sort_by');
    }

    public function test_sort_by_con_inyeccion_devuelve_422(): void
    {
        $org = $this->createOrganization();
        $admin = $this->createUser($org, 'Admin');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/tasks?sort_by='.urlencode('(select 1)'))
            ->assertStatus(422);
    }

    public function test_sort_order_invalido_devuelve_422(): void
    {
        $org = $this->createOrganization();
        $admin = $this->createUser($org, 'Admin');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/tasks?sort_by=created_at&sort_order=sideways')
            ->assertStatus(422)
            ->assertJsonValidationErrors('sort_order');
    }

    public function test_sort_by_valido_devuelve_200_y_ordena(): void
    {
        $org = $this->createOrganization();
        $admin = $this->createUser($org, 'Admin');
        $operador = $this->createUser($org, 'Operador');
        $this->createTask($org, $admin, $operador, ['title' => 'B tarea']);
        $this->createTask($org, $admin, $operador, ['title' => 'A tarea']);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/tasks?sort_by=created_at')
            ->assertStatus(200);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/tasks?sort_by=title&sort_order=asc')
            ->assertStatus(200);

        $this->assertSame(['A tarea', 'B tarea'], collect($response->json('data'))->pluck('title')->all());
    }

    public function test_error_500_no_expone_el_mensaje_de_la_excepcion(): void
    {
        $org = $this->createOrganization();
        $admin = $this->createUser($org, 'Admin');
        $operador = $this->createUser($org, 'Operador');

        $secretDetail = 'SQLSTATE[42S22]: Column not found: tabla_interna.columna_secreta';

        $this->mock(TaskService::class, function ($mock) use ($secretDetail) {
            $mock->shouldReceive('createTask')->once()->andThrow(new \RuntimeException($secretDetail));
        });

        Log::spy();

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/tasks', [
                'title' => 'Nueva tarea',
                'description' => 'Descripción',
                'due_date' => now()->addDays(5)->format('Y-m-d'),
                'due_time' => '14:00',
                'assignee_id' => $operador->id,
            ]);

        $response->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.server.0', 'Ocurrió un error inesperado. Por favor, inténtelo de nuevo.');

        $this->assertStringNotContainsString('columna_secreta', $response->getContent());
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());

        Log::shouldHaveReceived('error')
            ->with(Mockery::on(fn ($message) => str_contains($message, 'columna_secreta')), Mockery::type('array'))
            ->once();
    }
}
