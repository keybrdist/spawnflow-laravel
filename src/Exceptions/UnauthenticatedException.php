<?php

namespace Spawnflow\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class UnauthenticatedException extends HttpException
{
    public function __construct(string $message = 'Unauthenticated')
    {
        parent::__construct(401, $message);
    }
}
