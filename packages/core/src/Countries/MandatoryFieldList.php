<?php

declare(strict_types=1);

namespace Quittio\Core\Countries;

/**
 * Values that must be output in the PDF for one invoice.
 */
final readonly class MandatoryFieldList
{
    /**
     * @param  list<MandatoryField>  $fields
     */
    public function __construct(
        public array $fields = [],
    ) {}

    public function merge(self $other): self
    {
        $byKey = [];
        foreach ([...$this->fields, ...$other->fields] as $field) {
            $byKey[$field->accessKey] = $field;
        }

        return new self(array_values($byKey));
    }
}
