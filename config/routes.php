<?php

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

// Routes of the chat, to import with a path prefix and a name prefix, the latter set in the "route_name_prefix" configuration as well
return static function (RoutingConfigurator $routes): void {
    $routes->import('../src/Controller/', 'attribute');
};
