#!/bin/bash

# Docker Slim Build and Optimization Script
# This script builds Docker images and optimizes them using docker-slim

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Configuration
COMPOSE_FILE=".docker/prod/docker-compose.yml"
PROJECT_DIR="/home/kamaz/www/psyholodzhy-opencart"

# Function to print colored messages
print_success() {
    echo -e "${GREEN}✓ $1${NC}"
}

print_error() {
    echo -e "${RED}✗ $1${NC}"
}

print_info() {
    echo -e "${YELLOW}ℹ $1${NC}"
}

# Function to check if docker-slim is installed
check_slim_installation() {
    if ! command -v slim &> /dev/null; then
        print_error "docker-slim (slim) is not installed!"
        print_info "Installing docker-slim..."

        curl -L -o /tmp/ds.tar.gz https://github.com/slimtoolkit/slim/releases/download/1.40.11/dist_linux.tar.gz
        tar -xvzf /tmp/ds.tar.gz -C /tmp
        sudo mv /tmp/dist_linux/* /usr/local/bin/
        sudo chmod +x /usr/local/bin/slim
        rm -rf /tmp/dist_linux /tmp/ds.tar.gz

        print_success "docker-slim installed successfully!"
    else
        print_success "docker-slim is already installed"
        slim version
    fi
}

# Function to build original images
build_images() {
    print_info "Building original Docker images..."
    cd "$PROJECT_DIR"
    docker compose -f "$COMPOSE_FILE" build
    print_success "Original images built successfully!"
}

# Function to show image sizes
show_image_sizes() {
    print_info "Current image sizes:"
    docker images | grep dev-strateg | awk '{printf "%-60s %10s\n", $1":"$2, $7" "$8}'
}

# Function to optimize PHP-FPM image
optimize_php_fpm() {
    print_info "Optimizing PHP-FPM image..."
    slim build \
        --target dev-strateg-php-fpm:1.0 \
        --tag dev-strateg-php-fpm:1.0-slim \
        --http-probe=false \
        --include-path /usr/local/bin \
        --include-path /usr/local/lib \
        --include-path /usr/local/etc/php \
        --include-path /var/www \
        --include-path /home/www-data \
        --include-exe php-fpm \
        --include-exe php \
        --include-exe composer \
        --include-exe node \
        --include-exe npm \
        --include-exe npx \
        --continue-after 60
    print_success "PHP-FPM image optimized!"
}

# Function to optimize Nginx image
optimize_nginx() {
    print_info "Optimizing Nginx image..."
    slim build \
        --target dev-strateg-nginx:1.0 \
        --tag dev-strateg-nginx:1.0-slim \
        --http-probe=true \
        --http-probe-cmd GET:/ \
        --include-path /etc/nginx \
        --include-path /usr/share/nginx \
        --include-path /var/cache/nginx \
        --include-path /var/log/nginx \
        --include-path /var/www \
        --include-exe nginx \
        --continue-after 30
    print_success "Nginx image optimized!"
}

# Function to optimize Cron image
optimize_cron() {
    print_info "Optimizing Cron image..."
    slim build \
        --target dev-strateg-cron:1.0 \
        --tag dev-strateg-cron:1.0-slim \
        --http-probe=false \
        --include-path /usr/local/bin \
        --include-path /usr/local/lib \
        --include-path /var/www \
        --include-path /etc/crontabs \
        --include-exe php \
        --include-exe supercronic \
        --include-exe composer \
        --continue-after 60
    print_success "Cron image optimized!"
}

# Function to cleanup
#cleanup() {
#    print_info "Cleaning up unused containers and images..."
#    docker container prune -f
#    docker image prune -f
#    print_success "Cleanup completed!"
#}

# Main execution
main() {
    echo "======================================"
    echo "Docker Images Build & Optimization"
    echo "======================================"
    echo ""

    # Check installation
    check_slim_installation

    echo ""
    print_info "Starting optimization process..."
    echo ""

    # Build original images
    build_images

    echo ""
    print_info "Images before optimization:"
    show_image_sizes

    echo ""
    # Optimize images
    optimize_php_fpm
    echo ""
    optimize_nginx
    echo ""
    optimize_cron

    echo ""
    print_info "Images after optimization:"
    show_image_sizes

    echo ""
    # Cleanup
#    cleanup

    echo ""
    print_success "All done! Your optimized images are ready to use."
    print_info "Don't forget to update docker-compose.yml to use -slim tagged images!"
    echo ""
}

# Run main function
main "$@"