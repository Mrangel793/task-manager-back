<?php

namespace App\Http\Requests;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaskRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * User must have permission to create tasks.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create-tasks');
    }

    /**
     * Prepare the data for validation.
     * Extract date and time from ISO strings if needed, converting to local timezone.
     * For Admin users, set default values if not provided.
     */
    protected function prepareForValidation(): void
    {
        $data = [];
        $timezone = new \DateTimeZone(config('app.timezone', 'America/Bogota'));
        $user = $this->user();
        $isAdmin = $user && $user->hasRole('Admin');

        // Handle due_date - convert ISO string to local date
        if ($this->has('due_date') && $this->input('due_date')) {
            $dueDate = $this->input('due_date');
            if (is_string($dueDate) && strlen($dueDate) > 10) {
                try {
                    // Parse ISO string and convert to local timezone
                    $dateTime = new \DateTime($dueDate);
                    $dateTime->setTimezone($timezone);
                    $data['due_date'] = $dateTime->format('Y-m-d');
                } catch (\Exception $e) {
                    // If parsing fails, try to extract just the date part
                    $data['due_date'] = substr($dueDate, 0, 10);
                }
            }
        } elseif ($isAdmin && (!$this->has('due_date') || !$this->input('due_date'))) {
            // Admin: default to today if not provided
            $data['due_date'] = (new \DateTime())->setTimezone($timezone)->format('Y-m-d');
        }

        // Handle due_time - extract HH:MM from ISO string, converting to local timezone
        if ($this->has('due_time') && $this->input('due_time')) {
            $dueTime = $this->input('due_time');
            if (is_string($dueTime)) {
                // If it's an ISO datetime string (contains T), extract the time in local timezone
                if (str_contains($dueTime, 'T')) {
                    try {
                        $dateTime = new \DateTime($dueTime);
                        $dateTime->setTimezone($timezone);
                        $data['due_time'] = $dateTime->format('H:i');
                    } catch (\Exception $e) {
                        // Leave as-is, validation will catch it
                    }
                } elseif (strlen($dueTime) > 5 && str_contains($dueTime, ':')) {
                    // If it's HH:MM:SS format, extract HH:MM
                    $data['due_time'] = substr($dueTime, 0, 5);
                }
            }
        }

        // Set default values for optional fields
        if (!$this->has('priority') || !$this->input('priority')) {
            $data['priority'] = 'Media';
        }

        // Admin: default assignee to self if not provided
        if ($isAdmin && (!$this->has('assignee_id') || !$this->input('assignee_id'))) {
            $data['assignee_id'] = $user->id;
        }

        if (!empty($data)) {
            $this->merge($data);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     * Admin users can create tasks with just a title.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $user = $this->user();
        $isAdmin = $user && $user->hasRole('Admin');

        // Base rules - due_date and assignee_id are optional for Admin
        $dueDateRequired = $isAdmin ? 'nullable' : 'required';
        $assigneeRequired = $isAdmin ? 'nullable' : 'required';

        return [
            'title' => [
                'required',
                'string',
                'min:3',
                'max:100',
            ],
            'description' => [
                'nullable',
                'string',
                'max:500',
            ],
            'due_date' => [
                $dueDateRequired,
                'regex:/^\d{4}-\d{2}-\d{2}$/',
                function ($attribute, $value, $fail) {
                    if (!$value) return; // Skip if null (Admin default will be set)

                    // Validate it's a real date
                    $date = \DateTime::createFromFormat('Y-m-d', $value);
                    if (!$date || $date->format('Y-m-d') !== $value) {
                        $fail('La fecha de vencimiento no es válida.');
                        return;
                    }
                    // Check it's not in the past
                    $today = (new \DateTime())->setTime(0, 0, 0);
                    $dueDate = $date->setTime(0, 0, 0);
                    if ($dueDate < $today) {
                        $fail('La fecha de vencimiento no puede ser anterior a hoy.');
                    }
                },
            ],
            'due_time' => [
                'nullable',
                'date_format:H:i',
            ],
            'priority' => [
                'nullable',
                'string',
                Rule::in(['Baja', 'Media', 'Alta']),
            ],
            'assignee_id' => [
                $assigneeRequired,
                'uuid',
                'exists:users,id',
                function ($attribute, $value, $fail) {
                    if (!$value) return; // Skip if null (Admin default will be set)

                    $assignee = User::find($value);
                    $currentUser = $this->user();

                    if (!$assignee) {
                        $fail('El usuario asignado no existe.');
                        return;
                    }

                    // Allow users to assign tasks to themselves (using string comparison for UUIDs)
                    if ((string) $assignee->id === (string) $currentUser->id) {
                        // Check if the user is active
                        if (!$assignee->is_active) {
                            $fail('Tu cuenta no está activa.');
                            return;
                        }
                        return; // Allow self-assignment
                    }

                    // If assigning to someone else, must be an active Operador or any user for Admin
                    if (!$currentUser->hasRole('Admin') && !$assignee->hasRole('Operador')) {
                        $fail('Solo se pueden asignar tareas a operadores.');
                        return;
                    }

                    if (!$assignee->is_active) {
                        $fail('El usuario seleccionado no está activo.');
                        return;
                    }
                },
            ],
        ];
    }

    /**
     * Configure the validator instance.
     *
     * @param  \Illuminate\Validation\Validator  $validator
     * @return void
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Only validate datetime if both date and time are provided
            $dueDate = $this->input('due_date');
            $dueTime = $this->input('due_time');

            if ($dueDate && $dueTime && !$validator->errors()->has('due_date')) {
                try {
                    $dueDateTime = Carbon::parse("{$dueDate} {$dueTime}");
                    $now = Carbon::now()->subMinutes(5); // 5 minutes tolerance

                    if ($dueDateTime->lte($now)) {
                        $validator->errors()->add(
                            'due_date',
                            'La fecha y hora de vencimiento debe ser futura (al menos 5 minutos desde ahora).'
                        );
                    }
                } catch (\Exception $e) {
                    $validator->errors()->add('due_date', 'La fecha y hora de vencimiento no es válida.');
                }
            }
        });
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'El título es obligatorio.',
            'title.min' => 'El título debe tener al menos :min caracteres.',
            'title.max' => 'El título no puede exceder :max caracteres.',

            'description.max' => 'La descripción no puede exceder :max caracteres.',

            'due_date.required' => 'La fecha de vencimiento es obligatoria.',
            'due_date.regex' => 'La fecha debe tener el formato YYYY-MM-DD (ejemplo: 2026-01-21).',

            'due_time.date_format' => 'La hora de vencimiento debe tener el formato HH:MM (ejemplo: 14:30).',

            'priority.in' => 'La prioridad debe ser: Baja, Media o Alta.',

            'assignee_id.required' => 'Debe asignar la tarea a un operador.',
            'assignee_id.uuid' => 'El ID del operador no es válido.',
            'assignee_id.exists' => 'El operador seleccionado no existe.',
        ];
    }

    /**
     * Get custom authorization failure message.
     */
    protected function failedAuthorization(): void
    {
        throw new \Illuminate\Auth\Access\AuthorizationException(
            'No tienes permisos para crear tareas.'
        );
    }
}
