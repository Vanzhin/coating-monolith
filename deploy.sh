#!/bin/bash

# Deployment script for production
set -e

echo "🚀 Starting deployment..."

# Navigate to project directory
cd /var/www/sites/1helper

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Configuration
REGISTRY="ghcr.io"
IMAGE_NAME="Vanzhin/coating-monolith"
IMAGE_TAG="latest"

# Check if required environment variables are set
if [ -z "$DB_USER" ] || [ -z "$DB_PASSWORD" ] || [ -z "$DB_NAME" ]; then
    echo -e "${RED}❌ Error: Database environment variables not set${NC}"
    echo "Please set DB_USER, DB_PASSWORD, and DB_NAME"
    exit 1
fi

echo -e "${YELLOW}📦 Pulling latest image...${NC}"
docker pull ${REGISTRY}/${IMAGE_NAME}:${IMAGE_TAG}

echo -e "${YELLOW}🛑 Stopping existing containers...${NC}"
docker-compose -f docker-compose.prod.yml down

echo -e "${YELLOW}🔄 Starting new containers...${NC}"
export REGISTRY=${REGISTRY}
export IMAGE_NAME=${IMAGE_NAME}
export IMAGE_TAG=${IMAGE_TAG}
docker-compose -f docker-compose.prod.yml up -d

echo -e "${YELLOW}🗄️ Running database migrations...${NC}"
docker-compose -f docker-compose.prod.yml exec -T manager_php-cli php bin/console doctrine:migrations:migrate --no-interaction

echo -e "${YELLOW}🧹 Clearing cache...${NC}"
docker-compose -f docker-compose.prod.yml exec -T manager_php-cli php bin/console cache:clear --env=prod

echo -e "${YELLOW}🔥 Warming up cache...${NC}"
docker-compose -f docker-compose.prod.yml exec -T manager_php-cli php bin/console cache:warmup --env=prod

echo -e "${YELLOW}📊 Checking container status...${NC}"
docker-compose -f docker-compose.prod.yml ps

echo -e "${GREEN}✅ Deployment completed successfully!${NC}"
echo -e "${GREEN}🌐 Application is available at: https://1helper.ru${NC}"
