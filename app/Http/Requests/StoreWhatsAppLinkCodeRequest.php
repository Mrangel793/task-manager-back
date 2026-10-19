<?php

namespace App\Http\Requests;

use App\Http\Requests\Agent\AgentPhoneRequest;
use Illuminate\Validation\Rule;

/**
 * El código de vinculación queda atado al número que el usuario indica aquí.
 */
class StoreWhatsAppLinkCodeRequest extends AgentPhoneRequest
{
    public function rules(): array
    {
        return [
            'phone' => [
                'required',
                'string',
                self::E164,
                // Sólo bloquea un número que otro usuario ya verificó; uno apartado sin
                // verificar se le quita al vincular (ver AgentIdentityController::link).
                Rule::unique('users', 'whatsapp_phone')
                    ->where('whatsapp_verified', true)
                    ->ignore($this->user()?->id),
            ],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'phone.unique' => 'Este número de WhatsApp ya está registrado.',
        ]);
    }
}
