# Multi-stage build for production
FROM node:18-alpine AS node-builder

WORKDIR /app
COPY app/package*.json ./
RUN npm ci --only=production

COPY app/webpack.config.js ./
COPY app/assets ./assets
RUN npm run build

FROM php:8.3-fpm AS php-base

# Install system dependencies
RUN apt-get update && apt-get install --no-install-recommends --no-install-suggests -y \
    git \
    curl \
    htop \
    libmemcached-dev \
    cron \
    unzip \
    nano \
    libicu-dev \
    zlib1g-dev \
    libssl-dev \
    pkg-config \
    libzip-dev \
    libpq-dev \
    librabbitmq-dev \
    libssh-dev \
    libpng-dev \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN set -xe \
    && docker-php-ext-configure intl \
    && docker-php-ext-install \
        intl \
        opcache \
        zip \
        pdo \
        pdo_pgsql \
        bcmath \
        sockets \
        gd \
    && pecl install apcu redis memcached amqp \
    && docker-php-ext-enable apcu redis memcached amqp

# Install Composer
COPY --from=composer:2.7.2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER 1

# Set working directory
WORKDIR /app

# Copy composer files
COPY app/composer.json app/composer.lock ./

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader --no-scripts

# Copy application code
COPY app/ ./

# Copy built assets
COPY --from=node-builder /app/public/build ./public/build

# Copy PHP configuration
COPY docker/php-fpm/php.ini /usr/local/etc/php/php.ini

# Set permissions
RUN chown -R www-data:www-data /app/var

# Expose port
EXPOSE 9000

CMD ["php-fpm"]
