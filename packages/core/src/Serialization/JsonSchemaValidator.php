<?php

declare(strict_types=1);

namespace Quittio\Core\Serialization;

use JsonException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Quittio\Core\Exceptions\RuntimeException;

/**
 * Validates JSON input against the shipped invoice schema before hydration
 * (requires the optional package opis/json-schema).
 */
final class JsonSchemaValidator
{
    /**
     * Whether the optional package opis/json-schema is installed; without it validate() throws a
     * RuntimeException.
     */
    public static function available(): bool
    {
        return class_exists(Validator::class);
    }

    /**
     * @return list<string> errors as "path: message"; empty if valid
     */
    public function validate(string $json): array
    {
        if (! self::available()) {
            throw new RuntimeException('Install opis/json-schema to validate JSON input against the schema.');
        }

        try {
            $data = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return ['/: invalid JSON: '.$e->getMessage()];
        }
        $schema = (string) file_get_contents(JsonSchemaGenerator::defaultPath());

        $validator = new Validator;
        $validator->setMaxErrors(20);
        $result = $validator->validate($data, $schema);
        $error = $result->error();
        if ($error === null) {
            return [];
        }

        $messages = [];
        foreach ((new ErrorFormatter)->format($error, true) as $path => $errors) {
            foreach (is_array($errors) ? $errors : [$errors] as $message) {
                $messages[] = $path.': '.(is_string($message) ? $message : 'invalid');
            }
        }

        return $messages;
    }
}
