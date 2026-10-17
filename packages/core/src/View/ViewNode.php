<?php

declare(strict_types=1);

namespace Quittio\Core\View;

use ArrayAccess;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Quittio\Core\Exceptions\LogicException;
use Quittio\Core\View\Internal\NodeTypes;
use Traversable;

/**
 * Read-only node of the invoice view model with recorded property access.
 *
 * Templates use object syntax ($invoice->seller->name). Every read of a leaf value that is
 * not null (scalar or formatted value) is recorded in the access log, so that the mandatory
 * field guard can tell which values a custom template printed. A value counts as printed
 * when it is read as a leaf: also in a condition such as `if ($invoice->buyer->vatId)` and
 * while a loop reads the items of a list. Counting a list (count(), isEmpty(), isset()) and
 * reading null values are not recorded: count($invoice->legalNotes) prints no note.
 *
 * Nested nodes are typed: $invoice->seller is a Model\PartyView, $invoice->lines a
 * Model\ViewList of Model\LineView. The typed nodes are ViewNodes whose keys are documented
 * as typed properties, so they read, loop, count and record exactly like this class.
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
        return count($this->data);
    }

    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }

    /**
     * Items are read (and recorded) one by one while the template loops over them.
     */
    public function getIterator(): Traversable
    {
        foreach (array_keys($this->data) as $key) {
            yield $key => $this->read($key);
        }
    }

    /**
     * Plain values, e.g. for implode(); counts as reading every value below this node.
     *
     * @return array<string|int, mixed>
     */
    public function toArray(): array
    {
        $this->recordLeaves($this->data, $this->path);

        return self::plain($this->data);
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    protected function read(string|int $key): mixed
    {
        $path = $this->childPath($this->path, $key);
        $value = $this->data[$key] ?? null;
        if (is_array($value)) {
            // A typed node (PartyView, ViewList<LineView> …) where the view model defines one.
            return NodeTypes::node(NodeTypes::child(static::class, $key), $value, $this->log, $path);
        }
        if ($value !== null) {
            $this->log->record($path);
        }

        return $value;
    }

    private function childPath(string $path, string|int $key): string
    {
        return $path === '' ? (string) $key : (is_int($key) ? $path.'['.$key.']' : $path.'.'.$key);
    }

    /**
     * @param  array<string|int, mixed>  $data
     */
    private function recordLeaves(array $data, string $path): void
    {
        foreach ($data as $key => $value) {
            $child = $this->childPath($path, $key);
            if (is_array($value)) {
                $this->recordLeaves($value, $child);
            } elseif ($value !== null) {
                $this->log->record($child);
            }
        }
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
