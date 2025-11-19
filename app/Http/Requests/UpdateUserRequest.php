<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * Admin can update any user, Supervisors and Operadores can only update themselves.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        $targetUser = $this->route('user');

        // Admin can update anyone
        if ($user?->hasRole('Admin')) {
            return true;
        }

        // Users can only update themselves
        return $user?->id === $targetUser->id;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $userId = $this->route('user')->id;

        return [
            'name' => [
                'sometimes',
                'string',
                'min:3',
                'max:100',
                'regex:/^[a-zA-ZáéíóúÁÉÍÓÚñÑ\s]+$/', // Solo letras y espacios
            ],
            'phone' => [
                'sometimes',
                'string',
                Rule::unique('users', 'phone')->ignore($userId),
                'regex:/^\+[1-9]\d{1,14}$/', // E.164 format
            ],
            'password' => [
                'sometimes',
                'string',
                'min:8',
                'regex:/^(?=.*[A-Z])(?=.*\d).+$/', // Al menos 1 mayúscula y 1 número
            ],
            'role' => [
                'sometimes',
                'string',
                Rule::in(['Admin', 'Supervisor', 'Operador']),
                function ($attribute, $value, $fail) {
                    // Only admins can change roles
                    if (!$this->user()?->hasRole('Admin')) {
                        $fail('Solo los administradores pueden cambiar roles.');
                    }
                },
            ],
            'is_active' => [
                'sometimes',
                'boolean',
                function ($attribute, $value, $fail) {
                    // Only admins can change active status
                    if (!$this->user()?->hasRole('Admin')) {
                        $fail('Solo los administradores pueden cambiar el estado activo.');
                    }
                },
            ],
            'whatsapp_phone' => [
                'sometimes',
                'nullable',
                'string',
                Rule::unique('users', 'whatsapp_phone')->ignore($userId),
                'regex:/^\+[1-9]\d{1,14}$/', // E.164 format
            ],
            'whatsapp_verified' => [
                'sometimes',
                'boolean',
            ],
            'notification_preferences' => [
                'sometimes',
                'nullable',
                'json',
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.min' => 'El nombre debe tener al menos :min caracteres.',
            'name.max' => 'El nombre no puede exceder :max caracteres.',
            'name.regex' => 'El nombre solo puede contener letras y espacios.',

            'phone.unique' => 'Este número de teléfono ya está registrado.',
            'phone.regex' => 'El formato del número de teléfono debe ser E.164 (ejemplo: +573001234567).',

            'password.min' => 'La contraseña debe tener al menos :min caracteres.',
            'password.regex' => 'La contraseña debe contener al menos una letra mayúscula y un número.',

            'role.in' => 'El rol debe ser Admin, Supervisor u Operador.',

            'is_active.boolean' => 'El campo is_active debe ser verdadero o falso.',

            'whatsapp_phone.unique' => 'Este número de WhatsApp ya está registrado.',
            'whatsapp_phone.regex' => 'El formato del número de WhatsApp debe ser E.164 (ejemplo: +573001234567).',

            'whatsapp_verified.boolean' => 'El campo whatsapp_verified debe ser verdadero o falso.',

            'notification_preferences.json' => 'Las preferencias de notificación deben ser un JSON válido.',
        ];
    }

    /**
     * Get custom authorization failure message.
     */
    protected function failedAuthorization(): void
    {
        throw new \Illuminate\Auth\Access\AuthorizationException(
            'No tienes permisos para actualizar este usuario.'
        );
    }
}
