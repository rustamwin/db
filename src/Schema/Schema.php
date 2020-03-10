<?php

declare(strict_types=1);

namespace Yiisoft\Db\Schema;

use Yiisoft\Db\Connection\Connection;
use Yiisoft\Db\Exception\Exception;
use Yiisoft\Db\Exception\IntegrityException;
use Yiisoft\Db\Exception\InvalidCallException;
use Yiisoft\Db\Exception\NotSupportedException;
use Yiisoft\Db\Query\QueryBuilder;
use Yiisoft\Cache\CacheInterface;
use Yiisoft\Cache\Dependency\TagDependency;

/**
 * Schema is the base class for concrete DBMS-specific schema classes.
 *
 * Schema represents the database schema information that is DBMS specific.
 *
 * @property string $lastInsertID The row ID of the last row inserted, or the last value retrieved from the sequence
 * object. This property is read-only.
 * @property QueryBuilder $queryBuilder The query builder for this connection. This property is read-only.
 * @property string[] $schemaNames All schema names in the database, except system schemas. This property is
 * read-only.
 * @property string $serverVersion Server version as a string. This property is read-only.
 * @property string[] $tableNames All table names in the database. This property is read-only.
 * @property TableSchema[] $tableSchemas The metadata for all tables in the database. Each array element is an
 * instance of {@see TableSchema} or its child class. This property is read-only.
 * @property string $transactionIsolationLevel The transaction isolation level to use for this transaction.
 * This can be one of {@see Transaction::READ_UNCOMMITTED}, {@see Transaction::READ_COMMITTED},
 * {@see Transaction::REPEATABLE_READ} and {@see Transaction::SERIALIZABLE} but also a string containing DBMS specific
 * syntax to be used after `SET TRANSACTION ISOLATION LEVEL`. This property is write-only.
 */
abstract class Schema
{
    /* The following are the supported abstract column data types. */
    public const TYPE_PK = 'pk';

    public const TYPE_UPK = 'upk';

    public const TYPE_BIGPK = 'bigpk';

    public const TYPE_UBIGPK = 'ubigpk';

    public const TYPE_CHAR = 'char';

    public const TYPE_STRING = 'string';

    public const TYPE_TEXT = 'text';

    public const TYPE_TINYINT = 'tinyint';

    public const TYPE_SMALLINT = 'smallint';

    public const TYPE_INTEGER = 'integer';

    public const TYPE_BIGINT = 'bigint';

    public const TYPE_FLOAT = 'float';

    public const TYPE_DOUBLE = 'double';

    public const TYPE_DECIMAL = 'decimal';

    public const TYPE_DATETIME = 'datetime';

    public const TYPE_TIMESTAMP = 'timestamp';

    public const TYPE_TIME = 'time';

    public const TYPE_DATE = 'date';

    public const TYPE_BINARY = 'binary';

    public const TYPE_BOOLEAN = 'boolean';

    public const TYPE_MONEY = 'money';

    public const TYPE_JSON = 'json';

    /**
     * Schema cache version, to detect incompatibilities in cached values when the
     * data format of the cache changes.
     */
    public const SCHEMA_CACHE_VERSION = 1;

    /**
     * @var Connection the database connection
     */
    public ?Connection $db = null;

    /**
     * @var string|null the default schema name used for the current session.
     */
    public ?string $defaultSchema = null;

    /**
     * @var array map of DB errors and corresponding exceptions. If left part is found in DB error message exception
     * class from the right part is used.
     */
    public array $exceptionMap = [
        'SQLSTATE[23' => IntegrityException::class,
    ];

    /**
     * @var string|array column schema class or class config
     */
    public string $columnSchemaClass = ColumnSchema::class;

    /**
     * @var string|string[] character used to quote schema, table, etc. names. An array of 2 characters can be used in
     * case starting and ending characters are different.
     */
    protected string $tableQuoteCharacter = "'";
    /**
     * @var string|string[] character used to quote column names. An array of 2 characters can be used in case starting
     * and ending characters are different.
     */
    protected string $columnQuoteCharacter = '"';

    /**
     * @var array list of ALL schema names in the database, except system schemas
     */
    private array $schemaNames = [];

