<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'organization_name' => [
                'required_without:invite_code',
                'nullable',
                'string',
                'min:3',
                'max:100',
            ],
            'invite_code' => [
                'required_without:organization_name',
                'nullable',
                'string',
                'size:8',
                'exists:organizations,invite_code',
            ],
            'email' => [
                'required',
                'email',
                'string',
                'max:255',
                'unique:users,email',
            ],
            'phone' => [
                'nullable',
                'string',
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
                'confirmed',
                'regex:/^(?=.*[A-Z])(?=.*\d).+$/', // Al menos 1 mayúscula y 1 número
            ],
            'accepted_terms' => [
                'required',
                'accepted',
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
            'organization_name.required_without' => 'El nombre de la organización es obligatorio si no proporcionas un código de invitación.',
            'organization_name.min' => 'El nombre de la organización debe tener al menos :min caracteres.',
            'organization_name.max' => 'El nombre de la organización no puede exceder :max caracteres.',

            'invite_code.required_without' => 'El código de invitación es obligatorio si no proporcionas un nombre de organización.',
            'invite_code.size' => 'El código de invitación debe tener exactamente :size caracteres.',
            'invite_code.exists' => 'El código de invitación no es válido.',

            'email.required' => 'El correo electrónico es obligatorio.',
            'email.email' => 'El correo electrónico debe ser una dirección válida.',
            'email.unique' => 'Este correo electrónico ya está registrado.',
            'email.max' => 'El correo electrónico no puede exceder :max caracteres.',

            'phone.regex' => 'El formato del número de teléfono debe ser E.164 (ejemplo: +573001234567).',

            'name.required' => 'El nombre es obligatorio.',
            'name.min' => 'El nombre debe tener al menos :min caracteres.',
            'name.max' => 'El nombre no puede exceder :max caracteres.',
            'name.regex' => 'El nombre solo puede contener letras y espacios.',

            'password.required' => 'La contraseña es obligatoria.',
            'password.min' => 'La contraseña debe tener al menos :min caracteres.',
            'password.confirmed' => 'La confirmación de la contraseña no coincide.',
            'password.regex' => 'La contraseña debe contener al menos una letra mayúscula y un número.',

            'accepted_terms.required' => 'Debe aceptar los términos y condiciones.',
            'accepted_terms.accepted' => 'Debe aceptar los términos y condiciones.',
        ];
    }
}
