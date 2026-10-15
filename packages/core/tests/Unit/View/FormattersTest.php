<?php

declare(strict_types=1);

use Brick\Math\BigDecimal;
use Brick\Money\Money;
use Quittio\Core\Data\Party\Address;
use Quittio\Core\Support\Date;
use Quittio\Core\View\CountryNames;
use Quittio\Core\View\InternationalAddressFormatter;
use Quittio\Core\View\IntlFormatter;
use Quittio\Core\View\LetterLayout;
use Quittio\Core\View\Markdown;

it('formats amounts, prices, quantities, percentages and dates per locale', function (string $locale, array $expected) {
    $f = new IntlFormatter;

    // "~" stands for the no-break space before currency and percent signs.
    expect(str_replace("\u{a0}", '~', [
        $f->money(Money::of('1234567.5', 'EUR'), $locale),
        $f->price(BigDecimal::of('0.12345'), 'EUR', $locale),
        $f->quantity(BigDecimal::of('1.500'), $locale),
        $f->quantity(BigDecimal::of('-2'), $locale),
        $f->percent(BigDecimal::of('7.5'), $locale),
        $f->date(Date::parse('2026-03-05'), $locale),
    ]))->toBe($expected);
})->with([
    'German' => ['de', ['1.234.567,50~€', '0,12345~€', '1,5', '-2', '7,5~%', '05.03.2026']],
    'English' => ['en', ['1,234,567.50~€', '0.12345~€', '1.5', '-2', '7.5%', '05/03/2026']],
    'Swiss German' => ['de_CH', ["1'234'567.50~€", '0.12345~€', '1.5', '-2', '7.5~%', '05.03.2026']],
    'US English' => ['en_US', ['1,234,567.50~€', '0.12345~€', '1.5', '-2', '7.5%', '03/05/2026']],
]);

it('keeps all decimals of a unit price but rounds no amount', function () {
    $f = new IntlFormatter;

    expect($f->price(BigDecimal::of('12.3456'), 'EUR', 'de'))->toBe("12,3456\u{a0}€")
        ->and($f->price(BigDecimal::of('12.30'), 'EUR', 'de'))->toBe("12,30\u{a0}€")
        ->and($f->money(Money::of('-12.3', 'USD'), 'en'))->toBe('-$12.30');
});

it('renders a safe Markdown subset and escapes everything else', function () {
    $html = Markdown::toHtml("**fett** <script>alert(1)</script> [Link](javascript:alert(1)) <img src=x onerror=alert(1)>\n\n- a\n- b");

    expect($html)->toContain('<strong>fett</strong>', '&lt;script&gt;', '<ul>', '<li>a</li>')
        ->not->toContain('<script', 'javascript:', '<a ', '<img')
        ->and(Markdown::toHtml("# Heading\n\n`code`"))->not->toContain('<h1', '<code')
        ->and(Markdown::toHtml('   '))->toBe('')
        ->and(Markdown::toHtml(null))->toBe('');
});

it('escapes plain text and keeps its line breaks', function () {
    expect(Markdown::plain("Zeile <b>1</b>\nA & B"))->toBe("Zeile &lt;b&gt;1&lt;/b&gt;<br>\nA &amp; B")
        ->and(Markdown::plain('**not bold**'))->toBe('**not bold**');
});

it('formats addresses by the conventions of the country', function () {
    $formatter = new InternationalAddressFormatter;

    expect($formatter->format(new Address(countryCode: 'US', line1: '1 Main St', city: 'New York', postcode: '10001', subdivision: 'NY'), ['ACME Inc.'], 'DE', 'de'))
        ->toBe(['ACME Inc.', '1 Main St', 'New York NY 10001', 'VEREINIGTE STAATEN'])
        // Domestic: no country line; empty name lines are dropped.
        ->and($formatter->format(Address::make('Weg 1', '12345', 'Stadt', 'DE'), ['Kunde', ' '], 'DE'))->toBe(['Kunde', 'Weg 1', '12345 Stadt'])
        ->and($formatter->format(Address::make('Rue 1', '75002', 'Paris', 'FR'), [], 'DE', 'fr'))->toBe(['Rue 1', '75002 Paris', 'FRANCE']);
});

it('names countries in the language of the invoice', function () {
    expect(CountryNames::name('DE', 'de'))->toBe('Deutschland')
        ->and(CountryNames::name('DE', 'en'))->toBe('Germany')
        ->and(CountryNames::name('AT', 'fr'))->toBe('Autriche');
});

it('computes the letterhead above the address field', function () {
    $layout = new LetterLayout('din5008b', addressTop: 45.0);

    expect($layout->letterheadHeight(10.0))->toBe(35.0)
        ->and($layout->letterheadHeight(60.0))->toBe(0.0)
        ->and(LetterLayout::free()->name)->toBe('free');
});