    /**
     * @var array list of ALL table names in the database
     */
    private array $tableNames = [];

    /**
     * @var array list of loaded table metadata (table name => metadata type => metadata).
     */
    private array $tableMetadata = [];

    /**
     * @var QueryBuilder the query builder for this database
     */
    private ?QueryBuilder $builder = null;

    /**
     * @var string server version as a string.
     */
    private ?string $serverVersion = null;

    /**
     * @var CacheInterface $cache
     */
    private CacheInterface $cache;

    public function __construct(Connection $db)
    {
        $this->db = $db;
        $this->cache = $this->db->getSchemaCache();
    }

    /**
     * Resolves the table name and schema name (if any).
     *
     * @param string $name the table name
     *
     * @return void with resolved table, schema, etc. names.
     *
     * @throws NotSupportedException if this method is not supported by the DBMS.
     *
     * {@see \Yiisoft\Db\Schema\TableSchema}
     */
    protected function resolveTableName($name)
    {
        throw new NotSupportedException(get_class($this) . ' does not support resolving table names.');
    }

    /**
     * Returns all schema names in the database, including the default one but not system schemas.
     *
     * This method should be overridden by child classes in order to support this feature because the default
     * implementation simply throws an exception.
     *
     * @return void all schema names in the database, except system schemas.
     *
     * @throws NotSupportedException if this method is not supported by the DBMS.
     */
    protected function findSchemaNames()
    {
        throw new NotSupportedException(get_class($this) . ' does not support fetching all schema names.');
    }

    /**
     * Returns all table names in the database.
     *
     * This method should be overridden by child classes in order to support this feature because the default
     * implementation simply throws an exception.
     *
     * @param string $schema the schema of the tables. Defaults to empty string, meaning the current or default schema.
     *
     * @return void all table names in the database. The names have NO schema name prefix.
     *
     * @throws NotSupportedException if this method is not supported by the DBMS.
     */
    protected function findTableNames($schema = '')
    {
        throw new NotSupportedException(get_class($this) . ' does not support fetching all table names.');
    }

    /**
     * Loads the metadata for the specified table.
     *
     * @param string $name table name
     *
     * @return TableSchema|null DBMS-dependent table metadata, `null` if the table does not exist.
     */
    abstract protected function loadTableSchema(string $name): ?TableSchema;

    /**
     * Creates a column schema for the database.
     *
     * This method may be overridden by child classes to create a DBMS-specific column schema.
     *
     * @return ColumnSchema column schema instance.
     */
    protected function createColumnSchema(): ColumnSchema
    {
        return new $this->columnSchemaClass();
    }

    /**
     * Obtains the metadata for the named table.
     *
     * @param string $name table name. The table name may contain schema name if any. Do not quote the table name.
     * @param bool $refresh whether to reload the table schema even if it is found in the cache.
     *
     * @return TableSchema|null table metadata. `null` if the named table does not exist.
     */
    public function getTableSchema(string $name, bool $refresh = false): ?TableSchema
    {
        return $this->getTableMetadata($name, 'schema', $refresh);
    }

    /**
     * Returns the metadata for all tables in the database.
     *
     * @param string $schema  the schema of the tables. Defaults to empty string, meaning the current or default schema
     * name.
     * @param bool $refresh whether to fetch the latest available table schemas. If this is `false`, cached data may be
     * returned if available.
     *
     * @return TableSchema[] the metadata for all tables in the database.
     *                       Each array element is an instance of [[TableSchema]] or its child class.
     */
    public function getTableSchemas(string $schema = '', bool $refresh = false): array
    {
        return $this->getSchemaMetadata($schema, 'schema', $refresh);
    }

    /**
     * Returns all schema names in the database, except system schemas.
     *
     * @param bool $refresh whether to fetch the latest available schema names. If this is false, schema names fetched
     * previously (if available) will be returned.
     *
     * @return string[] all schema names in the database, except system schemas.
     */
    public function getSchemaNames(bool $refresh = false): array
    {
        if (empty($this->schemaNames) || $refresh) {
            $this->schemaNames = $this->findSchemaNames();
        }

        return $this->schemaNames;
    }

