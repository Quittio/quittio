<?php

declare(strict_types=1);

namespace Quittio\Laravel\Commands;

use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Validation\ValidationException;
use Quittio\Laravel\QuittioManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'quittio:render')]
final class RenderCommand extends QuittioCommand
{
    protected $signature = 'quittio:render
        {invoice? : Invoice of the Managed layer (not available in this release)}
        {--json= : JSON file with invoice data (InvoiceData::toJson())}
        {--format= : Output format, default: automatic choice}
        {--profile= : Profile}
        {--output= : Target directory or file}';

    protected $description = 'Render an invoice from a JSON file';

    public function handle(QuittioManager $quittio): int
    {
        if ($this->stringArgument('invoice') !== null) {
            $this->components->error('Rendering stored invoices needs the Managed layer, which is not part of this release. Use --json.');

            return self::FAILURE;
        }

        $file = $this->stringOption('json');
        if ($file === null) {
            $this->components->error('Pass the invoice data with --json=path/to/invoice.json.');

            return self::FAILURE;
        }
        $json = $this->readFile($file);
        if ($json === null) {
            return self::FAILURE;
        }

        try {
            $pending = $quittio->make(InvoiceData::fromJson($json));
            $format = $this->stringOption('format');
            if ($format !== null) {
                $pending->format($format);
            }
            $profile = $this->stringOption('profile');
            if ($profile !== null) {
                $pending->profile($profile);
            }
            $result = $pending->render();
        } catch (ValidationException $e) {
            $this->components->error('The invoice is not valid.');
            $this->printValidation($e->result);

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $cwd = getcwd();
        $output = $this->stringOption('output') ?? ($cwd === false ? '.' : $cwd);
        $path = is_dir($output) ? rtrim($output, '/').'/'.$result->filename() : $output;
        file_put_contents($path, $result->contents());

        $this->components->info(sprintf('%s (%s, profile %s) written to %s', $result->data()->number, $result->format(), $result->profile()?->key() ?? '–', $path));
        if ($result->validation()->hasWarnings()) {
            $this->printValidation($result->validation());
        }

        return self::SUCCESS;
    }
}
