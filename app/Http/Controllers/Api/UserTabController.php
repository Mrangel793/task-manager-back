<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserTab;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class UserTabController extends Controller
{
    /**
     * Get all tabs for the authenticated user.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $tabs = UserTab::where('user_id', $request->user()->id)
                ->orderBy('order')
                ->orderBy('created_at')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $tabs->map(fn($tab) => [
                    'id' => $tab->id,
                    'label' => $tab->label,
                    'view_type' => $tab->view_type,
                    'filters' => $tab->filters,
                    'order' => $tab->order,
                ]),
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching user tabs: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener las pestañas.',
            ], 500);
        }
    }

    /**
     * Create a new tab for the authenticated user.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'label' => 'required|string|max:100',
            'view_type' => 'nullable|string|max:50|in:tasks,admin_dashboard',
            'filters' => 'nullable|array',
            'filters.status' => 'nullable|string',
            'filters.priority' => 'nullable|string',
            'filters.assigneeIds' => 'nullable|array',
            'filters.assigneeIds.*' => 'uuid',
            'order' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // Get the max order for the user's tabs
            $maxOrder = UserTab::where('user_id', $request->user()->id)
                ->max('order') ?? -1;

            $tab = UserTab::create([
                'user_id' => $request->user()->id,
                'label' => $request->label,
                'view_type' => $request->view_type ?? 'tasks',
                'filters' => $request->filters ?? [],
                'order' => $request->order ?? ($maxOrder + 1),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Pestaña creada exitosamente.',
                'data' => [
                    'id' => $tab->id,
                    'label' => $tab->label,
                    'view_type' => $tab->view_type,
                    'filters' => $tab->filters,
                    'order' => $tab->order,
                ],
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error creating user tab: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al crear la pestaña.',
            ], 500);
        }
    }

    /**
     * Update a tab.
     *
     * @param Request $request
     * @param string $id
     * @return JsonResponse
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'label' => 'nullable|string|max:100',
            'view_type' => 'nullable|string|max:50|in:tasks,admin_dashboard',
            'filters' => 'nullable|array',
            'filters.status' => 'nullable|string',
            'filters.priority' => 'nullable|string',
            'filters.assigneeIds' => 'nullable|array',
            'filters.assigneeIds.*' => 'uuid',
            'order' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $tab = UserTab::where('id', $id)
                ->where('user_id', $request->user()->id)
                ->first();

            if (!$tab) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pestaña no encontrada.',
                ], 404);
            }

            // Update only provided fields
            if ($request->has('label')) {
                $tab->label = $request->label;
            }
            if ($request->has('view_type')) {
                $tab->view_type = $request->view_type;
            }
            if ($request->has('filters')) {
                $tab->filters = $request->filters;
            }
            if ($request->has('order')) {
                $tab->order = $request->order;
            }

            $tab->save();

            return response()->json([
                'success' => true,
                'message' => 'Pestaña actualizada exitosamente.',
                'data' => [
                    'id' => $tab->id,
                    'label' => $tab->label,
                    'view_type' => $tab->view_type,
                    'filters' => $tab->filters,
                    'order' => $tab->order,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating user tab: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar la pestaña.',
            ], 500);
        }
    }

    /**
     * Delete a tab.
     *
     * @param Request $request
     * @param string $id
     * @return JsonResponse
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        try {
            $tab = UserTab::where('id', $id)
                ->where('user_id', $request->user()->id)
                ->first();

            if (!$tab) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pestaña no encontrada.',
                ], 404);
            }

            $tab->delete();

            return response()->json([
                'success' => true,
                'message' => 'Pestaña eliminada exitosamente.',
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting user tab: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar la pestaña.',
            ], 500);
        }
    }

    /**
     * Bulk update tab orders.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function reorder(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'tabs' => 'required|array',
            'tabs.*.id' => 'required|uuid',
            'tabs.*.order' => 'required|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            foreach ($request->tabs as $tabData) {
                UserTab::where('id', $tabData['id'])
                    ->where('user_id', $request->user()->id)
                    ->update(['order' => $tabData['order']]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Orden de pestañas actualizado.',
            ]);
        } catch (\Exception $e) {
            Log::error('Error reordering user tabs: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al reordenar las pestañas.',
            ], 500);
        }
    }
}
