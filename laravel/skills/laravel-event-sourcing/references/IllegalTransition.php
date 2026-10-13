<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

class IllegalTransition extends DomainException
{
    public static function for(string $aggregate, string $operation, string $state): self
    {
        return new self("Cannot {$operation} {$aggregate} while in state \"{$state}\".");
    }
}
