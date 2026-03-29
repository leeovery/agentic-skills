<?php

declare(strict_types=1);

namespace YourName\MyPackage\Data;

use App\Data\Concerns\HasTestFactory;
use Spatie\LaravelData\Data;

abstract class BaseData extends Data
{
    use HasTestFactory;
}
