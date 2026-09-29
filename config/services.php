<?php

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->load('Tknoweb\\AiSqlAssistantBundle\\', '../src/')
        ->exclude(['../src/Contract', '../src/Entity', '../src/Provider', '../src/TknowebAiSqlAssistantBundle.php']);
};
