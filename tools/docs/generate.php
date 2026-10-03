<?php

declare(strict_types=1);

/**
 * Regenerates files derived from the data model:
 * - packages/core/resources/schema/invoice.schema.json (JSON Schema of InvoiceData::toArray())
 * - docs/field-reference.md (BT, cardinality, profiles, CII and UBL paths)
 *
 * Usage: php tools/docs/generate.php
 */

use Quittio\Core\Mapping\FieldReference;
use Quittio\Core\Serialization\JsonSchemaGenerator;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';

file_put_contents(JsonSchemaGenerator::defaultPath(), (new JsonSchemaGenerator)->toJson());
file_put_contents($root.'/docs/field-reference.md', (new FieldReference)->markdown());

echo "Schema and field reference written.\n";
