<?php

namespace App\Exceptions;

use RuntimeException;

class BookingConflictException extends RuntimeException
{
    public function __construct(string $message = 'The requested time slot is already booked.')
    {
        parent::__construct($message);
    }
}
