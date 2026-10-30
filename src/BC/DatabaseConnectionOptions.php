<?php

/**
 * @package   openemr
 * @link      https://www.open-emr.org
 * @author    Eric Stern <erics@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc <https://opencoreemr.com/>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\BC;

use InvalidArgumentException;
use LogicException;
use PDO;
use RuntimeException;
use SensitiveParameter;

/**
 * This class provides a type-safe way to configure database connections while
 * ensuring that sensitive values (password) are protected from leaking in
 * stack traces, var_dump(), and error logs.
 *
 * @internal
 *
 * @phpstan-type DbalParams array{
 *   driver: 'pdo_mysql'|'pdo_pgsql',
 *   dbname: string,
 *   user: string,
 *   password: string,
 *   charset: string,
 *   host?: string,
 *   port?: int,
 *   unix_socket?: string,
 *   driverOptions?: array<int, string>,
 *   sslmode?: string,
 *   sslrootcert?: string,
 *   sslcert?: string,
 *   sslkey?: string,
 * }
 * @phpstan-type SqlConf array{
 *   dbase: string,
 *   login: string,
 *   pass: string,
 *   host?: string,
 *   port?: int|string,
 *   socket?: string,
 *   db_type?: 'mysql'|'pgsql',
 *   db_sslmode?: string,
 * }
 * @phpstan-type ClientCert array{
 *   cert: string,
 *   key: string,
 * }
 * @phpstan-type SslConfig array{
 *   ca?: string,
 *   clientCert?: ClientCert,
 * }
 */
