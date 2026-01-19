<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * Only Admin users can create new users.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasRole('Admin');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                'unique:users,email',
            ],
            'phone' => [
                'nullable',
                'string',
                'unique:users,phone',
                'regex:/^\+[1-9]\d{1,14}$/', // E.164 format
            ],
            'name' => [
                'required',
                'string',
                'min:3',
                'max:100',
                'regex:/^[a-zA-ZáéíóúÁÉÍÓÚñÑ\s]+$/', // Solo letras y espacios
            ],
            'password' => [
                'required',
                'string',
                'min:8',
                'regex:/^(?=.*[A-Z])(?=.*\d).+$/', // Al menos 1 mayúscula y 1 número
            ],
            'role' => [
                'required',
                'string',
                Rule::in(['Admin', 'Supervisor', 'Operador']),
            ],
            'is_active' => [
                'sometimes',
                'boolean',
            ],
            'whatsapp_phone' => [
                'nullable',
                'string',
                'unique:users,whatsapp_phone',
                'regex:/^\+[1-9]\d{1,14}$/', // E.164 format
            ],
            'notification_preferences' => [
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
            'email.required' => 'El correo electrónico es obligatorio.',
            'email.email' => 'El correo electrónico debe ser una dirección válida.',
            'email.unique' => 'Este correo electrónico ya está registrado.',
            'email.max' => 'El correo electrónico no puede exceder :max caracteres.',

            'phone.unique' => 'Este número de teléfono ya está registrado.',
            'phone.regex' => 'El formato del número de teléfono debe ser E.164 (ejemplo: +573001234567).',

            'name.required' => 'El nombre es obligatorio.',
            'name.min' => 'El nombre debe tener al menos :min caracteres.',
            'name.max' => 'El nombre no puede exceder :max caracteres.',
            'name.regex' => 'El nombre solo puede contener letras y espacios.',

            'password.required' => 'La contraseña es obligatoria.',
            'password.min' => 'La contraseña debe tener al menos :min caracteres.',
            'password.regex' => 'La contraseña debe contener al menos una letra mayúscula y un número.',

            'role.required' => 'El rol es obligatorio.',
            'role.in' => 'El rol debe ser Admin, Supervisor u Operador.',

            'is_active.boolean' => 'El campo is_active debe ser verdadero o falso.',

            'whatsapp_phone.unique' => 'Este número de WhatsApp ya está registrado.',
            'whatsapp_phone.regex' => 'El formato del número de WhatsApp debe ser E.164 (ejemplo: +573001234567).',

            'notification_preferences.json' => 'Las preferencias de notificación deben ser un JSON válido.',
        ];
    }

    /**
     * Get custom authorization failure message.
     */
    protected function failedAuthorization(): void
    {
        throw new \Illuminate\Auth\Access\AuthorizationException(
            'No tienes permisos para crear usuarios. Solo los administradores pueden crear usuarios.'
        );
    }
}
