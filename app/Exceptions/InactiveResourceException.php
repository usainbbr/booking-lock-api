<?php

namespace App\Exceptions;

use RuntimeException;

class InactiveResourceException extends RuntimeException
{
    public function __construct(string $message = 'This resource is not accepting bookings.')
    {
        parent::__construct($message);
    }
}
