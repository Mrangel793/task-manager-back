<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lista blanca de rutas para tokens del agente de WhatsApp (ability "agent" sin "*").
 * Con un token de agente sólo pasan las rutas nombradas aquí; todo lo demás es 403.
 * Los tokens normales de la app no se ven afectados.
 */
class AgentTokenAllowlist
{
    public const ALLOWED_ROUTES = [
        'api.auth.me',

        'api.tasks.index',
        'api.tasks.show',
        'api.tasks.store',
        'api.tasks.update',
        'api.tasks.destroy',
        'api.tasks.reassign',
        'api.tasks.history',
        'api.tasks.comments.index',
        'api.tasks.comments.store',
        'api.tasks.comments.destroy',

        'api.projects.index',
        'api.projects.summary',
        'api.projects.show',
        'api.projects.tasks',
        'api.projects.store',
        'api.projects.members.index',
        'api.projects.members.store',

        'api.contacts.index',
        'api.contacts.show',
        'api.contacts.store',

        'api.notifications.index',
        'api.notifications.unread-count',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->isUsingAgentToken()
            && ! in_array($request->route()?->getName(), self::ALLOWED_ROUTES, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Esta acción no está permitida con un token de agente.',
            ], 403);
        }

        return $next($request);
    }
}
