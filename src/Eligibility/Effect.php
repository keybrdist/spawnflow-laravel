<?php

namespace Spawnflow\Eligibility;

/**
 * What an eligibility rule does when its condition passes.
 *
 * Show/Hide govern visibility; Enable/Disable govern editability.
 * Polarity: Show means "visible iff condition passes", Hide means
 * "hidden iff condition passes" — same for Enable/Disable.
 */
enum Effect: string
{
    case Show = 'show';
    case Hide = 'hide';
    case Enable = 'enable';
    case Disable = 'disable';

    public function governsVisibility(): bool
    {
        return $this === self::Show || $this === self::Hide;
    }
}
