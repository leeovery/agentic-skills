<?php

declare(strict_types=1);

namespace App\Data\Formatters;

class EmailFormatter
{
    public static function format(string $email): string
    {
        return str($email)->lower()->trim()->value();
    }
}
