<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidPostcodeRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $this->isValidPostcode($value)) {
            $fail('The :attribute must be a valid UK postcode.');
        }
    }

    private function isValidPostcode(string $postcode): bool
    {
        $pattern = '/^[A-Z]{1,2}\d{1,2}[A-Z]?\s?\d[A-Z]{2}$/i';
        return (bool) preg_match($pattern, $postcode);
    }
}
