<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Command;

use MaikSchneider\TcaApi\Dispatcher\RequestContext;
use MaikSchneider\TcaApi\OpenApi\OpenApiBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Writes the OpenAPI spec of a site, as /_api/openapi.json serves it, without a
 * running site or database: the spec only depends on the TCA, the resource configs
 * and the site settings. A CI build can generate client types from it.
 */
#[AsCommand('tca-api:openapi', 'Writes the OpenAPI spec of a site, as /_api/openapi.json serves it.')]
final class OpenApiSpecCommand extends Command
{
    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly OpenApiBuilder $openApiBuilder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('site', InputArgument::OPTIONAL, 'Identifier of the site; may be omitted when there is only one')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'File to write the spec to instead of stdout');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $identifier = $input->getArgument('site');
        $sites      = $this->siteFinder->getAllSites();
        if (!\is_string($identifier) || $identifier === '') {
            if (\count($sites) !== 1) {
                $output->writeln('<error>Pass the site identifier, one of: ' . implode(', ', array_keys($sites)) . '</error>');
                return Command::INVALID;
            }
            $identifier = (string)array_key_first($sites);
        }

        try {
            $site = $this->siteFinder->getSiteByIdentifier($identifier);
        } catch (SiteNotFoundException) {
            $output->writeln('<error>No site "' . $identifier . '", available: ' . implode(', ', array_keys($sites)) . '</error>');
            return Command::INVALID;
        }

        $language = $site->getDefaultLanguage();
        $request  = (new ServerRequest($language->getBase(), 'GET'))
            ->withAttribute('site', $site)
            ->withAttribute('language', $language);
        $spec = $this->openApiBuilder->build(new RequestContext($site->getSettings(), $request));

        // Same encoding as RequestDispatcher::serveOpenApiSpec(), so both outputs are identical.
        $json = (string)json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $file = $input->getOption('output');
        if (\is_string($file) && $file !== '') {
            if (file_put_contents($file, $json . "\n") === false) {
                $output->writeln('<error>Could not write ' . $file . '</error>');
                return Command::FAILURE;
            }
            return Command::SUCCESS;
        }

        $output->writeln($json, OutputInterface::OUTPUT_RAW);

        return Command::SUCCESS;
    }
}
