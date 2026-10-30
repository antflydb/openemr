# PostgreSQL and Antfly port

This fork targets PostgreSQL first, then Antfly's PostgreSQL protocol and SQL
implementation. PostgreSQL is the reference for distinguishing application
migration defects from Antfly compatibility gaps.

## Current status

The connection configuration and Doctrine DBAL factory support PostgreSQL.
The EHR application does **not** yet run on PostgreSQL or Antfly. Its legacy
audited ADOdb path and MySQLi installer deliberately reject PostgreSQL
configuration rather than silently connecting to MySQL.

Existing sites default to MySQL. An experimental PostgreSQL configuration uses
the following additional entries in a site's `$sqlconf` array:

```php
$sqlconf['db_type'] = 'pgsql';
$sqlconf['port'] = '5432';
$sqlconf['db_sslmode'] = 'verify-full';
```

Use the existing host, login, password, and database entries. The PHP runtime
needs `pdo_pgsql`. TLS verifies the server certificate and hostname by default.
Optional site certificates live under `documents/certificates/pgsql-ca`,
`pgsql-cert`, and `pgsql-key`; MySQL continues to use its existing filenames.
For a PostgreSQL Unix socket, configure `socket` as the socket **directory**,
omitting host and port.

## Reference test environment

From the repository root:

```sh
docker compose -f docker/postgres-port/compose.yaml up --build --abort-on-container-exit --exit-code-from tests
docker compose -f docker/postgres-port/compose.yaml down --volumes
```

The test stack publishes no ports, uses a disposable database, and disables TLS
only on its private test network. It runs the connection configuration tests
and a PostgreSQL 18 integration test using prepared values, Unicode, exact
big integers and decimal amounts, NULL, commit, and rollback. It does not
install the application or validate clinical workflows.

## Remaining migration work

1. Port the audited ADOdb connection and recordset contract. Preserve SQL audit
   events, the originating error, affected rows, and the application insert ID
   across audit writes. Prefer explicit `RETURNING` for generated IDs.
2. Convert the installer, base schema, seed files, and `sql_upgrade.php` path.
   The current base schema has 285 table declarations. Preserve primary and
   unique keys, binary UUID values, numeric ranges, enum constraints, comments,
   index semantics, and timestamp update behavior. A syntax-only transpilation
   is not a verified schema migration.
3. Port schema discovery, identifier quoting, MySQL functions, upserts,
   pagination, and direct MySQLi callers. Include modules and maintenance tools.
4. Prove fresh installation and upgrades on PostgreSQL, then exercise login,
   access controls, patient CRUD, scheduling, notes and amendments, billing,
   portal/API access, and audit logging through the application test suites.
5. Run the resulting SQL and driver contracts against Antfly, recording exact
   unsupported statements and protocol behavior. PG wire support alone does
   not establish application compatibility. Resolve each gap in either the
   portable application SQL or Antfly before claiming the EHR works there.

Keep the upstream GPL license, notices, and authorship. This fork's test results
do not extend upstream certification claims to a modified database backend.
