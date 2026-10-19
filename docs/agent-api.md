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

## 2. Rutas del usuario (auth:sanctum, token normal de la app)

### `POST /api/v1/me/whatsapp-link-code`

Genera un código de 6 dígitos válido 10 min e invalida los códigos pendientes del usuario.
El código sólo se devuelve en esta respuesta (se guarda su HMAC). Límite: 5/min.

- `201 {"success": true, "message": "...", "data": {"code": "042817", "expires_at": "2026-10-19T15:10:00-05:00"}}`
- `401` sin token. `403` si se llama con un token de agente.

### `DELETE /api/v1/me/whatsapp`

Desvincula: `whatsapp_phone = null`, `whatsapp_verified = false`, borra códigos pendientes y
**revoca los tokens de agente vigentes** del usuario.

- `200 {"success": true, "message": "WhatsApp desvinculado."}`

## 3. Rutas del agente (`agent.hmac`)

### `POST /api/agent/v1/link`

Body: `{"phone": "+573001234567", "code": "042817"}` (`phone` en E.164; `code` 6 dígitos,
mándalo como string para no perder ceros a la izquierda).

| Respuesta | Cuándo |
|-----------|--------|
| `200 {"success": true, "data": {"user_id", "name", "organization": {"id", "name"}}}` | código vigente; el usuario queda con `whatsapp_phone = phone` y `whatsapp_verified = true` |
| `409 {"success": false, "message": "..."}` | ese teléfono ya pertenece a otro usuario (el código no se consume) |
| `422 {"success": false, "message": "Código inválido o vencido."}` | código inexistente, vencido o ya usado; usuario o organización inactivos |
| `422` con `errors` | `phone` no E.164 o `code` sin 6 dígitos |
| `429` | ese teléfono acumuló más de 5 intentos fallidos en 10 min: queda bloqueado hasta que pase la ventana |

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

Con un token de agente están **bloqueadas** (403): `POST /api/v1/auth/refresh-token`
(lo volvería permanente), `POST /api/v1/auth/change-password` y
`POST /api/v1/me/whatsapp-link-code`.

## Lecturas por SQL

Ver [agent-readonly.md](agent-readonly.md).