    /**
     * Returns all table names in the database.
     *
     * @param string $schema the schema of the tables. Defaults to empty string, meaning the current or default schema
     * name.
     * If not empty, the returned table names will be prefixed with the schema name.
     * @param bool $refresh whether to fetch the latest available table names. If this is false, table names fetched
     * previously (if available) will be returned.
     *
     * @return string[] all table names in the database.
     */
    public function getTableNames(string $schema = '', bool $refresh = false): array
    {
        if (!isset($this->tableNames[$schema]) || $refresh) {
            $this->tableNames[$schema] = $this->findTableNames($schema);
        }

        return $this->tableNames[$schema];
    }

    /**
     * @return QueryBuilder the query builder for this connection.
     */
    public function getQueryBuilder(): QueryBuilder
    {
        if ($this->builder === null) {
            $this->builder = $this->createQueryBuilder();
        }

        return $this->builder;
    }

    /**
     * Determines the PDO type for the given PHP data value.
     *
     * @param mixed $data the data whose PDO type is to be determined
     *
     * @return int the PDO type
     *
     * @see http://www.php.net/manual/en/pdo.constants.php
     */
    public function getPdoType($data): int
    {
        static $typeMap = [
            // php type => PDO type
            'boolean'  => \PDO::PARAM_BOOL,
            'integer'  => \PDO::PARAM_INT,
            'string'   => \PDO::PARAM_STR,
            'resource' => \PDO::PARAM_LOB,
            'NULL'     => \PDO::PARAM_NULL,
        ];
        $type = gettype($data);

        return $typeMap[$type] ?? \PDO::PARAM_STR;
    }

    /**
     * Refreshes the schema.
     *
     * This method cleans up all cached table schemas so that they can be re-created later to reflect the database
     * schema change.
     */
    public function refresh(): void
    {
        /* @var $cache CacheInterface */
        $cache = \is_string($this->db->getSchemaCache()) ? $this->cache : $this->db->getSchemaCache();

        if ($this->db->isSchemaCacheEnabled() && $cache instanceof CacheInterface) {
            TagDependency::invalidate($cache, $this->getCacheTag());
        }

        $this->tableNames = [];
        $this->tableMetadata = [];
    }

    /**
     * Refreshes the particular table schema.
     *
     * This method cleans up cached table schema so that it can be re-created later to reflect the database schema
     * change.
     *
     * @param string $name table name.
     */
    public function refreshTableSchema(string $name): void
    {
        $rawName = $this->getRawTableName($name);

        unset($this->tableMetadata[$rawName]);

        $this->tableNames = [];

        if ($this->db->isSchemaCacheEnabled() && $this->cache instanceof CacheInterface) {
            $this->cache->delete($this->getCacheKey($rawName));
        }
    }

    /**
     * Creates a query builder for the database.
     *
     * This method may be overridden by child classes to create a DBMS-specific query builder.
     *
     * @return QueryBuilder query builder instance
     */
    public function createQueryBuilder()
    {
        return new QueryBuilder($this->db);
    }

    /**
     * Create a column schema builder instance giving the type and value precision.
     *
     * This method may be overridden by child classes to create a DBMS-specific column schema builder.
     *
     * @param string $type type of the column. See {@see ColumnSchemaBuilder::$type}.
     * @param int|string|array $length length or precision of the column. See {@see ColumnSchemaBuilder::$length}.
     *
     * @return ColumnSchemaBuilder column schema builder instance
     */
    public function createColumnSchemaBuilder(string $type, $length = null)
    {
        return new ColumnSchemaBuilder($type, $length);
    }

    /**
     * Returns all unique indexes for the given table.
     *
     * Each array element is of the following structure:
     *
     * ```php
     * [
     *  'IndexName1' => ['col1' [, ...]],
     *  'IndexName2' => ['col2' [, ...]],
     * ]
     * ```
     *
     * This method should be overridden by child classes in order to support this feature
     * because the default implementation simply throws an exception
     *
     * @param TableSchema $table the table metadata
     *
     * @throws NotSupportedException if this method is called
     *
     * @return array all unique indexes for the given table.
     */
    public function findUniqueIndexes(TableSchema $table)
    {
        throw new NotSupportedException(get_class($this) . ' does not support getting unique indexes information.');
    }

