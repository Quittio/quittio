<?php

declare(strict_types=1);

namespace Quittio\Core\View;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use LogicException;
use Traversable;

/**
 * Read-only node of the invoice view model with recorded property access.
 *
 * Templates use object syntax ($invoice->seller->name). Every read is recorded
 * in the access log, so that the mandatory field guard can tell which values a
 * custom template actually printed.
 *
 * @implements ArrayAccess<string|int, mixed>
 * @implements IteratorAggregate<string|int, mixed>
 */
class ViewNode implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable
{
    /**
     * @param  array<string|int, mixed>  $data
     */
    public function __construct(
        protected array $data,
        protected AccessLog $log,
        protected string $path = '',
    ) {}

    public function __get(string $name): mixed
    {
        return $this->read($name);
    }

    public function __isset(string $name): bool
    {
        return isset($this->data[$name]);
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->data[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->read($offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('The view model is read-only.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('The view model is read-only.');
    }

    public function count(): int
    {
        $this->log->record($this->path);

        return count($this->data);
    }

    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }

    public function getIterator(): Traversable
    {
        $this->log->record($this->path);
        $items = [];
        foreach (array_keys($this->data) as $key) {
            $items[$key] = $this->read($key);
        }

        return new ArrayIterator($items);
    }

    /**
     * @return array<string|int, mixed>
     */
    public function toArray(): array
    {
        return self::plain($this->data);
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    protected function read(string|int $key): mixed
    {
        $path = $this->path === '' ? (string) $key : (is_int($key) ? $this->path.'['.$key.']' : $this->path.'.'.$key);
        $this->log->record($path);

        $value = $this->data[$key] ?? null;

        return is_array($value) ? new self($value, $this->log, $path) : $value;
    }

    /**
     * @param  array<string|int, mixed>  $data
     * @return array<string|int, mixed>
     */
    private static function plain(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $result[$key] = match (true) {
                is_array($value) => self::plain($value),
                $value instanceof FormattedValue => $value->formatted,
                default => $value,
            };
        }

        return $result;
    }
}
