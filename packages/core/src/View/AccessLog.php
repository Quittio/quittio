<?php

declare(strict_types=1);

namespace Quittio\Core\View;

/**
 * Records which view model fields a template read (for the mandatory field guard).
 */
final class AccessLog
{
    /** @var array<string, true> */
    private array $paths = [];

    public function record(string $path): void
    {
        $this->paths[$path] = true;
    }

    /**
     * Whether $path or any field below it was read.
     */
    public function accessed(string $path): bool
    {
        if (isset($this->paths[$path])) {
            return true;
        }

        foreach (array_keys($this->paths) as $accessed) {
            if (str_starts_with($accessed, $path.'.') || str_starts_with($accessed, $path.'[')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return array_keys($this->paths);
    }

    public function reset(): void
    {
        $this->paths = [];
    }
}
