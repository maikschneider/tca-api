<?php

declare(strict_types=1);

use MaikSchneider\TcaApi\ConfigurationModuleProvider\TcaApiConfigurationProvider;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator, ContainerBuilder $containerBuilder): void {
    if (!class_exists(\TYPO3\CMS\Lowlevel\ConfigurationModuleProvider\AbstractProvider::class)) {
        return;
    }

    $containerConfigurator->services()
        ->set(TcaApiConfigurationProvider::class)
        ->autowire()
        ->autoconfigure()
        ->tag('lowlevel.configuration.module.provider', [
            'identifier' => 'tcaApiConfiguration',
            'label'      => 'LLL:EXT:tca_api/Resources/Private/Language/locallang.xlf:module.configuration.provider.label',
        ]);
};
