.DEFAULT_GOAL := help
BACK = cd backend &&
FRONT = cd frontend &&

help: ## List available targets
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

## —— Local development (SQLite, no Docker) ——————————————————————
install: ## Install backend and frontend dependencies, JWT keys and demo database
	$(BACK) composer install
	$(BACK) php bin/console lexik:jwt:generate-keypair --skip-if-exists
	$(FRONT) npm ci
	$(MAKE) db-reset

db-reset: ## Recreate the local SQLite database with demo data
	$(BACK) php bin/console doctrine:schema:drop --force --full-database
	$(BACK) php bin/console doctrine:schema:create
	$(BACK) php bin/console doctrine:fixtures:load --no-interaction

api: ## Start the API on http://127.0.0.1:8000 (Symfony CLI)
	$(BACK) symfony server:start --no-tls --port=8000

worker: ## Run the async message worker (AI scoring, notifications, audit)
	$(BACK) php bin/console messenger:consume async -vv

front: ## Start the Angular dev server on http://localhost:4200
	$(FRONT) npx ng serve

## —— Quality ————————————————————————————————————————————————————
test: ## Run the PHPUnit suite
	$(BACK) php bin/phpunit

phpstan: ## Static analysis (level 6)
	$(BACK) php bin/console cache:warmup --env=dev -q && vendor/bin/phpstan analyse --memory-limit=1G

build-front: ## Production build of the SPA
	$(FRONT) npx ng build --configuration production

ci: phpstan test build-front ## Everything the CI pipeline runs

## —— Docker ——————————————————————————————————————————————————————
up: ## Build and start the full stack on http://localhost:8080 (generates secrets on first run)
	@test -f .env.docker || { \
		sed -e "s/^APP_SECRET=.*/APP_SECRET=$$(openssl rand -hex 32)/" \
		    -e "s/^JWT_PASSPHRASE=.*/JWT_PASSPHRASE=$$(openssl rand -hex 32)/" \
		    .env.docker.example > .env.docker && echo "Created .env.docker with fresh random secrets."; }
	docker compose up -d --build

demo: ## Same as `up`, loading the demo dataset (local use only: demo accounts share a public password)
	@$(MAKE) up
	docker compose exec api sh -c 'APP_ENV=dev composer install -q --no-progress && APP_ENV=dev php bin/console doctrine:fixtures:load --append --no-interaction'

down: ## Stop the stack
	docker compose down

logs: ## Follow logs
	docker compose logs -f api worker web

.PHONY: help install db-reset api worker front test phpstan build-front ci up demo down logs
