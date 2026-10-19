<?php

namespace App\Http\Requests\Agent;

use Illuminate\Foundation\Http\FormRequest;

class AgentPhoneRequest extends FormRequest
{
    public const E164 = 'regex:/^\+[1-9]\d{7,14}$/';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', self::E164],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.required' => 'El teléfono es obligatorio.',
            'phone.regex' => 'El teléfono debe estar en formato E.164 (ejemplo: +573001234567).',
        ];
    }
}
