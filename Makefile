DEV_DOCKER_COMPOSE_FILE=.docker/dev/docker-compose.yml
PROD_DOCKER_COMPOSE_FILE=.docker/prod/docker-compose.yml
SLIM_DOCKER_COMPOSE_FILE=.docker/prod/docker-compose.slim.yml
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

# Docker Slim optimization commands
optimize-all:
	chmod +x .docker/prod/optimize-images.sh
	./.docker/prod/optimize-images.sh

optimize-php:
	echo "Optimizing PHP-FPM image..."
	slim build \
		--target $(PART_OF_CONTAINER_NAME)-php-fpm:1.0 \
		--tag $(PART_OF_CONTAINER_NAME)-php-fpm:1.0-slim \
		--http-probe=false \
		--include-path /bin/bash \
		--include-path /bin/ls \
		--include-path /bin/grep \
		--include-path /usr/local/bin \
		--include-path /usr/local/lib \
		--include-path /usr/local/etc/php \
		--include-path /usr/bin \
		--include-path /var/www \
		--include-path /home/www-data \
		--include-exe php-fpm \
		--include-exe php \
		--include-exe composer \
		--include-exe node \
		--include-exe npm \
		--include-exe npx \
		--include-exe bash \
		--include-exe ls \
		--continue-after 60

optimize-nginx:
	echo "Optimizing Nginx image..."
	slim build \
		--target $(PART_OF_CONTAINER_NAME)-nginx:1.0 \
		--tag $(PART_OF_CONTAINER_NAME)-nginx:1.0-slim \
		--http-probe=true \
		--http-probe-cmd GET:/ \
		--include-path /etc/nginx \
		--include-path /usr/share/nginx \
		--include-path /var/cache/nginx \
		--include-path /var/log/nginx \
		--include-path /var/www \
		--include-exe nginx \
		--continue-after 30

optimize-cron:
	echo "Optimizing Cron image..."
	slim build \
		--target $(PART_OF_CONTAINER_NAME)-cron:1.0 \
		--tag $(PART_OF_CONTAINER_NAME)-cron:1.0-slim \
		--http-probe=false \
		--include-path /usr/local/bin \
		--include-path /usr/local/lib \
		--include-path /usr/bin \
		--include-path /var/www \
		--include-path /etc/crontabs \
		--include-exe php \
		--include-exe supercronic \
		--include-exe composer \
		--continue-after 60

# Use slim images
up-prod-slim:
	docker compose -f $(SLIM_DOCKER_COMPOSE_FILE) up -d

down-prod-slim:
	docker compose -f $(SLIM_DOCKER_COMPOSE_FILE) down

restart-prod-slim: down-prod-slim up-prod-slim

# Show image sizes
show-sizes:
	docker images | grep $(PART_OF_CONTAINER_NAME)

# Compare sizes with detailed breakdown
compare-sizes:
	chmod +x .docker/prod/compare-sizes.sh
	./.docker/prod/compare-sizes.sh

# Install docker-slim
install-slim:
	echo "Installing docker-slim..."
	wget https://github.com/slimtoolkit/slim/releases/latest/download/dist_linux.tar.gz
	echo "docker-slim installed successfully!"
	slim --version