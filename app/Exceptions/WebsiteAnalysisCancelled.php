<?php

namespace App\Exceptions;

use RuntimeException;

class WebsiteAnalysisCancelled extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Analysis canceled.');
    }
}
