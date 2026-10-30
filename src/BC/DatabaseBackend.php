<?php

/**
 * Database protocols supported by connection configuration.
 *
 * @package   OpenEMR
 * @copyright Copyright (c) 2026 AntflyDB
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\BC;

enum DatabaseBackend: string
{
    case MySQL = 'mysql';
    case PostgreSQL = 'pgsql';
}
