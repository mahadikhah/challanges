<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\TelegramServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    TelegramServiceProvider::class,
];
