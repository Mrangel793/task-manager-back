# Vistas de solo lectura para el agente de WhatsApp (MariaDB / cPanel)

El agente autónomo (servicio Python aparte) lee datos directamente por SQL a través de
**vistas** que viven en una base de datos **separada**. El usuario MySQL del agente sólo
tiene `SELECT` sobre esa BD de vistas y **no** puede leer las tablas originales.

## Por qué una BD separada

En cPanel los privilegios se asignan por **base de datos completa** (no hay `GRANT` por
tabla ni por columna). Si el agente tuviera `SELECT` sobre la BD principal leería
`users.password`, tokens de Google, `activation_token`, etc. Por eso:

- BD principal: `<cpaneluser>_taskmanager` (la de la app, `DB_DATABASE`).
- BD de vistas: `<cpaneluser>_agentro` (configurable con `AGENT_RO_DATABASE`).
- Las vistas se crean con `SQL SECURITY DEFINER` y referencian la BD principal con nombre
  calificado (`` `<bd_principal>`.tasks ``). Al consultar una vista, MariaDB usa los
  privilegios del **definer** (el usuario que corrió el comando) para leer las tablas, y
  los del **invocador** (el agente) sólo para la vista.

## Vistas

| Vista | Columnas | Filtro |
|-------|----------|--------|
| `v_organizations` | id, name, is_active, created_at, updated_at | `deleted_at IS NULL`. **Sin** `invite_code` ni `slug` |
| `v_users` | id, organization_id, name, role (columna), is_active, spatie_roles (CSV de roles spatie), created_at, updated_at | `deleted_at IS NULL`. **Sin** phone, whatsapp_phone, email, password, google_*_token, activation_token |
| `v_projects` | id, organization_id, name, description, status, color, due_date, visibility, created_by, created_at, updated_at | `deleted_at IS NULL` |
| `v_project_members` | id, organization_id (del proyecto), project_id, user_id, added_at | proyecto no borrado |
| `v_tasks` | id, organization_id, project_id, assignee_id, creator_id, status, title, description, due_date, due_time, started_at, completed_at, created_at, updated_at | `deleted_at IS NULL` |
| `v_task_comments` | id, organization_id, task_id, user_id, body, has_attachment (0/1), created_at, updated_at | tarea no borrada (la tabla no tiene `deleted_at`). **Sin** `attachment_path` |
| `v_task_histories` | id, organization_id, task_id, user_id, action, from_value, to_value, created_at | sin filtro (incluye el historial de tareas borradas, es auditoría) |
| `v_contacts` | id, organization_id, name, source, notes, phone_last4, email_masked (`p***@dominio.com`), created_by, created_at, updated_at | sin filtro |

> **Importante — aislamiento entre organizaciones.** Las vistas exponen **todas** las
> organizaciones. El servicio del agente DEBE filtrar siempre por `organization_id` del
> usuario resuelto con `whoami`. La BD no puede hacer ese filtro por él (no hay contexto de
> usuario en la conexión SQL del agente).

## Pasos en cPanel

1. **Crear la BD de vistas.** cPanel → *MySQL® Databases* → *Create New Database*:
   `agentro` (quedará `<cpaneluser>_agentro`).
2. **Dar a la app acceso a la BD de vistas.** En *Add User To Database*, agrega el usuario
   MySQL que usa la app (el de `DB_USERNAME`) a `<cpaneluser>_agentro` con **ALL PRIVILEGES**
   (necesita `CREATE VIEW` ahí). Ese usuario ya tiene privilegios en la BD principal: será el
   **definer** de las vistas.
3. **Crear el usuario del agente.** *MySQL Users* → *Add New User*: `agent` (queda
   `<cpaneluser>_agent`) con una contraseña larga y aleatoria. **No** la pongas en git.
4. **Asignarle sólo SELECT sobre la BD de vistas.** *Add User To Database* →
   `<cpaneluser>_agent` en `<cpaneluser>_agentro` → marca **únicamente SELECT**.
   **No** lo agregues a la BD principal. Nunca le des INSERT/UPDATE/DELETE en `agentro`:
   las vistas son actualizables por diseño de MariaDB y, con `SQL SECURITY DEFINER`, una
   escritura pasaría a la BD principal con los privilegios del definer.
5. **Configurar la app y crear las vistas** (por SSH o Terminal de cPanel, desde la raíz
   del proyecto):
   ```bash
   # en .env de producción
   AGENT_RO_DATABASE=<cpaneluser>_agentro

   php artisan config:clear
   php artisan agent:create-views
   # o sin tocar .env:
   php artisan agent:create-views --database=<cpaneluser>_agentro
   ```
   El comando es idempotente (`CREATE OR REPLACE VIEW`): vuelve a correrlo después de cada
   migración que cambie columnas de estas tablas, o si se borra/renombra el usuario definer.
6. **Remote MySQL.** cPanel → *Remote MySQL®* → agrega **sólo la IP pública del servidor del
   agente** (nada de `%`). El agente se conecta al host MySQL del hosting con
   `<cpaneluser>_agent` y la BD `<cpaneluser>_agentro`. Si el hosting lo permite, exige TLS.
7. **Verificar** desde el servidor del agente:
   ```sql
   SELECT * FROM <cpaneluser>_taskmanager.users LIMIT 1;  -- debe fallar: SELECT command denied
   SELECT * FROM <cpaneluser>_agentro.v_users LIMIT 1;    -- OK, sin email/password/tokens
   INSERT INTO <cpaneluser>_agentro.v_tasks (id) VALUES ('X'); -- debe fallar: INSERT command denied
   ```

## Advertencias verificadas en MariaDB 10.6.28 (contenedor `mariadb:10.6`)

- **El definer necesita SELECT en la BD principal.** Si se le revocan privilegios sobre la
  BD principal, toda consulta a las vistas falla con
  `ERROR 1356 ... definer/invoker of view lack rights to use them`.
- **Si el usuario definer se borra o se renombra**, consultar la vista devuelve un error
  engañoso para el agente: `ERROR 1045 (28000): Access denied for user '<agente>'`. La
  solución es volver a correr `php artisan agent:create-views` con un usuario existente.
  Ojo al cambiar la contraseña o el usuario de la app en cPanel.
- El definer queda como `CURRENT_USER` de quien corre el comando (en la prueba:
  `tm_owner@%`). En cPanel ese usuario es el de la app; el agente nunca es definer.
- El agente no puede crear vistas propias en `agentro` (`CREATE VIEW command denied`), así
  que no puede fabricarse una vista DEFINER hacia la BD principal.
