<?php

namespace JBadarneh\JoFotara\Traits;

trait ComparesAmounts
{
    /**
     * Whether an amount is greater than a limit by more than rounding noise
     *
     * Amounts are stored and serialized with nine decimals, each rounded on its
     * own, and binary floating point cannot represent most decimals exactly
     * (3 x 1.15 is 3.4499999999999997). A plain comparison therefore refuses
     * correct amounts. A difference counts only when it is larger than what
     * rounding four amounts to nine decimals can produce (two units of the
     * ninth decimal), or than the precision a float has left at that magnitude.
     * Both are far below the 0.001 the integration guide tolerates.
     */
    private function amountExceeds(float $amount, float $limit): bool
    {
        return $amount - $limit > max(2.5e-9, abs($limit) * 1e-14);
    }
}
