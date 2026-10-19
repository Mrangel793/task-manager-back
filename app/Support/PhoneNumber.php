<?php

namespace App\Support;

final class PhoneNumber
{
    /**
     * Número internacional E.164: "+", sin cero inicial, 8 a 15 dígitos en total.
     */
    public const E164_REGEX = '/^\+[1-9]\d{7,14}$/';

    public const E164_RULE = 'regex:'.self::E164_REGEX;
}
