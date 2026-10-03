<?php

declare(strict_types=1);

use Quittio\Core\I18n\Translations;

/*
|--------------------------------------------------------------------------
| Core test bootstrap
|--------------------------------------------------------------------------
| Each test starts with a fresh translator, so that tests cannot influence
| each other through registered translation paths.
*/

uses()->beforeEach(fn () => Translations::reset())->in(__DIR__);
