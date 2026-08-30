<?php

declare(strict_types=1);

use MaikSchneider\TcaApi\ConfigurationModuleProvider\TcaApiConfigurationProvider;
use MaikSchneider\TcaApi\Controller\ApiDocumentationController;
use MaikSchneider\TcaApi\DependencyInjection\PreloadingProcessorCompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;

return static function (ContainerConfigurator $containerConfigurator, ContainerBuilder $containerBuilder): void {
    $services = $containerConfigurator->services();
    $containerBuilder->addCompilerPass(new PreloadingProcessorCompilerPass());

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