    /**
     * Returns the ID of the last inserted row or sequence value.
     *
     * @param string $sequenceName name of the sequence object (required by some DBMS)
     *
     * @throws InvalidCallException if the DB connection is not active
     *
     * @return string the row ID of the last row inserted, or the last value retrieved from the sequence object
     *
     * @see http://www.php.net/manual/en/function.PDO-lastInsertId.php
     */
    public function getLastInsertID(string $sequenceName = ''): string
    {
        if ($this->db->isActive()) {
            return $this->db->getPDO()->lastInsertId(
                $sequenceName === '' ? null : $this->quoteTableName($sequenceName)
            );
        }

        throw new InvalidCallException('DB Connection is not active.');
    }

    /**
     * @return bool whether this DBMS supports [savepoint](http://en.wikipedia.org/wiki/Savepoint).
     */
    public function supportsSavepoint(): bool
    {
        return $this->db->isSavepointEnabled();
    }

    /**
     * Creates a new savepoint.
     *
     * @param string $name the savepoint name
     */
    public function createSavepoint(string $name): void
    {
        $this->db->createCommand("SAVEPOINT $name")->execute();
    }

    /**
     * Releases an existing savepoint.
     *
     * @param string $name the savepoint name
     */
    public function releaseSavepoint(string $name): void
    {
        $this->db->createCommand("RELEASE SAVEPOINT $name")->execute();
    }

    /**
     * Rolls back to a previously created savepoint.
     *
     * @param string $name the savepoint name
     */
    public function rollBackSavepoint(string $name): void
    {
        $this->db->createCommand("ROLLBACK TO SAVEPOINT $name")->execute();
    }

    /**
     * Sets the isolation level of the current transaction.
     *
     * @param string $level The transaction isolation level to use for this transaction.
     *
     * This can be one of {@see Transaction::READ_UNCOMMITTED}, {@see Transaction::READ_COMMITTED},
     * {@see Transaction::REPEATABLE_READ} and {@see Transaction::SERIALIZABLE} but also a string containing DBMS
     * specific syntax to be used after `SET TRANSACTION ISOLATION LEVEL`.
     *
     * @see http://en.wikipedia.org/wiki/Isolation_%28database_systems%29#Isolation_levels
     */
    public function setTransactionIsolationLevel(string $level): void
    {
        $this->db->createCommand("SET TRANSACTION ISOLATION LEVEL $level")->execute();
    }

    /**
     * Executes the INSERT command, returning primary key values.
     *
     * @param string $table   the table that new rows will be inserted into.
     * @param array  $columns the column data (name => value) to be inserted into the table.
     *
     * @return array|false primary key values or false if the command fails
     */
    public function insert(string $table, array $columns)
    {
        $command = $this->db->createCommand()->insert($table, $columns);

        if (!$command->execute()) {
            return false;
        }

        $tableSchema = $this->getTableSchema($table);
        $result = [];

        foreach ($tableSchema->primaryKey as $name) {
            if ($tableSchema->columns[$name]->autoIncrement) {
                $result[$name] = $this->getLastInsertID($tableSchema->sequenceName);
                break;
            }

            $result[$name] = $columns[$name] ?? $tableSchema->columns[$name]->defaultValue;
        }

        return $result;
    }

    /**
     * Quotes a string value for use in a query.
     * Note that if the parameter is not a string, it will be returned without change.
     *
     * @param string|int $str string to be quoted
     *
     * @return string|int the properly quoted string
     *
     * @see http://www.php.net/manual/en/function.PDO-quote.php
     */
    public function quoteValue($str)
    {
        if (!is_string($str)) {
            return $str;
        }

        if (($value = $this->db->getSlavePdo()->quote($str)) !== false) {
            return $value;
        }

        // the driver doesn't support quote (e.g. oci)
        return "'" . addcslashes(str_replace("'", "''", $str), "\000\n\r\\\032") . "'";
    }

