<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tca;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use TYPO3\CMS\Core\Cache\Frontend\PhpFrontend;
use TYPO3\CMS\Core\Database\Schema\Parser\Parser;
use TYPO3\CMS\Core\Database\Schema\SqlReader;
use TYPO3\CMS\Core\Package\Cache\PackageDependentCacheIdentifier;
use TYPO3\CMS\Core\Package\PackageManager;

/**
 * JSON schemas of the columns the ext_tables.sql files of the active extensions define.
 *
 * Such a column keeps its declared type, whatever its TCA says, and the database
 * driver returns its value accordingly: tt_content.colPos is an INT although its
 * TCA is a select. Read from the files only, so building the OpenAPI spec still
 * needs no database connection.
 */
class SqlColumnTypes
{
    /** @var array<string, array<string, array<string, mixed>>>|null table => column => schema */
    private ?array $schemas = null;

    public function __construct(
        private readonly SqlReader $sqlReader,
        private readonly Parser $parser,
        private readonly PackageManager $packageManager,
        #[Autowire(service: 'cache.core')]
        private readonly PhpFrontend $cache,
    ) {
    }

    /**
     * @return array<string, mixed>|null null when no ext_tables.sql defines the column
     */
    public function schema(string $table, string $column): ?array
    {
        $this->schemas ??= $this->load();

        return $this->schemas[$table][$column] ?? null;
    }

    /**
     * The value the database driver returns for a column of this type.
     *
     * @return array<string, mixed>|null null for a type whose value isn't known
     */
    public static function schemaFor(Column $column): ?array
    {
        $schema = match (Type::lookupName($column->getType())) {
            // The driver returns an unsigned BIGINT beyond PHP_INT_MAX as a string.
            Types::BIGINT => $column->getUnsigned() ? ['oneOf' => [['type' => 'integer'], ['type' => 'string']]] : ['type' => 'integer'],
            Types::SMALLINT, Types::INTEGER, Types::BOOLEAN => ['type' => 'integer'],
            Types::FLOAT, Types::SMALLFLOAT => ['type' => 'number'],
            Types::DECIMAL, Types::STRING, Types::ASCII_STRING, Types::TEXT, Types::GUID, Types::JSON,
            Types::DATE_MUTABLE, Types::DATETIME_MUTABLE, Types::TIME_MUTABLE => ['type' => 'string'],
            default => null,
        };
        if ($schema === null || $column->getNotnull()) {
            return $schema;
        }

        return ['oneOf' => [$schema, ['type' => 'null']]];
    }

    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function load(): array
    {
        $cacheIdentifier = (new PackageDependentCacheIdentifier($this->packageManager))
            ->withPrefix('TcaApiSqlColumnTypes')
            ->toString();

        $schemas = $this->cache->require($cacheIdentifier);
        if (\is_array($schemas)) {
            return $schemas;
        }

        // Read the files directly: SqlReader::getTablesDefinitionString() dispatches an event
        // whose core listener sets up every configured cache backend, e.g. Redis, which an
        // offline spec build doesn't have.
        $sql = [];
        foreach ($this->packageManager->getActivePackages() as $package) {
            $file = $package->getPackagePath() . 'ext_tables.sql';
            if (is_file($file)) {
                $sql[] = (string)file_get_contents($file);
            }
        }

        $schemas = [];
        foreach ($this->sqlReader->getCreateTableStatementArray(implode("\n\n", $sql)) as $statement) {
            foreach ($this->parser->parse($statement) as $table) {
                $tableName = self::trimQuotes($table->getName());
                // Later definitions overrule earlier ones, as in SchemaMigrator.
                foreach ($table->getColumns() as $column) {
                    $columnName = self::trimQuotes($column->getName());
                    $schema     = self::schemaFor($column);
                    if ($schema !== null) {
                        $schemas[$tableName][$columnName] = $schema;
                    } else {
                        unset($schemas[$tableName][$columnName]);
                    }
                }
            }
        }

        $this->cache->set($cacheIdentifier, 'return ' . var_export($schemas, true) . ';');

        return $schemas;
    }

    private static function trimQuotes(string $identifier): string
    {
        return str_replace(['`', '"', '[', ']'], '', $identifier);
    }
}
