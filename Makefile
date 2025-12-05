up-dev:
	docker compose -f .docker/dev/docker-compose.yml up -d

down-dev:
	docker compose -f .docker/dev/docker-compose.yml down

build-dev:
	docker compose -f .docker/dev/docker-compose.yml build

restart-dev: down-dev up-dev

up-prod:
	docker compose -f .docker/prod/docker-compose.yml up -d

down-prod:
	docker compose -f .docker/prod/docker-compose.yml down

build-prod:
	docker compose -f .docker/prod/docker-compose.yml build

restart-prod: down-prod up-prod

vite:
	cd httpdocs/app && npm run dev

vite-build:
	cd httpdocs/app \
	&& npm run build

spfdb:
	chown -R ${id -u}:${id -g} db