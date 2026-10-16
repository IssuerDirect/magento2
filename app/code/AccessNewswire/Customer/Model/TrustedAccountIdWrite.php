<?php
declare(strict_types=1);

namespace AccessNewswire\Customer\Model;

/**
 * Lets server-side code that has already decided an AccessWire account ID
 * is legitimate save it from an otherwise untrusted context (e.g. a guest
 * GraphQL request), past GuardAccesswireAccountId. Shared instance, so the
 * plugin sees the same flag as the caller.
 */
class TrustedAccountIdWrite
{
    private int $depth = 0;

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function execute(callable $callback): mixed
    {
        $this->depth++;
        try {
            return $callback();
        } finally {
            $this->depth--;
        }
    }

    public function isActive(): bool
    {
        return $this->depth > 0;
    }
}