    /**
     * Quotes a table name for use in a query.
     *
     * If the table name contains schema prefix, the prefix will also be properly quoted. If the table name is already
     * quoted or contains '(' or '{{', then this method will do nothing.
     *
     * @param string $name table name
     *
     * @return string the properly quoted table name
     *
     * @see quoteSimpleTableName()
     */
    public function quoteTableName(string $name): string
    {
        if (strpos($name, '(') !== false || strpos($name, '{{') !== false) {
            return $name;
        }

        if (strpos($name, '.') === false) {
            return $this->quoteSimpleTableName($name);
        }

        $parts = explode('.', $name);

        foreach ($parts as $i => $part) {
            $parts[$i] = $this->quoteSimpleTableName($part);
        }

        return implode('.', $parts);
    }

    /**
     * Quotes a column name for use in a query.
     *
     * If the column name contains prefix, the prefix will also be properly quoted. If the column name is already quoted
     * or contains '(', '[[' or '{{', then this method will do nothing.
     *
     * @param string $name column name
     *
     * @return string the properly quoted column name
     *
     * @see quoteSimpleColumnName()
     */
    public function quoteColumnName(string $name): string
    {
        if (strpos($name, '(') !== false || strpos($name, '[[') !== false) {
            return $name;
        }

        if (($pos = strrpos($name, '.')) !== false) {
            $prefix = $this->quoteTableName(substr($name, 0, $pos)) . '.';
            $name = substr($name, $pos + 1);
        } else {
            $prefix = '';
        }

        if (strpos($name, '{{') !== false) {
            return $name;
        }

        return $prefix . $this->quoteSimpleColumnName($name);
    }

    /**
     * Quotes a simple table name for use in a query.
     *
     * A simple table name should contain the table name only without any schema prefix.
     * If the table name is already quoted, this method will do nothing.
     *
     * @param string $name table name
     *
     * @return string the properly quoted table name
     */
    public function quoteSimpleTableName(string $name): string
    {
        if (is_string($this->tableQuoteCharacter)) {
            $startingCharacter = $endingCharacter = $this->tableQuoteCharacter;
        } else {
            [$startingCharacter, $endingCharacter] = $this->tableQuoteCharacter;
        }

        return strpos($name, $startingCharacter) !== false ? $name : $startingCharacter . $name . $endingCharacter;
    }

    /**
     * Quotes a simple column name for use in a query.
     *
     * A simple column name should contain the column name only without any prefix.
     * If the column name is already quoted or is the asterisk character '*', this method will do nothing.
     *
     * @param string $name column name
     *
     * @return string the properly quoted column name
     */
    public function quoteSimpleColumnName(string $name): string
    {
        if (is_string($this->tableQuoteCharacter)) {
            $startingCharacter = $endingCharacter = $this->columnQuoteCharacter;
        } else {
            [$startingCharacter, $endingCharacter] = $this->columnQuoteCharacter;
        }

        return $name === '*' || strpos($name, $startingCharacter) !== false ? $name : $startingCharacter . $name
            . $endingCharacter;
    }

    /**
     * Unquotes a simple table name.
     *
     * A simple table name should contain the table name only without any schema prefix.
     * If the table name is not quoted, this method will do nothing.
     *
     * @param string $name table name.
     *
     * @return string unquoted table name.
     */
    public function unquoteSimpleTableName(string $name): string
    {
        if (\is_string($this->tableQuoteCharacter)) {
            $startingCharacter = $this->tableQuoteCharacter;
        } else {
            $startingCharacter = $this->tableQuoteCharacter[0];
        }

        return strpos($name, $startingCharacter) === false ? $name : substr($name, 1, -1);
    }

    /**
     * Unquotes a simple column name.
     *
     * A simple column name should contain the column name only without any prefix.
     * If the column name is not quoted or is the asterisk character '*', this method will do nothing.
     *
     * @param string $name column name.
     *
     * @return string unquoted column name.
     */
    public function unquoteSimpleColumnName(string $name): string
    {
        if (\is_string($this->columnQuoteCharacter)) {
            $startingCharacter = $this->columnQuoteCharacter;
        } else {
            $startingCharacter = $this->columnQuoteCharacter[0];
        }

        return strpos($name, $startingCharacter) === false ? $name : substr($name, 1, -1);
    }

