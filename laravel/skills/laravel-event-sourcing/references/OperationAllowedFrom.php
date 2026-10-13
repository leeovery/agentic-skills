<?php

declare(strict_types=1);

namespace App\Attributes;

use Attribute;
use BackedEnum;

/**
 * Declares the states an aggregate operation is legal from.
 *
 *   #[OperationAllowedFrom(OrderState::Placed)]                         state is Placed
 *   #[OperationAllowedFrom([OrderState::Placed, OrderState::Paid])]     state is Placed or Paid
 *   #[OperationAllowedFrom([
 *       'state' => OrderState::Placed,
 *       'paymentState' => [PaymentState::Authorised, PaymentState::Captured],
 *   ])]                                                                 both keys hold
 *
 * Each key names a state property on the aggregate; a single case or a list stands for `state`.
 * Repeat the attribute to allow the operation when any one of them holds.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
readonly class OperationAllowedFrom
{
    /** @var array<string, list<BackedEnum>> */
    public array $allowedFrom;

    /**
     * @param  BackedEnum|list<BackedEnum>|array<string, BackedEnum|list<BackedEnum>>  $allowedFrom
     */
    public function __construct(BackedEnum|array $allowedFrom)
    {
        $this->allowedFrom = self::normalise($allowedFrom);
    }

    /**
     * @param  BackedEnum|list<BackedEnum>|array<string, BackedEnum|list<BackedEnum>>  $allowedFrom
     * @return array<string, list<BackedEnum>>
     */
    private static function normalise(BackedEnum|array $allowedFrom): array
    {
        if ($allowedFrom instanceof BackedEnum) {
            return ['state' => [$allowedFrom]];
        }

        if (array_is_list($allowedFrom)) {
            return ['state' => $allowedFrom];
        }

        return array_map(
            static fn (BackedEnum|array $states): array => $states instanceof BackedEnum ? [$states] : $states,
            $allowedFrom,
        );
    }
}