final readonly class DatabaseConnectionOptions
{
    private const REDACTED = '[REDACTED]';

    public string $charset;

    /**
     * @param ClientCert|null $sslClientCert Client cert/key pair for mTLS
     */
    public function __construct(
        public string $dbname,
        public string $user,
        #[SensitiveParameter]
        public string $password,
        public ?string $host = null,
        public ?int $port = null,
        public ?string $unixSocket = null,
        public ?string $sslCaPath = null,
        public ?array $sslClientCert = null,
        public DatabaseBackend $backend = DatabaseBackend::MySQL,
        public string $sslMode = 'verify-full',
    ) {
        $hasHost = $host !== null;
        $hasPort = $port !== null;
        $hasSocket = $unixSocket !== null;

        $hasTcp = $hasHost && $hasPort;
        $hasPartialTcp = $hasHost !== $hasPort;

        if ($hasPartialTcp) {
            throw new InvalidArgumentException(
                'host and port must both be provided together'
            );
        }
        if ($hasTcp && $hasSocket) {
            throw new InvalidArgumentException(
                'Cannot specify both host/port and unixSocket'
            );
        }
        if (!$hasTcp && !$hasSocket) {
            throw new InvalidArgumentException(
                'Must specify either host/port or unixSocket'
            );
        }

        if (!in_array($sslMode, ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'], true)) {
            throw new InvalidArgumentException('Invalid PostgreSQL SSL mode');
        }

        $this->charset = match ($backend) {
            DatabaseBackend::MySQL => 'utf8mb4',
            DatabaseBackend::PostgreSQL => 'UTF8',
        };
    }

    /**
     * Creates options for a site by loading its sqlconf.php file.
     *
     * @param string $siteDir Site directory path (e.g., OE_SITE_DIR)
     */
    public static function forSite(
        string $siteDir,
    ): self {
        $sqlconf = self::loadSqlconf($siteDir);
        $backend = DatabaseBackend::from($sqlconf['db_type'] ?? 'mysql');
        $sslPaths = self::inferSslPaths($siteDir, $backend);

        return self::fromSqlconf($sqlconf, $sslPaths);
    }

    /**
     * Creates options from a pre-parsed sqlconf array.
     *
     * @param SqlConf $sqlconf
     * @param SslConfig $ssl
     */
    public static function fromSqlconf(array $sqlconf, array $ssl = []): self
    {
        $host = $sqlconf['host'] ?? null;
        $port = isset($sqlconf['port']) ? (int) $sqlconf['port'] : null;
        $unixSocket = $sqlconf['socket'] ?? null;

        return new self(
            dbname: $sqlconf['dbase'],
            user: $sqlconf['login'],
            password: $sqlconf['pass'],
            host: $host,
            port: $port,
            unixSocket: $unixSocket,
            sslCaPath: $ssl['ca'] ?? null,
            sslClientCert: $ssl['clientCert'] ?? null,
            backend: DatabaseBackend::from($sqlconf['db_type'] ?? 'mysql'),
            sslMode: $sqlconf['db_sslmode'] ?? 'verify-full',
        );
    }

    /**
     * Detects database SSL certificate files in the site directory.
     *
     * @return SslConfig
     */
    public static function inferSslPaths(string $siteDir, DatabaseBackend $backend = DatabaseBackend::MySQL): array
    {
        $config = [];

        $certDir = sprintf('%s/documents/certificates', $siteDir);
        $prefix = $backend->value;
        $caFile = sprintf('%s/%s-ca', $certDir, $prefix);
        $cert = sprintf('%s/%s-cert', $certDir, $prefix);
        $key = sprintf('%s/%s-key', $certDir, $prefix);

        if (file_exists($caFile)) {
            $config['ca'] = $caFile;
        }
        if (file_exists($cert) || file_exists($key)) {
            if (!file_exists($cert) || !file_exists($key)) {
                throw new LogicException(
                    match ($backend) {
                        DatabaseBackend::MySQL => 'MySQL cert or key file missing. You need both or neither.',
                        DatabaseBackend::PostgreSQL => 'PostgreSQL cert or key file missing. You need both or neither.',
                    }
                );
            }
            $config['clientCert'] = [
                'cert' => $cert,
                'key' => $key,
            ];
        }

        return $config;
    }

    /**
     * @return SqlConf
     */
    private static function loadSqlconf(string $siteDir): array
    {
        $sqlconfPath = $siteDir . '/sqlconf.php';
        if (!file_exists($sqlconfPath)) {
            throw new RuntimeException(sprintf(
                '%s not found in %s. Is the site configured?',
                'sqlconf.php',
                $siteDir,
            ));
        }

        $loader = static function (string $path): array {
            require $path;
            if (!isset($sqlconf) || !is_array($sqlconf)) {
                throw new RuntimeException('sqlconf.php did not define $sqlconf array');
            }
            return $sqlconf;
        };

        /** @var SqlConf $result */
        $result = $loader($sqlconfPath);
        return $result;
    }

    /**
     * Converts the options to the array format expected by Doctrine DBAL's
     * DriverManager::getConnection().
     *
     * @return DbalParams
     */
    public function toDbalParams(): array
    {
        $params = [
            'driver' => match ($this->backend) {
                DatabaseBackend::MySQL => 'pdo_mysql',
                DatabaseBackend::PostgreSQL => 'pdo_pgsql',
            },
            'dbname' => $this->dbname,
            'user' => $this->user,
            'password' => $this->password,
        ];

        if ($this->host !== null) {
            $params['host'] = $this->host;
        }

        if ($this->port !== null) {
            $params['port'] = $this->port;
        }

        if ($this->unixSocket !== null) {
            if ($this->backend === DatabaseBackend::PostgreSQL) {
                $params['host'] = $this->unixSocket;
            } else {
                $params['unix_socket'] = $this->unixSocket;
            }
        }

        $params['charset'] = $this->charset;

        if ($this->backend === DatabaseBackend::PostgreSQL) {
            $params['sslmode'] = $this->sslMode;
            if ($this->sslCaPath !== null) {
                $params['sslrootcert'] = $this->sslCaPath;
            }
            if ($this->sslClientCert !== null) {
                $params['sslcert'] = $this->sslClientCert['cert'];
                $params['sslkey'] = $this->sslClientCert['key'];
            }
            return $params;
        }

        $driverOptions = [];
        if ($this->sslCaPath !== null) {
            // PHP 8.5+ moved MySQL-specific PDO attributes to Pdo\Mysql
            $attrSslCa = class_exists(\Pdo\Mysql::class) ? \Pdo\Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA;
            $driverOptions[$attrSslCa] = $this->sslCaPath;
        }
        if ($this->sslClientCert !== null) {
            $attrSslCert = class_exists(\Pdo\Mysql::class) ? \Pdo\Mysql::ATTR_SSL_CERT : PDO::MYSQL_ATTR_SSL_CERT;
            $attrSslKey = class_exists(\Pdo\Mysql::class) ? \Pdo\Mysql::ATTR_SSL_KEY : PDO::MYSQL_ATTR_SSL_KEY;
            $driverOptions[$attrSslCert] = $this->sslClientCert['cert'];
            $driverOptions[$attrSslKey] = $this->sslClientCert['key'];
        }
        if ($driverOptions !== []) {
            $params['driverOptions'] = $driverOptions;
        }

        return $params;
    }

    /**
     * Controls what is shown when var_dump() or print_r() is called.
     * Sensitive values are redacted.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'dbname' => $this->dbname,
            'user' => $this->user,
            'password' => self::REDACTED,
            'host' => $this->host,
            'port' => $this->port,
            'unixSocket' => $this->unixSocket,
            'charset' => $this->charset,
            'backend' => $this->backend->value,
            'sslMode' => $this->sslMode,
            'sslCaPath' => $this->sslCaPath,
            'sslClientCert' => $this->sslClientCert,
        ];
    }
}
