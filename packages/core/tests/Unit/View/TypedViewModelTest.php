<?php

declare(strict_types=1);

use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Documents\DocumentBuilder;
use Quittio\Core\Documents\DocumentCalculator;
use Quittio\Core\Documents\Package;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Exceptions\LogicException;
use Quittio\Core\Quittio;
use Quittio\Core\Templates\PhpTemplateEngine;
use Quittio\Core\Testing\Scenarios;
use Quittio\Core\View\AccessLog;
use Quittio\Core\View\FormattedValue;
use Quittio\Core\View\InvoiceViewModel;
use Quittio\Core\View\Model\AddressView;
use Quittio\Core\View\Model\LineView;
use Quittio\Core\View\Model\PartyView;
use Quittio\Core\View\Model\TotalsView;
use Quittio\Core\View\Model\ViewList;
use Quittio\Core\View\ViewContext;
use Quittio\Core\View\ViewModelFactory;
use Quittio\Core\View\ViewNode;
use Quittio\Country\DE\CountryDE;

/*
 * The typed view model (Quittio\Core\View\Model) must not change what templates see: a
 * template written with array syntax before the typed nodes existed and one written against
 * the typed nodes print the same and record the same reads for the mandatory field guard.
 */

const TYPED_TEMPLATES = __DIR__.'/../../Fixtures/templates';

/**
 * @return array<string, Closure(): InvoiceViewModel>
 */
function typedViewModels(): array
{
    $views = [];
    foreach (Scenarios::names() as $scenario) {
        $views[$scenario] = fn () => (new ViewModelFactory)->build(
            (new En16931Calculator)->calculate(Scenarios::make($scenario)->build()),
            new ViewContext(locale: 'de', country: new CountryDE),
        );
    }
    $views['self-billed'] = fn () => (new ViewModelFactory)->build(
        (new En16931Calculator)->calculate(Scenarios::simple()->type('389')->build()),
        new ViewContext(locale: 'de', country: new CountryDE),
    );

    $line = fn (string $name, int $quantity, string $price) => fn ($l) => $l->name($name)->quantity($quantity, 'H87')->netPrice($price)->vat(VatCategory::StandardRate, '19');
    $documents = [
        'quote' => fn () => DocumentBuilder::quote()->validUntil('2026-10-31')->line($line('Consulting', 10, '100.00'))->optionalLine($line('Training', 1, '500.00'))->alternativeLine('1', $line('Remote consulting', 10, '80.00')),
        'delivery note' => fn () => DocumentBuilder::deliveryNote()->reference('ORDER-7', '2026-09-28')->deliveryDate('2026-10-01')
            ->serialisedLine(fn ($l) => $l->name('Laptop')->quantity(2, 'H87'), ['SN-1', 'SN-2'])->package(new Package('1', 'Box', Brick\Math\BigDecimal::of('4.2'))),
        'payment reminder' => fn () => DocumentBuilder::paymentReminder(2)->openItem('INV-42', '2026-08-01', '1190.00', '2026-08-15', '190.00')
            ->fee('Reminder fee', '5.00')->interest('1000.00', '9.27', '2026-08-16', '2026-10-03')->deadline('2026-10-17'),
        'account statement' => fn () => DocumentBuilder::accountStatement()->statementPeriod('2026-09-01', '2026-09-30')->openingBalance('100.00')
            ->openItem('INV-40', '2026-09-02', '500.00', '2026-09-16', '500.00'),
    ];
    foreach ($documents as $name => $builder) {
        $views[$name] = fn () => (new ViewModelFactory)->buildDocument(
            (new DocumentCalculator)->calculate($builder()->number('DOC-1')->issueDate('2026-10-01')
                ->seller(fn ($p) => $p->name('Seller Ltd')->vatId('DE123456789')->address('Main Street 1', '12345', 'Sample City', 'DE'))
                ->buyer(fn ($p) => $p->name('Buyer Inc')->address('Station Road 5', '54321', 'Example Town', 'DE'))
                ->build()),
            new ViewContext(locale: 'de', country: new CountryDE),
        );
    }

    return $views;
}

/**
 * @return array<string, string> property => documented type of a node class
 */
function typedProperties(string $class): array
{
    preg_match_all('/@property-read\s+(\S+)\s+\$(\w+)/', (string) (new ReflectionClass($class))->getDocComment(), $matches, PREG_SET_ORDER);

    return array_column($matches, 1, 2);
}

function typedMatches(mixed $value, string $type): bool
{
    if (str_starts_with($type, '?')) {
        return $value === null || typedMatches($value, substr($type, 1));
    }
    if (preg_match('/^ViewList<(.+)>$/', $type, $match) === 1) {
        if (! $value instanceof ViewList) {
            return false;
        }
        foreach ($value as $entry) {
            if (! typedMatches($entry, $match[1])) {
                return false;
            }
        }

        return true;
    }

    return match ($type) {
        'string' => is_string($value),
        'int' => is_int($value),
        'float' => is_float($value),
        'bool' => is_bool($value),
        'FormattedValue' => $value instanceof FormattedValue,
        'ViewNode' => $value instanceof ViewNode,
        default => is_object($value) && $value::class === 'Quittio\\Core\\View\\Model\\'.$type,
    };
}

