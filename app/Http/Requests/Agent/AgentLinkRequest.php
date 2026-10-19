<?php

namespace App\Http\Requests\Agent;

class AgentLinkRequest extends AgentPhoneRequest
{
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', self::E164],
            'code' => ['required', 'regex:/^\d{6}$/'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'code.required' => 'El código es obligatorio.',
            'code.regex' => 'El código debe tener 6 dígitos.',
        ]);
    }
}