    /**
     * Returns the actual name of a given table name.
     *
     * This method will strip off curly brackets from the given table name
     * and replace the percentage character '%' with {@see Connection::tablePrefix}.
     *
     * @param string $name the table name to be converted
     *
     * @return string the real name of the given table name
     */
    public function getRawTableName(string $name): string
    {
        if (strpos($name, '{{') !== false) {
            $name = preg_replace('/\\{\\{(.*?)\\}\\}/', '\1', $name);

            return str_replace('%', $this->db->getTablePrefix(), $name);
        }

        return $name;
    }

    /**
     * Extracts the PHP type from abstract DB type.
     *
     * @param ColumnSchema $column the column schema information
     *
     * @return string PHP type name
     */
    protected function getColumnPhpType(ColumnSchema $column): string
    {
        static $typeMap = [
            // abstract type => php type
            self::TYPE_TINYINT  => 'integer',
            self::TYPE_SMALLINT => 'integer',
            self::TYPE_INTEGER  => 'integer',
            self::TYPE_BIGINT   => 'integer',
            self::TYPE_BOOLEAN  => 'boolean',
            self::TYPE_FLOAT    => 'double',
            self::TYPE_DOUBLE   => 'double',
            self::TYPE_BINARY   => 'resource',
            self::TYPE_JSON     => 'array',
        ];

        if (isset($typeMap[$column->type])) {
            if ($column->type === 'bigint') {
                return PHP_INT_SIZE === 8 && !$column->unsigned ? 'integer' : 'string';
            }

            if ($column->type === 'integer') {
                return PHP_INT_SIZE === 4 && $column->unsigned ? 'string' : 'integer';
            }

            return $typeMap[$column->type];
        }

        return 'string';
    }

    /**
     * Converts a DB exception to a more concrete one if possible.
     *
     * @param \Exception $e
     * @param string $rawSql SQL that produced exception
     *
     * @return Exception
     */
    public function convertException(\Exception $e, string $rawSql): Exception
    {
        if ($e instanceof Exception) {
            return $e;
        }

        $exceptionClass = Exception::class;

        foreach ($this->exceptionMap as $error => $class) {
            if (strpos($e->getMessage(), $error) !== false) {
                $exceptionClass = $class;
            }
        }

        $message = $e->getMessage() . "\nThe SQL being executed was: $rawSql";
        $errorInfo = $e instanceof \PDOException ? $e->errorInfo : null;

        return new $exceptionClass($message, $errorInfo, (int) $e->getCode(), $e);
    }

    /**
     * Returns a value indicating whether a SQL statement is for read purpose.
     *
     * @param string $sql the SQL statement
     *
     * @return bool whether a SQL statement is for read purpose.
     */
    public function isReadQuery($sql): bool
    {
        $pattern = '/^\s*(SELECT|SHOW|DESCRIBE)\b/i';

        return preg_match($pattern, $sql) > 0;
    }

    /**
     * Returns a server version as a string comparable by {@see \version_compare()}.
     *
     * @return string server version as a string.
     */
    public function getServerVersion(): string
    {
        if ($this->serverVersion === null) {
            $this->serverVersion = $this->db->getSlavePdo()->getAttribute(\PDO::ATTR_SERVER_VERSION);
        }

        return $this->serverVersion;
    }

    /**
     * Returns the cache key for the specified table name.
     *
     * @param string $name the table name.
     *
     * @return mixed the cache key.
     */
    protected function getCacheKey($name)
    {
        return [
            __CLASS__,
            $this->db->getDsn(),
            $this->db->getUsername(),
            $this->getRawTableName($name),
        ];
    }

    /**
     * Returns the cache tag name.
     * This allows {@see refresh()} to invalidate all cached table schemas.
     *
     * @return string the cache tag name
     */
    protected function getCacheTag(): string
    {
        return md5(serialize([
            __CLASS__,
            $this->db->getDsn(),
            $this->db->getUsername(),
        ]));
    }

