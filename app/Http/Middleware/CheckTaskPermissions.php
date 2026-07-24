<?php

namespace App\Http\Middleware;

use App\Models\Task;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckTaskPermissions
{
    /**
     * Handle an incoming request.
     *
     * Validates permissions on specific tasks based on user role:
     * - Admin: Can access all tasks
     * - Supervisor: Can access their own tasks + tasks assigned to Operadores
     * - Operador: Can only access tasks assigned to them
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Get task from route parameter if it exists
        $task = $request->route('task');

        // If no task in route, allow the request (will be handled by controller)
        if (!$task instanceof Task) {
            return $next($request);
        }

        // Admin can access all tasks
        if ($user->hasRole('Admin')) {
            return $next($request);
        }

        // Supervisor can access:
        // 1. Tasks assigned to them
        // 2. Tasks they created
        // 3. Tasks assigned to Operadores
        if ($user->hasRole('Supervisor')) {
            $canAccess = $task->assignee_id === $user->id
                || $task->creator_id === $user->id
                || $task->assignee?->hasRole('Operador');

            if ($canAccess) {
                return $next($request);
            }

            return response()->json([
                'success' => false,
                'message' => 'No tienes permisos para acceder a esta tarea.',
            ], 403);
        }

        // Operador can access tasks assigned to them or created by them
        if ($task->assignee_id === $user->id || $task->creator_id === $user->id) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => 'No tienes permisos para acceder a esta tarea.',
        ], 403);
    }
}
