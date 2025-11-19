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
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
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
                'required',
                'date',
                'after_or_equal:today',
            ],
            'due_time' => [
                'required',
                'date_format:H:i',
            ],
            'priority' => [
                'required',
                'string',
                Rule::in(['Baja', 'Media', 'Alta']),
            ],
            'assignee_id' => [
                'required',
                'uuid',
                'exists:users,id',
                function ($attribute, $value, $fail) {
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

                    // If assigning to someone else, must be an active Operador
                    if (!$assignee->hasRole('Operador')) {
                        $fail('Solo se pueden asignar tareas a operadores.');
                        return;
                    }

                    if (!$assignee->is_active) {
                        $fail('El operador seleccionado no está activo.');
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
            // Validate that due_date + due_time is in the future (with 5 minutes tolerance)
            if (!$validator->errors()->has('due_date') && !$validator->errors()->has('due_time')) {
                $dueDate = $this->input('due_date');
                $dueTime = $this->input('due_time');

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
            'due_date.date' => 'La fecha de vencimiento debe ser una fecha válida.',
            'due_date.after_or_equal' => 'La fecha de vencimiento no puede ser anterior a hoy.',

            'due_time.required' => 'La hora de vencimiento es obligatoria.',
            'due_time.date_format' => 'La hora de vencimiento debe tener el formato HH:MM (ejemplo: 14:30).',

            'priority.required' => 'La prioridad es obligatoria.',
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