    /**
     * Returns the metadata of the given type for the given table.
     *
     * If there's no metadata in the cache, this method will call
     * a `'loadTable' . ucfirst($type)` named method with the table name to obtain the metadata.
     *
     * @param string $name table name. The table name may contain schema name if any. Do not quote the table name.
     * @param string $type metadata type.
     * @param bool   $refresh whether to reload the table metadata even if it is found in the cache.
     *
     * @return mixed metadata.
     */
    protected function getTableMetadata(string $name, string $type, bool $refresh)
    {
        if ($this->db->isSchemaCacheEnabled() && !\in_array($name, $this->db->getSchemaCacheExclude(), true)) {
            $schemaCache = $this->cache;
        }

        $rawName = $this->getRawTableName($name);

        if (!isset($this->tableMetadata[$rawName])) {
            $this->loadTableMetadataFromCache($schemaCache, $rawName);
        }

        if ($refresh || !\array_key_exists($type, $this->tableMetadata[$rawName])) {
            $this->tableMetadata[$rawName][$type] = $this->{'loadTable' . ucfirst($type)}($rawName);
            $this->saveTableMetadataToCache($schemaCache, $rawName);
        }

        return $this->tableMetadata[$rawName][$type];
    }

    /**
     * Returns the metadata of the given type for all tables in the given schema.
     * This method will call a `'getTable' . ucfirst($type)` named method with the table name
     * and the refresh flag to obtain the metadata.
     *
     * @param string $schema the schema of the metadata. Defaults to empty string, meaning the current or default schema
     * name.
     * @param string $type metadata type.
     * @param bool $refresh whether to fetch the latest available table metadata. If this is `false`, cached data may be
     * returned if available.
     *
     * @return array array of metadata.
     */
    protected function getSchemaMetadata(string $schema, string $type, bool $refresh): array
    {
        $metadata = [];
        $methodName = 'getTable' . ucfirst($type);

        foreach ($this->getTableNames($schema, $refresh) as $name) {
            if ($schema !== '') {
                $name = $schema . '.' . $name;
            }
            $tableMetadata = $this->$methodName($name, $refresh);
            if ($tableMetadata !== null) {
                $metadata[] = $tableMetadata;
            }
        }

        return $metadata;
    }

    /**
     * Sets the metadata of the given type for the given table.
     *
     * @param string $name table name.
     * @param string $type metadata type.
     * @param mixed  $data metadata.
     */
    protected function setTableMetadata(string $name, string $type, $data): void
    {
        $this->tableMetadata[$this->getRawTableName($name)][$type] = $data;
    }

    /**
     * Changes row's array key case to lower if PDO's one is set to uppercase.
     *
     * @param array $row row's array or an array of row's arrays.
     * @param bool  $multiple whether multiple rows or a single row passed.
     *
     * @return array normalized row or rows.
     */
    protected function normalizePdoRowKeyCase(array $row, bool $multiple): array
    {
        if ($this->db->getSlavePdo()->getAttribute(\PDO::ATTR_CASE) !== \PDO::CASE_UPPER) {
            return $row;
        }

        if ($multiple) {
            return \array_map(function (array $row) {
                return \array_change_key_case($row, CASE_LOWER);
            }, $row);
        }

        return \array_change_key_case($row, CASE_LOWER);
    }

    /**
     * Tries to load and populate table metadata from cache.
     *
     * @param CacheInterface|null $cache
     * @param string $name
     */
    private function loadTableMetadataFromCache(?CacheInterface $cache, string $name): void
    {
        if ($cache === null) {
            $this->tableMetadata[$name] = [];

            return;
        }

        $metadata = $cache->get($this->getCacheKey($name));

        if (!\is_array($metadata) || !isset($metadata['cacheVersion']) || $metadata['cacheVersion'] !== static::SCHEMA_CACHE_VERSION) {
            $this->tableMetadata[$name] = [];

            return;
        }

        unset($metadata['cacheVersion']);
        $this->tableMetadata[$name] = $metadata;
    }

    /**
     * Saves table metadata to cache.
     *
     * @param CacheInterface|null $cache
     * @param string $name
     */
    private function saveTableMetadataToCache(?CacheInterface $cache, string $name): void
    {
        if ($cache === null) {
            return;
        }

        $metadata = $this->tableMetadata[$name];

        $metadata['cacheVersion'] = static::SCHEMA_CACHE_VERSION;

        $cache->set(
            $this->getCacheKey($name),
            $metadata,
            $this->db->getSchemaCacheDuration(),
            new TagDependency(['tags' => $this->getCacheTag()])
        );
    }
}