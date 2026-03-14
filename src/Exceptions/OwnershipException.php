<?php

namespace Spawnflow\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class OwnershipException extends HttpException
{
    public function __construct(string $message = 'Not authorized to access this resource')
    {
        parent::__construct(403, $message);
    }
}
