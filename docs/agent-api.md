# API de identidad del agente de WhatsApp

Capa de identidad del agente autónomo (servicio Python aparte). El agente:

1. Vincula el WhatsApp de un usuario con un código de un solo uso (`/link`).
2. Resuelve quién escribe por su teléfono (`/whoami`).
3. Obtiene un token Sanctum de 15 min (`/token`) y con él usa la API normal `/api/v1/*`
   **como ese usuario**, con su rol y su organización.

Todas las respuestas son JSON con la convención del proyecto: `{"success": bool, ...}`.

## 1. Autenticación HMAC del servicio (`agent.hmac`)

Aplica a `/api/agent/v1/*`. Secreto compartido: `AGENT_HMAC_SECRET` (en el `.env` de ambos
sistemas). Si está vacío, **todas** las rutas responden 401 (falla cerrado).

Cabeceras obligatorias:

| Cabecera | Valor |
|----------|-------|
| `X-Agent-Timestamp` | unix en segundos (entero) |
| `X-Agent-Nonce` | aleatorio de 16 a 64 caracteres `[A-Za-z0-9_-]` (p. ej. `secrets.token_hex(16)`) |
| `X-Agent-Signature` | hex en minúsculas de `HMAC-SHA256(secret, mensaje)` |

```
mensaje = timestamp + "\n" + nonce + "\n" + METHOD + "\n" + path_con_query + "\n" + raw_body
```

- `METHOD` en mayúsculas (`GET`, `POST`).
- `path_con_query` es la ruta **tal cual se envía**, con la query sin reordenar ni
  re-codificar: `/api/agent/v1/whoami?phone=%2B573001234567`. Codifica el `+` como `%2B`.
  Si la app se monta en un subdirectorio, la ruta es relativa a la raíz de la app.
- `raw_body` son los bytes exactos enviados (cadena vacía en GET).

Se rechaza con `401 {"success": false, "message": "Unauthorized."}` si: falta el secreto,
falta o es inválida alguna cabecera, `|now - timestamp| > 300 s`, la firma no coincide
(`hash_equals`) o el nonce ya se usó en los últimos 10 min.

Ejemplo en Python:

```python
import hashlib, hmac, json, secrets, time, requests
from urllib.parse import quote

def signed(method, path, body=None):
    raw = json.dumps(body, separators=(",", ":")) if body is not None else ""
    ts, nonce = str(int(time.time())), secrets.token_hex(16)
    msg = "\n".join([ts, nonce, method, path, raw]).encode()
    sig = hmac.new(SECRET.encode(), msg, hashlib.sha256).hexdigest()
    headers = {"X-Agent-Timestamp": ts, "X-Agent-Nonce": nonce, "X-Agent-Signature": sig,
               "Content-Type": "application/json", "Accept": "application/json"}
    return requests.request(method, BASE + path, data=raw.encode(), headers=headers)

signed("GET", "/api/agent/v1/whoami?phone=" + quote("+573001234567", safe=""))
```

Rate limit: 120 req/min por IP en todo el grupo; `/link` además 30 req/min.

**Nonces y cache.** Los nonces usados viven 10 min en la cache de Laravel (`CACHE_STORE`).
`php artisan cache:clear` (o `optimize:clear`, que lo incluye) los borra: durante los 5 min
siguientes una petición capturada podría reenviarse una vez más. Igual pasa con los
contadores de intentos fallidos de `/link`. Evita limpiar la cache en producción salvo que
haga falta y, si se hace, considera rotar `AGENT_HMAC_SECRET`. Con `CACHE_STORE=file` o
`database` el registro del nonce no es estrictamente atómico ante peticiones simultáneas;
`redis` sí lo es.

## 2. Rutas del usuario (auth:sanctum, token normal de la app)

### `POST /api/v1/me/whatsapp-link-code`

Body: `{"phone": "+573001234567"}` (E.164 con 8 a 15 dígitos, `^\+[1-9]\d{7,14}$`; el número de WhatsApp que se va a vincular).
La misma regla aplica a `phone` en `/link`, `/whoami` y `/token`.

