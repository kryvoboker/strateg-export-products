#!/bin/bash

# Docker Images Size Comparison Script
# Shows size comparison between regular and slim images

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

echo -e "${CYAN}======================================"
echo -e "Docker Images Size Comparison"
echo -e "======================================${NC}"
echo ""

# Function to get image size in bytes
get_size_bytes() {
    local image=$1
    docker images --format "{{.Size}}" "$image" 2>/dev/null | head -1
}

# Function to convert size to bytes for calculation
size_to_bytes() {
    local size=$1
    local number=$(echo "$size" | grep -oE '[0-9.]+')
    local unit=$(echo "$size" | grep -oE '[A-Z]+')

    case $unit in
        GB)
            echo "$(echo "$number * 1024 * 1024 * 1024" | bc | cut -d. -f1)"
            ;;
        MB)
            echo "$(echo "$number * 1024 * 1024" | bc | cut -d. -f1)"
            ;;
        KB)
            echo "$(echo "$number * 1024" | bc | cut -d. -f1)"
            ;;
        *)
            echo "$number"
            ;;
    esac
}

# Function to calculate percentage reduction
calc_reduction() {
    local original=$1
    local optimized=$2

    if [ -z "$original" ] || [ -z "$optimized" ]; then
        echo "N/A"
        return
    fi

    local orig_bytes=$(size_to_bytes "$original")
    local opt_bytes=$(size_to_bytes "$optimized")

    if [ -z "$orig_bytes" ] || [ -z "$opt_bytes" ] || [ "$orig_bytes" -eq 0 ]; then
        echo "N/A"
        return
    fi

    local reduction=$(echo "scale=1; (($orig_bytes - $opt_bytes) / $orig_bytes) * 100" | bc)
    echo "${reduction}%"
}

# Function to display service comparison
compare_service() {
    local service_name=$1
    local regular_image=$2
    local slim_image=$3

    local regular_size=$(get_size_bytes "$regular_image")
    local slim_size=$(get_size_bytes "$slim_image")

    echo -e "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "${BLUE}Service: ${GREEN}$service_name${NC}"
    echo -e "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"

    if [ -n "$regular_size" ]; then
        echo -e "Regular Image:  ${CYAN}$regular_image${NC}"
        echo -e "Size:           ${GREEN}$regular_size${NC}"
    else
        echo -e "Regular Image:  ${RED}Not found${NC}"
    fi

    echo ""

    if [ -n "$slim_size" ]; then
        echo -e "Slim Image:     ${CYAN}$slim_image${NC}"
        echo -e "Size:           ${GREEN}$slim_size${NC}"
    else
        echo -e "Slim Image:     ${RED}Not found${NC}"
    fi

    if [ -n "$regular_size" ] && [ -n "$slim_size" ]; then
        local reduction=$(calc_reduction "$regular_size" "$slim_size")
        echo -e "Reduction:      ${GREEN}$reduction${NC}"
    fi

    echo ""
}

# Compare all services
compare_service "PHP-FPM" \
    "dev-strateg-php-fpm:1.0" \
    "dev-strateg-php-fpm:1.0-slim"

compare_service "Nginx" \
    "dev-strateg-nginx:1.0" \
    "dev-strateg-nginx:1.0-slim"

compare_service "Cron" \
    "dev-strateg-cron:1.0" \
    "dev-strateg-cron:1.0-slim"

compare_service "MariaDB" \
    "dev-strateg-mariadb:1.0" \
    "dev-strateg-mariadb:1.0-slim"

compare_service "Memcached" \
    "dev-strateg-memcached:1.0" \
    "dev-strateg-memcached:1.0-slim"

# Summary table
echo -e "${CYAN}======================================"
echo -e "Summary Table"
echo -e "======================================${NC}"
echo ""

printf "%-15s %-20s %-20s %-15s\n" "Service" "Regular" "Slim" "Reduction"
printf "%-15s %-20s %-20s %-15s\n" "-------" "-------" "----" "---------"

php_reg=$(get_size_bytes "dev-strateg-php-fpm:1.0")
php_slim=$(get_size_bytes "dev-strateg-php-fpm:1.0-slim")
php_red=$(calc_reduction "$php_reg" "$php_slim")
printf "%-15s %-20s %-20s %-15s\n" "PHP-FPM" "${php_reg:-N/A}" "${php_slim:-N/A}" "$php_red"

nginx_reg=$(get_size_bytes "dev-strateg-nginx:1.0")
nginx_slim=$(get_size_bytes "dev-strateg-nginx:1.0-slim")
nginx_red=$(calc_reduction "$nginx_reg" "$nginx_slim")
printf "%-15s %-20s %-20s %-15s\n" "Nginx" "${nginx_reg:-N/A}" "${nginx_slim:-N/A}" "$nginx_red"

cron_reg=$(get_size_bytes "dev-strateg-cron:1.0")
cron_slim=$(get_size_bytes "dev-strateg-cron:1.0-slim")
cron_red=$(calc_reduction "$cron_reg" "$cron_slim")
printf "%-15s %-20s %-20s %-15s\n" "Cron" "${cron_reg:-N/A}" "${cron_slim:-N/A}" "$cron_red"

mariadb_reg=$(get_size_bytes "dev-strateg-mariadb:1.0")
mariadb_slim=$(get_size_bytes "dev-strateg-mariadb:1.0-slim")
mariadb_red=$(calc_reduction "$mariadb_reg" "$mariadb_slim")
printf "%-15s %-20s %-20s %-15s\n" "MariaDB" "${mariadb_reg:-N/A}" "${mariadb_slim:-N/A}" "$mariadb_red"

memcached_reg=$(get_size_bytes "dev-strateg-memcached:1.0")
memcached_slim=$(get_size_bytes "dev-strateg-memcached:1.0-slim")
memcached_red=$(calc_reduction "$memcached_reg" "$memcached_slim")
printf "%-15s %-20s %-20s %-15s\n" "Memcached" "${memcached_reg:-N/A}" "${memcached_slim:-N/A}" "$memcached_red"

echo ""
echo -e "${CYAN}======================================"
echo -e "All Images"
echo -e "======================================${NC}"
echo ""

docker images | grep -E "REPOSITORY|dev-strateg" | head -20

echo ""
echo -e "${GREEN}✓ Comparison complete!${NC}"