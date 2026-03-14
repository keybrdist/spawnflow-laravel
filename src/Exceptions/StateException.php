<?php

namespace Spawnflow\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class StateException extends HttpException
{
    public function __construct(string $message = 'Operation not allowed in the current state')
    {
        parent::__construct(409, $message);
    }
}
