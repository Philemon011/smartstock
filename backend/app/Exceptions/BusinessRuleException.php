<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Une règle métier est violée (ex. désactiver l'entrepôt principal). Le message est destiné à l'utilisateur.
 */
class BusinessRuleException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode)
    {
        parent::__construct($message);
    }
}