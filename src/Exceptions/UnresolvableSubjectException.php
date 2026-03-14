<?php

namespace Spawnflow\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class UnresolvableSubjectException extends HttpException
{
    public function __construct(string $alias)
    {
        parent::__construct(404, "Subject '$alias' is not registered");
    }
}
