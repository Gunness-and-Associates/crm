<?php

namespace App\Support\SchemaManager;

final class SchemaValidationException extends \RuntimeException
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(' ', $errors) ?: 'Schema change rejected.');
    }
}
