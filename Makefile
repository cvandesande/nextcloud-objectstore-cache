# SPDX-FileCopyrightText: 2026 CVan
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Convenience wrapper around the Dockerised toolchain, so the checks can be run
# locally without a host PHP/Composer install. Mirrors .github/workflows/ci.yml.
#
#   make install   install Composer dependencies
#   make test      run PHPUnit on the production PHP (8.5)
#   make lint      php -l every source file
#   make cs        coding-standard check (php-cs-fixer, dry run)
#   make cs-fix    apply coding-standard fixes
#   make psalm     static analysis
#   make check     lint + cs + psalm + test (everything CI runs)

# PHPUnit and the app run on the production runtime; Psalm/php-cs-fixer are pinned
# to 8.3 because Psalm 5.x does not run on PHP 8.4+.
PHP_TEST    ?= php:8.5-cli
PHP_ANALYSE ?= php:8.3-cli
COMPOSER    ?= composer:2

DOCKER = docker run --rm -v "$(CURDIR)":/app -w /app

.PHONY: install test lint cs cs-fix psalm check

install:
	$(DOCKER) $(COMPOSER) composer install --ignore-platform-req=php --no-interaction --no-progress

test:
	$(DOCKER) $(PHP_TEST) php vendor/bin/phpunit --configuration tests/phpunit.xml

lint:
	$(DOCKER) $(PHP_TEST) sh -c 'find lib tests -name "*.php" -print0 | xargs -0 -n1 php -l'

cs:
	$(DOCKER) $(PHP_ANALYSE) php vendor/bin/php-cs-fixer fix --dry-run --diff

cs-fix:
	$(DOCKER) $(PHP_ANALYSE) php vendor/bin/php-cs-fixer fix

psalm:
	$(DOCKER) $(PHP_ANALYSE) php vendor/bin/psalm --no-progress

check: lint cs psalm test
