<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a hunter whose membership restricts them to a single residence
 * state (single_state_hunt, without the multi_state_hunt override) attempts to
 * apply to a listing outside that state. Caught at the controller boundary and
 * surfaced as an upsell message — the authoritative backstop behind the UI's
 * disabled Apply button.
 */
class OutOfStateHuntException extends RuntimeException
{
    public function __construct(
        public readonly string $attemptedState,
        public readonly ?string $lockedState,
    ) {
        $where = $lockedState ? "in {$lockedState}" : 'in your home state';

        parent::__construct(
            "Your membership only covers hunting {$where}, so you can't apply to this {$attemptedState} listing. Upgrade to a multi-state plan to hunt anywhere."
        );
    }
}
