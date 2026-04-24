<?php

namespace App\Exceptions;

use App\DTO\RuleResult;
use Exception;

class RuleResultException extends Exception
{
    public function __construct(public RuleResult $result)
    {
        parent::__construct($result->message);
    }
}
