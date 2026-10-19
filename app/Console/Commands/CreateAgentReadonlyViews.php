<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Crea (o reemplaza) las vistas de solo lectura del agente en una base de datos
 * separada. Las vistas usan SQL SECURITY DEFINER y referencian la BD principal
 * con nombre calificado, así el usuario MySQL del agente sólo necesita SELECT
 * sobre la BD de vistas. Ver docs/agent-readonly.md.
 */
class CreateAgentReadonlyViews extends Command
{
    protected $signature = 'agent:create-views {--database= : BD donde crear las vistas (por defecto AGENT_RO_DATABASE)}';

    protected $description = 'Crea/reemplaza las vistas de solo lectura del agente de WhatsApp en la BD separada';

    public function handle(): int
    {
        $connection = DB::connection();

        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->error('Este comando sólo soporta MySQL/MariaDB.');

            return self::FAILURE;
        }

        $source = (string) $connection->getDatabaseName();
        $target = (string) ($this->option('database') ?: config('agent.readonly_database'));

        foreach (['principal' => $source, 'de vistas' => $target] as $label => $name) {
            if (! preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) {
                $this->error("Nombre de BD {$label} inválido o vacío: '{$name}'. Usa --database= o AGENT_RO_DATABASE.");

                return self::FAILURE;
            }
        }

        if (strcasecmp($source, $target) === 0) {
            $this->error('La BD de vistas debe ser distinta de la BD principal.');

            return self::FAILURE;
        }

        $exists = DB::select('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$target]);
        if ($exists === []) {
            $this->error("La BD '{$target}' no existe. Créala primero (en cPanel: MySQL Databases).");

            return self::FAILURE;
        }

        foreach ($this->views($source) as $view => $select) {
            $connection->statement(
                "CREATE OR REPLACE SQL SECURITY DEFINER VIEW `{$target}`.`{$view}` AS {$select}"
            );
            $this->line("  <info>OK</info> {$target}.{$view}");
        }

        $this->info('Vistas creadas en '.$target.' leyendo de '.$source.'.');

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    private function views(string $db): array
    {
        $userMorph = addslashes(User::class);

        return [
            'v_organizations' => "SELECT o.id, o.name, o.is_active, o.created_at, o.updated_at
                FROM `{$db}`.organizations o
                WHERE o.deleted_at IS NULL",

            'v_users' => "SELECT u.id, u.organization_id, u.name, u.role, u.is_active,
                    (SELECT GROUP_CONCAT(r.name ORDER BY r.name SEPARATOR ',')
                        FROM `{$db}`.model_has_roles mhr
                        JOIN `{$db}`.roles r ON r.id = mhr.role_id
                        WHERE mhr.model_id = u.id AND mhr.model_type = '{$userMorph}') AS spatie_roles,
                    u.created_at, u.updated_at
                FROM `{$db}`.users u
                WHERE u.deleted_at IS NULL",

            'v_projects' => "SELECT p.id, p.organization_id, p.name, p.description, p.status, p.color,
                    p.due_date, p.visibility, p.created_by, p.created_at, p.updated_at
                FROM `{$db}`.projects p
                WHERE p.deleted_at IS NULL",

            'v_project_members' => "SELECT pm.id, p.organization_id, pm.project_id, pm.user_id, pm.added_at
                FROM `{$db}`.project_members pm
                JOIN `{$db}`.projects p ON p.id = pm.project_id
                WHERE p.deleted_at IS NULL",

            'v_tasks' => "SELECT t.id, t.organization_id, t.project_id, t.assignee_id, t.creator_id,
                    t.status, t.title, t.description, t.due_date, t.due_time,
                    t.started_at, t.completed_at, t.created_at, t.updated_at
                FROM `{$db}`.tasks t
                WHERE t.deleted_at IS NULL",

            'v_task_comments' => "SELECT c.id, c.organization_id, c.task_id, c.user_id, c.body,
                    (c.attachment_path IS NOT NULL) AS has_attachment, c.created_at, c.updated_at
                FROM `{$db}`.task_comments c
                JOIN `{$db}`.tasks t ON t.id = c.task_id
                WHERE t.deleted_at IS NULL",

            'v_task_histories' => "SELECT h.id, h.organization_id, h.task_id, h.user_id, h.action,
                    h.from_value, h.to_value, h.created_at
                FROM `{$db}`.task_histories h",

            'v_contacts' => "SELECT ct.id, ct.organization_id, ct.name, ct.source, ct.notes,
                    CASE WHEN ct.phone IS NULL OR ct.phone = '' THEN NULL
                        ELSE RIGHT(REGEXP_REPLACE(ct.phone, '[^0-9]', ''), 4) END AS phone_last4,
                    CASE WHEN ct.email IS NULL OR LOCATE('@', ct.email) = 0 THEN NULL
                        ELSE CONCAT(LEFT(ct.email, 1), '***', SUBSTRING(ct.email, LOCATE('@', ct.email))) END AS email_masked,
                    ct.created_by, ct.created_at, ct.updated_at
                FROM `{$db}`.contacts ct",
        ];
    }
}
