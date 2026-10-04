<?php

// Laravel's router expects the public directory as its working directory.
chdir(dirname(__DIR__).'/public');

return require dirname(__DIR__).'/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php';
