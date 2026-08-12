<?php

namespace App\Support;

final class MetadataValidationException extends \RuntimeException
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(' ', $errors) ?: 'Metadata change rejected.');
    }
}
