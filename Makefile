# Contributor entry points. `make help` lists the targets; CONTRIBUTING.md
# explains the two-bridge layout these targets serve.

HYPERVEL_DIR = environments/hypervel
HYPERVEL_PHPUNIT = $(HYPERVEL_DIR)/vendor/bin/phpunit \
	--configuration phpunit.xml.dist \
	--bootstrap $(HYPERVEL_DIR)/vendor/autoload.php \
	--testsuite=Hypervel-Unit,Hypervel-Feature

.PHONY: help install up down test test-hypervel test-all cs phpstan

help:
	@echo "make install        install root vendor (Core + Laravel toolchain)"
	@echo "make up             start ClickHouse (required by every test target)"
	@echo "make down           stop containers"
	@echo "make test           run Core + Laravel suites (root vendor, local PHP)"
	@echo "make test-hypervel  run the Hypervel suite (Swoole container)"
	@echo "make test-all       run everything: Core + Laravel + Hypervel"
	@echo "make cs             check code style"
	@echo "make phpstan        run core + laravel static analysis"

install: vendor/autoload.php

up:
	docker compose up -d --wait clickhouse

down:
	docker compose down

test: vendor/autoload.php
	composer test

# Runs inside the Swoole container; the isolated vendor tree is installed
# on first use (Hypervel conflicts with testbench, so it cannot share the
# root vendor).
test-hypervel: $(HYPERVEL_DIR)/vendor/autoload.php
	docker compose run --rm hypervel $(HYPERVEL_PHPUNIT)

test-all: test test-hypervel

cs: vendor/autoload.php
	composer cs

phpstan: vendor/autoload.php
	composer phpstan

vendor/autoload.php: composer.json
	composer install
	@touch $@

$(HYPERVEL_DIR)/vendor/autoload.php: $(HYPERVEL_DIR)/composer.json
	docker compose run --rm --no-deps hypervel \
		composer update --working-dir=$(HYPERVEL_DIR) --prefer-dist -n
	@touch $@
