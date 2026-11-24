.DEFAULT_GOAL := help

COMPOSE := docker compose

.PHONY: help setup up down logs shell test destroy

help: ## List the available targets
	@echo "Usage: make <target>"
	@echo
	@awk 'BEGIN { FS = ":.*## " } /^[a-z][a-zA-Z_-]*:.*## / { printf "  %-10s %s\n", $$1, $$2 }' $(MAKEFILE_LIST)
	@echo
	@echo "Options:"
	@echo "  make setup FORCE=1   back up .env to .env.backup and recreate it"
	@echo "  make logs s=webapp   follow the logs of one service"

setup: ## Interactive setup: .env, secrets, containers, dependencies, migrations
	@bin/setup $(if $(filter 1,$(FORCE)),--force,)

up: ## Start the stack, wait for MariaDB and print the URLs
	@test -f .env || { echo ".env not found. Run: make setup"; exit 1; }
	@bin/setup --up

down: ## Stop the stack (keeps the volumes)
	$(COMPOSE) down

logs: ## Follow the logs (all services, or s=<service>)
	$(COMPOSE) logs -f $(s)

shell: ## Open bash in the webapp container
	$(COMPOSE) exec webapp bash

test: ## Run the test suite in the webapp container
	$(COMPOSE) exec webapp composer test

destroy: ## Stop the stack and DELETE the volumes (database, qdrant, embeddings)
	@printf 'This deletes all volumes (MariaDB data, Qdrant vectors, embeddings cache). Continue? [y/N] '; \
	read answer; \
	case "$$answer" in \
		y|Y|yes|YES|Yes) $(COMPOSE) down -v ;; \
		*) echo "Aborted." ;; \
	esac
