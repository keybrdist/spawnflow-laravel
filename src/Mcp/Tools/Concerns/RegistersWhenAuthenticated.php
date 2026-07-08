<?php

namespace Spawnflow\Mcp\Tools\Concerns;

use Illuminate\Support\Facades\Auth;

/**
 * Runtime CRUD tools act AS a user — without one they could only fail at
 * auth(). Same absence-not-guard principle as the dev tools: over a bare
 * stdio session (no authenticated user) they are absent from tools/list;
 * over the web transport the auth middleware guarantees a user.
 */
trait RegistersWhenAuthenticated
{
    public function eligibleForRegistration(): bool
    {
        return Auth::check();
    }
}
