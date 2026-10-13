<?php

declare(strict_types=1);

namespace App\Aggregates\Concerns;

use App\Attributes\OperationAllowedFrom;
use App\Exceptions\IllegalTransition;
use BackedEnum;
use Illuminate\Support\Collection;
use ReflectionAttribute;
use ReflectionMethod;

/**
 * Guards an aggregate operation against its #[OperationAllowedFrom] attributes.
 *
 * The operation is legal when any attribute holds, and an attribute holds when every state property
 * it names is in its allowed list. The aggregate needs a backed-enum `$state` property, plus one for
 * each other key its attributes use.
 */
trait ProtectsInvariants
{
    protected function ensure(): void
    {
        // Frame [1] is the operation that called ensure(). Call ensure() directly from the operation:
        // a wrapper or closure in between becomes frame [1] and the wrong method's attributes are read.
        $operation = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'];

        throw_unless(
            $this->isLegal($operation),
            IllegalTransition::for($this->aggregateName(), $operation, $this->state->value),
        );
    }

    private function isLegal(string $operation): bool
    {
        return $this->allowedFromClauses($operation)->contains(
            fn (array $clause): bool => $this->clauseHolds($clause),
        );
    }

    /**
     * @return Collection<int, array<string, list<BackedEnum>>>
     */
    private function allowedFromClauses(string $operation): Collection
    {
        return collect(new ReflectionMethod($this, $operation)->getAttributes(OperationAllowedFrom::class))
            ->map(fn (ReflectionAttribute $attribute): array => $attribute->newInstance()->allowedFrom);
    }

    /**
     * @param  array<string, list<BackedEnum>>  $clause
     */
    private function clauseHolds(array $clause): bool
    {
        return collect($clause)->every(
            fn (array $allowed, string $property): bool => in_array($this->{$property}, $allowed, strict: true),
        );
    }

    private function aggregateName(): string
    {
        return str(static::class)->classBasename()->beforeLast('Aggregate')->toString();
    }
}
