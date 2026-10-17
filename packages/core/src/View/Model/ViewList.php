<?php

declare(strict_types=1);

namespace Quittio\Core\View\Model;

use Quittio\Core\View\AccessLog;
use Quittio\Core\View\Internal\NodeTypes;
use Quittio\Core\View\ViewNode;
use Traversable;

/**
 * A list (or a map, such as "labels") of the view model whose entries have one type, e.g.
 * ViewList<LineView> for "lines" or ViewList<string> for "legalNotes".
 *
 * It behaves like every other node: loop over it, read entries with $list[0], count() it,
 * call isEmpty() or toArray(). Entries are recorded for the mandatory field guard as the
 * template reads them.
 *
 * @template-covariant T
 */
final class ViewList extends ViewNode
{
    /**
     * @internal created by the view model
     *
     * @param  array<string|int, mixed>  $data
     * @param  string  $items  type of the entries that are arrays (see NodeTypes)
     */
    public function __construct(array $data, AccessLog $log, string $path = '', private readonly string $items = '')
    {
        parent::__construct($data, $log, $path);
    }

    /**
     * Entries are read (and recorded) one by one while the template loops over them.
     *
     * @return Traversable<string|int, T>
     */
    public function getIterator(): Traversable
    {
        /** @var Traversable<string|int, T> $entries */
        $entries = parent::getIterator();

        yield from $entries;
    }

    /**
     * The entry at $offset; null when it is missing.
     *
     * @return T|null
     */
    public function offsetGet(mixed $offset): mixed
    {
        /** @var T|null $entry */
        $entry = parent::offsetGet($offset);

        return $entry;
    }

    protected function read(string|int $key): mixed
    {
        $value = $this->data[$key] ?? null;
        if (is_array($value)) {
            $path = match (true) {
                $this->path === '' => (string) $key,
                is_int($key) => $this->path.'['.$key.']',
                default => $this->path.'.'.$key,
            };

            return NodeTypes::node($this->items, $value, $this->log, $path);
        }

        return parent::read($key);
    }
}
