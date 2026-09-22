DEV_DOCKER_COMPOSE_FILE=.docker/dev/docker-compose.yml
PROD_DOCKER_COMPOSE_FILE=.docker/prod/docker-compose.yml
PART_OF_CONTAINER_NAME=dev-strateg-export-products

up-dev:
	docker compose -f $(DEV_DOCKER_COMPOSE_FILE) up -d

down-dev:
	docker compose -f $(DEV_DOCKER_COMPOSE_FILE) down

build-dev:
	docker compose -f $(DEV_DOCKER_COMPOSE_FILE) build

restart-dev: down-dev up-dev
rebuild-dev: down-dev build-dev up-dev

up-prod:
	docker compose -f $(PROD_DOCKER_COMPOSE_FILE) up -d

down-prod:
	docker compose -f $(PROD_DOCKER_COMPOSE_FILE) down

build-prod:
	docker compose -f $(PROD_DOCKER_COMPOSE_FILE) build

restart-prod: down-prod up-prod
rebuild-prod: down-prod build-prod up-prod

set-node:
	bash -c "source ~/.nvm/nvm.sh && nvm use 25.6.1"

vite: set-node
	cd app-code/app && npm run dev

vite-build: set-node
	cd app-code/app \
	&& npm run build

# Open a shell in the PHP-FPM container.
stpt:
	docker compose -f $(DEV_DOCKER_COMPOSE_FILE) exec $(PART_OF_CONTAINER_NAME)-php-fpm /bin/bash

branch-list:
	git branch -a --sort=-committerdate