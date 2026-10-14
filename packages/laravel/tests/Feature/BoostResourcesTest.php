<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

// Laravel Boost reads guidelines and skills from resources/boost of the packages an application
// requires directly. They are shipped by quittio/laravel and, as a copy, by quittio/quittio
// (the root of the monorepo).

function boostResources(): string
{
    return dirname(__DIR__, 2).'/resources/boost';
}

it('ships the Boost guidelines and the quittio-development skill', function () {
    $skill = (string) file_get_contents(boostResources().'/skills/quittio-development/SKILL.md');

    expect(boostResources().'/guidelines/core.blade.php')->toBeFile()
        ->and($skill)->toStartWith("---\nname: quittio-development\ndescription: ")
        ->and($skill)->toContain('https://docs.quittio.dev/mcp');
});

it('renders the Boost guidelines with Blade', function () {
    $html = Blade::render((string) file_get_contents(boostResources().'/guidelines/core.blade.php'));

    expect($html)->toStartWith('## Quittio')
        ->and($html)->toContain('<code-snippet name="Build and render a German invoice" lang="php">')
        ->and($html)->toContain('Seller::apply(InvoiceBuilder::make())')
        ->and($html)->not->toContain('@verbatim');
});

it('keeps the copy of quittio/quittio identical', function () {
    $root = dirname(__DIR__, 4).'/resources/boost';
    if (! is_dir($root)) {
        $this->markTestSkipped('The copy exists only in the monorepo.');
    }

    foreach (['guidelines/core.blade.php', 'skills/quittio-development/SKILL.md'] as $file) {
        expect(file_get_contents($root.'/'.$file))->toBe(file_get_contents(boostResources().'/'.$file), $file);
    }
});
