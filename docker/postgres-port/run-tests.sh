#!/bin/sh
set -eu

composer install --no-interaction --prefer-dist --no-progress --no-scripts
vendor/bin/phpunit --bootstrap vendor/autoload.php --configuration phpunit-isolated.xml tests/Tests/BC/DatabaseConnectionOptionsTest.php
vendor/bin/phpunit --bootstrap vendor/autoload.php --configuration phpunit.integration.xml --testsuite database
