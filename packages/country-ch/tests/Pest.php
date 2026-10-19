<?php

declare(strict_types=1);

use Quittio\Core\I18n\Translations;

uses()->beforeEach(function () {
    Translations::reset();
})->in(__DIR__);