/**
 * Every key of the node's data is documented, and its value has the documented type.
 *
 * @param  list<string>  $errors
 */
function typedCheck(ViewNode $node, string $path, array &$errors): void
{
    $data = (fn () => $this->data)->call($node);
    $documented = typedProperties($node::class);
    foreach (array_keys($data) as $key) {
        $type = $documented[$key] ?? null;
        $value = $node->{$key};
        if ($type === null) {
            $errors[] = $path.$key.' is not documented on '.$node::class;

            continue;
        }
        if (! typedMatches($value, $type)) {
            $errors[] = $path.$key.' is '.get_debug_type($value).', documented as '.$type;

            continue;
        }
        typedDescend($value, $path.$key, $errors);
    }
}

/**
 * @param  list<string>  $errors
 */
function typedDescend(mixed $value, string $path, array &$errors): void
{
    if ($value instanceof ViewList) {
        foreach ($value as $key => $entry) {
            typedDescend($entry, $path.'['.$key.']', $errors);
        }
    } elseif ($value instanceof ViewNode && $value::class !== ViewNode::class) {
        typedCheck($value, $path.'.', $errors);
    }
}

/**
 * @return array{string, list<string>} the output and the sorted paths the template read
 */
function typedRender(InvoiceViewModel $view, string $template): array
{
    $html = (new PhpTemplateEngine([TYPED_TEMPLATES]))->render($template, $view);
    $paths = $view->accessLog()->all();
    sort($paths);

    return [$html, $paths];
}

it('prints the same with array syntax and with the typed nodes', function (Closure $view) {
    [$legacy, $legacyReads] = typedRender($view(), 'legacy-arrays');
    [$typed, $typedReads] = typedRender($view(), 'typed-nodes');

    expect($typed)->toBe($legacy)
        ->and($typedReads)->toBe($legacyReads)
        ->and($legacyReads)->not->toBeEmpty()
        ->and($legacy)->toContain('<h1');
})->with(typedViewModels());

it('passes the mandatory field guard with the typed template as with the array template', function (string $scenario) {
    $quittio = Quittio::create(['template_paths' => [TYPED_TEMPLATES], 'mandatory_fields' => 'exception'], [new CountryDE]);
    $render = fn (string $template) => (string) $quittio->make(Scenarios::make($scenario)->build())->format('html')->withoutValidation()->template($template)->render()->html();

    expect($render('typed-nodes'))->toBe($render('legacy-arrays'));
})->with(['simple', 'reverse-charge', 'intra-eu', 'cancellation', 'sub-lines', 'allowances']);

it('documents every node with its type', function (Closure $view) {
    $errors = [];
    typedCheck($view(), '', $errors);

    expect($errors)->toBe([]);
})->with(typedViewModels());

it('gives typed nodes that behave like every other node', function () {
    $view = typedViewModels()['simple']();

    expect($view->seller)->toBeInstanceOf(PartyView::class)->toBeInstanceOf(ViewNode::class)
        ->and($view['seller'])->toBeInstanceOf(PartyView::class)
        ->and($view->seller->address)->toBeInstanceOf(AddressView::class)
        ->and($view['seller']['address']['lines'])->toBeInstanceOf(ViewList::class)
        ->and($view->lines)->toBeInstanceOf(ViewList::class)
        ->and($view->lines[0])->toBeInstanceOf(LineView::class)
        ->and($view->totals)->toBeInstanceOf(TotalsView::class)
        ->and($view->seller->name)->toBe($view['seller']['name'])
        ->and($view->lines->toArray())->toBe($view['lines']->toArray())
        ->and(json_encode($view->seller))->toBe(json_encode($view['seller']->toArray()))
        ->and(count($view->lines))->toBe(count($view['lines']))
        ->and($view->lines->isEmpty())->toBeFalse()
        ->and($view->seller->missing)->toBeNull()
        ->and(isset($view->seller->name))->toBeTrue()
        ->and(fn () => $view->lines[0] = null)->toThrow(LogicException::class, 'read-only')
        ->and(function () use ($view) {
            unset($view->seller['name']);
        })->toThrow(LogicException::class, 'read-only');
});

it('keeps plain nodes outside the view model', function () {
    $log = new AccessLog;
    $plain = new ViewNode(['seller' => ['name' => 'A'], 'lines' => [['name' => 'B']]], $log);
    $own = new class(['seller' => ['name' => 'A']], $log) extends ViewNode {};

    expect($plain->seller::class)->toBe(ViewNode::class)
        ->and($plain->lines::class)->toBe(ViewNode::class)
        ->and($plain->lines[0]::class)->toBe(ViewNode::class)
        ->and($own->seller::class)->toBe(ViewNode::class);
});