`PATCH /api/v1/auth/me` ya **no** acepta `whatsapp_phone` (422): la vinculación por código es
la única vía para el usuario. En el login, un `whatsapp_phone` sólo sirve como credencial si
está verificado, y si la credencial coincide con más de un usuario (el `phone` de uno y el
WhatsApp de otro) se rechaza con el 401 genérico.

`PATCH /api/v1/users/{id}` tampoco deja que un usuario cambie **su propio** `whatsapp_phone`
(422, aunque sea Admin o envíe `null`). Sólo quien tiene `manage-users` puede cambiar el de
**otro** usuario de su organización, y ese número queda sin verificar.

Genera un código de 6 dígitos válido 10 min **atado a ese número** e invalida los códigos
pendientes del usuario. Se guardan sólo el HMAC del código y el del número (con `APP_KEY`);
el código en claro sólo se devuelve en esta respuesta. Límite: 5/min.

- `201 {"success": true, "message": "...", "data": {"code": "042817", "expires_at": "2026-10-19T15:10:00-05:00"}}`
- `422` con `errors.phone` si falta, no es E.164 o ya es el WhatsApp **verificado** de otro
  usuario. Un número que otro usuario tiene sin verificar no bloquea (ver `/link`).
- `401` sin token. `403` si se llama con un token de agente.

### `DELETE /api/v1/me/whatsapp`

Desvincula: `whatsapp_phone = null`, `whatsapp_verified = false`, borra códigos pendientes y
**revoca los tokens de agente vigentes** del usuario.

- `200 {"success": true, "message": "WhatsApp desvinculado."}`
- `403` si se llama con un token de agente (el usuario desvincula desde la app).

## 3. Rutas del agente (`agent.hmac`)

### `POST /api/agent/v1/link`

Body: `{"phone": "+573001234567", "code": "042817"}` (`phone` en E.164 = el número que
escribe al agente; `code` 6 dígitos, mándalo como string para no perder ceros a la izquierda).

El código sólo sirve desde **el mismo número** que el usuario indicó al generarlo: el mismo
código enviado desde otro número responde 422, así que rotar números no ayuda a adivinarlo.

| Respuesta | Cuándo |
|-----------|--------|
| `200 {"success": true, "data": {"user_id", "name", "organization": {"id", "name"}}}` | código vigente para ese número; el usuario queda con `whatsapp_phone = phone` y `whatsapp_verified = true` |
| `409 {"success": false, "message": "..."}` | otro usuario **verificó** ese número después de generar el código, o lo tomó en una vinculación simultánea (el código no se consume) |
| `422 {"success": false, "message": "Código inválido o vencido."}` | código inexistente, de otro número, vencido, ya usado o invalidado por intentos; usuario u organización inactivos |
| `422` con `errors` | `phone` no E.164 o `code` sin 6 dígitos |
| `429` | ese número acumuló más de 5 fallos en 10 min, **o** `/link` acumuló más de 50 fallos en total en 10 min (bloqueo global para todos, con `Log::warning`) |

Si otro usuario (de cualquier organización) tenía ese número **sin verificar**, al vincular se
le quita (`whatsapp_phone = null`) y se revocan sus tokens `whatsapp-agent`: el número es de
quien demuestra tenerlo enviando el código desde ese WhatsApp. Queda un `Log::info` con
ambos ids.

Intentos: cada fallo desde un número suma `attempts` al código vigente de ese número; al
6º fallo el código queda invalidado aunque luego se envíe el correcto (hay que generar otro).
Los códigos usados o vencidos se purgan a diario con `model:prune` (scheduler, 03:30).

### `GET /api/agent/v1/whoami?phone=%2B573001234567`

Usuario con ese `whatsapp_phone`, `whatsapp_verified = true`, `is_active = true`, no borrado,
y con organización activa y no borrada.

- `200 {"success": true, "data": {"id", "name", "organization": {"id", "name"}, "roles": ["Operador"], "permissions": ["view-tasks", ...], "role": "Operador"}}`
- `404 {"success": false, "message": "No encontrado."}` en cualquier otro caso (no se distingue el motivo).

