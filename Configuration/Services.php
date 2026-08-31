<?php

declare(strict_types=1);

use MaikSchneider\TcaApi\ConfigurationModuleProvider\TcaApiConfigurationProvider;
use MaikSchneider\TcaApi\Controller\ApiDocumentationController;
use MaikSchneider\TcaApi\Serializer\FileProcessing\FileProcessorInterface;
use MaikSchneider\TcaApi\Serializer\Processing\ColumnProcessorInterface;
use MaikSchneider\TcaApi\Serializer\Processing\PreloadingProcessorInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;

return static function (ContainerConfigurator $containerConfigurator, ContainerBuilder $containerBuilder): void {
    $services = $containerConfigurator->services();
    // Preloading processors cache collection-specific state on the instance.
    // Autoconfiguration gives every implementation a fresh instance per lookup
    // without walking (and thereby autoloading) every container definition.
    $containerBuilder->registerForAutoconfiguration(PreloadingProcessorInterface::class)
        ->setShared(false);

    // Processors are named by class-string in a resource config and built with
    // GeneralUtility::makeInstance(), which injects constructor dependencies only
    // for services the container exposes. Extensions default to `public: false`,
    // so a private processor definition is dropped during compilation and
    // makeInstance() falls back to `new` — a processor with a constructor then
    // dies with an ArgumentCountError pointing nowhere near the cause.
    //
    // Autoconfiguration marks them public wherever they are declared, without this
    // extension having to walk (and thereby autoload) every definition in the
    // container.
    foreach ([ColumnProcessorInterface::class, FileProcessorInterface::class] as $processorInterface) {
        $containerBuilder->registerForAutoconfiguration($processorInterface)->setPublic(true);
    }

    // The "Integrations" backend module (Swagger UI) depends on the v14-only
    // ComponentFactory. Excluded from the Classes/* autowiring glob in
    // Services.yaml and wired here only when running on TYPO3 v14+.
    if (class_exists(ComponentFactory::class)) {
        $services->set(ApiDocumentationController::class)
            ->autowire()
            ->autoconfigure();
    }

    if (!class_exists(\TYPO3\CMS\Lowlevel\ConfigurationModuleProvider\AbstractProvider::class)) {
        return;
    }

    $services->set(TcaApiConfigurationProvider::class)
        ->autowire()
        ->autoconfigure()
        ->tag('lowlevel.configuration.module.provider', [
            'identifier' => 'tcaApiConfiguration',
            'label'      => 'LLL:EXT:tca_api/Resources/Private/Language/locallang.xlf:module.configuration.provider.label',
        ]);
};
