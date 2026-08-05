<?php

namespace App\Support;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Validates a layout definition against the frozen contract
 * (resources/contracts/layout.schema.json) — the backend↔frontend interface.
 */
final class LayoutValidator
{
    /**
     * @param  array<string, mixed>  $layout
     * @return list<string> validation errors; empty means valid
     */
    public function errors(array $layout): array
    {
        $data = json_decode((string) json_encode($layout));
        $schema = json_decode((string) file_get_contents(resource_path('contracts/layout.schema.json')));

        if (! is_object($schema)) {
            throw new \RuntimeException('Invalid layout contract schema.');
        }

        $error = (new Validator)->validate($data, $schema)->error();

        if ($error === null) {
            return [];
        }

        $messages = [];
        foreach ((new ErrorFormatter)->formatFlat($error) as $message) {
            $messages[] = is_string($message) ? $message : (string) json_encode($message);
        }

        return $messages;
    }

    /**
     * @param  array<string, mixed>  $layout
     */
    public function valid(array $layout): bool
    {
        return $this->errors($layout) === [];
    }
}
