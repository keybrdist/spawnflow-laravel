<?php

namespace Spawnflow\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class ForbiddenFieldAccessException extends HttpException
{
    public function __construct(string $message = 'No editable fields available for this context')
    {
        parent::__construct(403, $message);
    }
}
