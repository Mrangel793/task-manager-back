<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;

class ContactController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Contact::with('creator:id,name');

        if ($request->filled('source')) {
            $query->where('source', $request->source);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                  ->orWhere('phone', 'LIKE', "%{$search}%")
                  ->orWhere('email', 'LIKE', "%{$search}%")
                  ->orWhere('source', 'LIKE', "%{$search}%");
            });
        }

        $contacts = $query->orderBy('name')->paginate(50);

        // Get distinct sources for filter options
        $sources = Contact::select('source')
            ->whereNotNull('source')
            ->distinct()
            ->orderBy('source')
            ->pluck('source');

        return response()->json([
            'success' => true,
            'data' => [
                'contacts' => $contacts,
                'sources' => $sources,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name'    => 'required|string|max:255',
            'phone'   => 'nullable|string|max:50',
            'email'   => 'nullable|email|max:255',
            'address' => 'nullable|string|max:500',
            'notes'   => 'nullable|string',
            'source'  => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $contact = Contact::create([
            'name'            => $request->name,
            'phone'           => $request->phone,
            'email'           => $request->email,
            'address'         => $request->address,
            'notes'           => $request->notes,
            'source'          => $request->source,
            'organization_id' => $request->user()->organization_id,
            'created_by'      => $request->user()->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Contacto creado correctamente.',
            'data'    => ['contact' => $contact],
        ], 201);
    }

    public function show(string $id): JsonResponse
    {
        $contact = Contact::with('creator:id,name')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => ['contact' => $contact],
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $contact = Contact::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name'    => 'sometimes|required|string|max:255',
            'phone'   => 'nullable|string|max:50',
            'email'   => 'nullable|email|max:255',
            'address' => 'nullable|string|max:500',
            'notes'   => 'nullable|string',
            'source'  => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $contact->update($request->only(['name', 'phone', 'email', 'address', 'notes', 'source']));

        return response()->json([
            'success' => true,
            'message' => 'Contacto actualizado correctamente.',
            'data'    => ['contact' => $contact],
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $contact = Contact::findOrFail($id);
        $contact->delete();

        return response()->json([
            'success' => true,
            'message' => 'Contacto eliminado correctamente.',
        ]);
    }

    public function export(Request $request): Response
    {
        $query = Contact::orderBy('source')->orderBy('name');

        if ($request->filled('source')) {
            $query->where('source', $request->source);
        }

        $contacts = $query->get();

        $filename = 'contactos_' . now()->format('Y-m-d') . '.csv';

        $headers = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($contacts) {
            $handle = fopen('php://output', 'w');

            // BOM for Excel UTF-8 compatibility
            fputs($handle, "\xEF\xBB\xBF");

            // Header row
            fputcsv($handle, ['ID', 'Nombre', 'Teléfono', 'Email', 'Dirección', 'Empresa/Origen', 'Notas', 'Creado']);

            foreach ($contacts as $contact) {
                fputcsv($handle, [
                    $contact->id,
                    $contact->name,
                    $contact->phone ?? '',
                    $contact->email ?? '',
                    $contact->address ?? '',
                    $contact->source ?? '',
                    $contact->notes ?? '',
                    $contact->created_at->format('d/m/Y H:i'),
                ]);
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function grantAccess(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|uuid|exists:users,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $user = User::findOrFail($request->user_id);
        $user->givePermissionTo('view-contacts');

        return response()->json([
            'success' => true,
            'message' => "Acceso a contactos otorgado a {$user->name}.",
        ]);
    }

    public function revokeAccess(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|uuid|exists:users,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $user = User::findOrFail($request->user_id);
        $user->revokePermissionTo('view-contacts');

        return response()->json([
            'success' => true,
            'message' => "Acceso a contactos revocado para {$user->name}.",
        ]);
    }
}