### `POST /api/agent/v1/token`

Body: `{"phone": "+573001234567"}`. Mismas condiciones que `whoami`.

- `200 {"success": true, "data": {"token": "<id>|<plain>", "expires_at": "<ISO 8601, ahora + 15 min>"}}`
- `404` igual que `whoami`.

El token es un personal access token de Sanctum con nombre `whatsapp-agent`, ability
`agent` y `expires_at` = +15 min. Úsalo como `Authorization: Bearer <token>` contra
`/api/v1/*`: aplica el mismo RBAC y la misma organización que la sesión normal del usuario
(p. ej. un Operador sólo ve sus tareas y `DELETE /api/v1/tasks/{id}` le da 403). Pasado el
vencimiento responde 401: pide otro token. Los tokens vencidos del usuario se purgan al
emitir uno nuevo.

### Lista blanca del token de agente (`agent.allowlist`)

Con un token de agente sólo responden estas rutas `/api/v1`; **cualquier otra da 403**
(`"Esta acción no está permitida con un token de agente."`). Además aplica el RBAC normal
(p. ej. `tasks.destroy` exige `delete-tasks`, `contacts.*` exige `view-contacts`).

| Grupo | Rutas (nombre) |
|-------|----------------|
| Perfil | `GET auth/me` (`api.auth.me`) |
| Tareas | `GET tasks`, `GET tasks/{id}`, `POST tasks`, `PATCH/PUT tasks/{id}`, `DELETE tasks/{id}`, `POST tasks/{id}/reassign`, `GET tasks/{id}/history`, `GET/POST tasks/{id}/comments`, `DELETE tasks/{id}/comments/{c}` |
| Proyectos | `GET projects`, `GET projects/summary`, `GET projects/{id}`, `GET projects/{id}/tasks`, `POST projects`, `GET projects/{id}/members`, `POST projects/{id}/members` |
| Contactos | `GET contacts`, `GET contacts/{id}`, `POST contacts` (sin export ni grant/revoke) |
| Notificaciones | `GET notifications`, `GET notifications/unread-count` |

Quedan fuera, entre otras: gestión de usuarios (`users*`), `PATCH auth/me`, refresh-token,
change-password, logout, `me/whatsapp*`, Google Calendar, push, tabs, editar/borrar
proyectos, quitar miembros, editar/borrar/exportar contactos y marcar notificaciones.
La lista vive en `App\Http\Middleware\AgentTokenAllowlist::ALLOWED_ROUTES`; una ruta nueva
queda bloqueada para el agente hasta que se agregue ahí.

### Cuándo deja de servir un token de agente

- Vence a los 15 min (401).
- El usuario queda inactivo, pierde `whatsapp_verified` o su organización se desactiva: la
  siguiente petición responde 401 y el token se borra.
- Se cambia el `whatsapp_phone` del usuario (desde gestión de usuarios, `PATCH users/{id}`), se
  le quita la verificación, se desvincula o se borra el usuario: se revocan todos sus tokens
  `whatsapp-agent`. Al borrar un usuario también se liberan su email, teléfono y WhatsApp.

## Despliegue: revisar colisiones antes de subir

El login ahora rechaza (401 genérico) una credencial que coincide con el `phone` de un usuario
y con el WhatsApp verificado de otro. Antes de desplegar, busca esos casos en la BD de
producción para resolverlos (cambiar o desverificar uno de los dos números) y que nadie se
quede sin poder entrar:

```sql
SELECT a.id, b.id
FROM users a
JOIN users b ON a.phone = b.whatsapp_phone
    AND a.id <> b.id
    AND b.whatsapp_verified = 1
    AND a.deleted_at IS NULL
    AND b.deleted_at IS NULL;
```

Si devuelve filas, `a` es quien entra con su `phone` y `b` quien tiene ese número como WhatsApp
verificado.

## Lecturas por SQL

Ver [agent-readonly.md](agent-readonly.md).
