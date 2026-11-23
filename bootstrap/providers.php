<?php

use App\Providers\AppServiceProvider;
use App\Providers\ChatServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\KnowledgeServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    HorizonServiceProvider::class,
    KnowledgeServiceProvider::class,
    ChatServiceProvider::class,
];
