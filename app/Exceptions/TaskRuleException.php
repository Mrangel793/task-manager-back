<?php

namespace App\Exceptions;

use Exception;

/**
 * A business rule rejected the task operation; its message is safe to show to the user.
 */
class TaskRuleException extends Exception {}
