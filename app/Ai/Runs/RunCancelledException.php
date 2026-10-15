<?php

namespace App\Ai\Runs;

use RuntimeException;

class RunCancelledException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('De AI-taak is geannuleerd.');
    }
}
