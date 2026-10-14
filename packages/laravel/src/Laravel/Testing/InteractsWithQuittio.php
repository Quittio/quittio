<?php

declare(strict_types=1);

namespace Quittio\Laravel\Testing;

use DOMDocument;
use Illuminate\Container\Container;
use PHPUnit\Framework\Assert;
use Quittio\Core\Validation\ValidationMessage;
use Quittio\Laravel\QuittioManager;
use ReflectionClass;
use ReflectionProperty;

/**
 * Assertions for feature tests.
 */
trait InteractsWithQuittio
{
    /**
     * Reads the PDF or XML and validates it with all configured levels.
     */
    public function assertValidEInvoice(string $pdfOrXml, ?string $profile = null): void
    {
        $manager = Container::getInstance()->make(QuittioManager::class);
        $result = $manager->validateFile($pdfOrXml);
        $errors = array_map(static fn (ValidationMessage $m): string => sprintf('[%s] %s', $m->code, $m->message), $result->errors());

        Assert::assertSame([], $errors, "The e-invoice is not valid:\n".implode("\n", $errors));

        if ($profile !== null) {
            $read = $manager->read($pdfOrXml)->profile;
            Assert::assertSame($manager->profiles()->get($profile)->key(), $read, sprintf('Expected profile "%s", found "%s".', $profile, $read ?? 'unknown'));
        }
    }

    /**
     * Compares XML with a stored snapshot (canonicalised); the first run writes it.
     */
    public function assertXmlMatchesSnapshot(string $xml, ?string $name = null): void
    {
        $directory = $this->quittioSnapshotDirectory();
        $name ??= $this->quittioSnapshotName();
        $file = $directory.'/'.preg_replace('/[^A-Za-z0-9_.-]+/', '_', $name).'.xml';
        $actual = self::canonicalXml($xml);

        if (! is_file($file)) {
            if (! is_dir($directory)) {
                mkdir($directory, 0775, true);
            }
            file_put_contents($file, $actual);
            Assert::assertFileExists($file);

            return;
        }

        Assert::assertSame((string) file_get_contents($file), $actual, sprintf('The XML does not match the snapshot %s.', $file));
    }

    protected function quittioSnapshotDirectory(): string
    {
        // Pest generates test classes; the original test file is kept in a static property.
        $file = property_exists(static::class, '__filename')
            ? (new ReflectionProperty(static::class, '__filename'))->getValue()
            : (new ReflectionClass($this))->getFileName();

        return dirname(is_string($file) ? $file : '.').'/__snapshots__/quittio';
    }

    protected function quittioSnapshotName(): string
    {
        return (new ReflectionClass($this))->getShortName().'__'.(method_exists($this, 'name') ? (string) $this->name() : 'xml');
    }

    private static function canonicalXml(string $xml): string
    {
        $document = new DOMDocument;
        $document->preserveWhiteSpace = false;
        $document->formatOutput = true;
        if (! $document->loadXML($xml, LIBXML_NONET)) {
            Assert::fail('The given string is not well-formed XML.');
        }

        return (string) $document->saveXML();
    }
}
