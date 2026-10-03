<?php

declare(strict_types=1);

namespace Quittio\Core\View;

/**
 * The only object templates receive (specification 11.6). Every value is available
 * formatted ($value->formatted or {{ $value }}) and raw ($value->raw).
 *
 * Templates contain no calculation logic; all values are calculated beforehand.
 */
final class InvoiceViewModel extends ViewNode
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(array $data, ?AccessLog $log = null)
    {
        parent::__construct($data, $log ?? new AccessLog);
    }

    /**
     * Document title (e.g. "Invoice", "Credit note") for PDF metadata and file names.
     */
    public function title(): string
    {
        $title = $this->data['title'] ?? null;

        return is_string($title) ? $title : '';
    }

    public function accessLog(): AccessLog
    {
        return $this->log;
    }

    /**
     * Translated label; per invoice overrides from the presentation data win.
     *
     * @param  array<string, string>  $replace
     */
    public function label(string $key, array $replace = []): string
    {
        $labels = $this->data['labels'] ?? [];
        $text = is_array($labels) && isset($labels[$key]) && is_string($labels[$key]) ? $labels[$key] : $key;
        foreach ($replace as $name => $value) {
            $text = str_replace(':'.$name, $value, $text);
        }

        return $text;
    }
}
