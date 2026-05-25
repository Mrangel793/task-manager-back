<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

        $this->notifyContactCreated($contact, $request->user()->name);

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

    public function export(Request $request): StreamedResponse
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
            fputcsv($handle, ['ID', 'Nombre', 'Teléfono', 'Email', 'Municipio', 'Notas', 'Creado']);

            foreach ($contacts as $contact) {
                fputcsv($handle, [
                    $contact->id,
                    $contact->name,
                    $contact->phone ?? '',
                    $contact->email ?? '',
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

    private function notifyContactCreated(Contact $contact, string $createdByName): void
    {
        try {
            $users = User::whereNotNull('phone')
                ->permission('view-contacts')
                ->get();

            foreach ($users as $user) {
                Notification::create([
                    'organization_id' => $contact->organization_id,
                    'user_id'         => $user->id,
                    'channel'         => 'whatsapp',
                    'type'            => 'contact_created',
                    'title'           => 'Nuevo contacto registrado',
                    'message'         => "Se registró el contacto {$contact->name}",
                    'status'          => 'pending',
                    'data'            => [
                        'phone'    => $user->phone,
                        'template' => 'contacto_creado',
                        'template_params' => [
                            'user_name'      => $user->name,
                            'contact_name'   => $contact->name,
                            'contact_phone'  => $contact->phone ?? 'N/A',
                            'contact_source' => $contact->source ?? 'N/A',
                        ],
                        'contact_id' => $contact->id,
                    ],
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Error creating contact notifications: ' . $e->getMessage());
        }
    }
}
