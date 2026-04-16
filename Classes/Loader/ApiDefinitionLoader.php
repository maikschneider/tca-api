<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Loader;

use MaikSchneider\TcaApi\Registry\ApiRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Finder\Finder;
use TYPO3\CMS\Core\Cache\Frontend\PhpFrontend;
use TYPO3\CMS\Core\Package\Cache\PackageDependentCacheIdentifier;
use TYPO3\CMS\Core\Package\PackageManager;

#[Autoconfigure(public: true)]
final readonly class ApiDefinitionLoader
{
    public function __construct(
        private PackageManager $packageManager,
        #[Autowire(service: 'cache.core')]
        private PhpFrontend $cache,
    ) {
    }

    public function load(): void
    {
        $cacheIdentifier = (new PackageDependentCacheIdentifier($this->packageManager))
            ->withPrefix('TcaApiDefinitions')
            ->toString();

        $definitions = $this->cache->require($cacheIdentifier);
        if (!is_array($definitions)) {
            $definitions = $this->collectDefinitions();
            $this->cache->set(
                $cacheIdentifier,
                'return ' . var_export($definitions, true) . ';',
            );
        }

        foreach ($definitions as $resourceName => $config) {
            ApiRegistry::register($resourceName, $config);
        }
    }

    private function collectDefinitions(): array
    {
        $definitions = [];
        foreach ($this->packageManager->getActivePackages() as $package) {
            $dir = $package->getPackagePath() . 'Configuration/TcaApi/';
            if (!is_dir($dir)) {
                continue;
            }
            $finder = Finder::create()->files()->name('*.php')->in($dir)->depth(0)->sortByName();
            foreach ($finder as $file) {
                $config = require $file->getPathname();
                $resourceName = $config['general']['resourceName']
                    ?? strtolower(basename($file->getFilename(), '.php'));
                $definitions[$resourceName] = $config;
            }
        }
        return $definitions;
    }
}
