FROM php:8.3-fpm

#ARG timezone
ARG INSTALL_XDEBUG=false
RUN if [ ${INSTALL_XDEBUG} = true ]; \
    then \
     pecl install xdebug && docker-php-ext-enable xdebug;\
fi;

RUN apt-get update && apt-get install --no-install-recommends --no-install-suggests -y \
		git \
        curl \
        htop \
        libmemcached-dev \
        cron\
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

RUN set -xe \
    && docker-php-ext-configure \
        intl \
    && docker-php-ext-install \
        intl \
        opcache \
        zip \
        pdo \
        pdo_pgsql \
        bcmath \
        sockets \
        gd \
    && pecl install \
        apcu \
        redis \
        memcached \
        amqp \
        && rm -rf /tmp/pear \
    && docker-php-ext-enable \
        apcu \
        redis \
        memcached \
        amqp

COPY docker/php-fpm/php.ini /usr/local/etc/php/php.ini
#RUN sed -i -e "s#TIMEZONE#$timezone#g" /usr/local/etc/php/php.ini

COPY docker/php-fpm/xdebug.ini /tmp/xdebug.ini
#RUN cat /tmp/xdebug.ini >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini
RUN cat /tmp/xdebug.ini >> /usr/local/etc/php/conf.d/xdebug.ini
RUN rm /tmp/xdebug.ini

COPY --from=composer:2.7.2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

RUN mkdir -p /var/www/.composer && chown -R www-data /var/www/.composer

# Copy application code
WORKDIR /app
COPY app /app

# Create production .env file
RUN echo "APP_ENV=prod" > /app/.env && \
    echo "APP_DEBUG=0" >> /app/.env && \
    echo "APP_SECRET=your-secret-key-here" >> /app/.env

# Install dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

# Set proper permissions
RUN chown -R www-data:www-data /app
RUN chmod -R 755 /app

# выполняю команду генерации закрытого и открытого ключей для JWT
COPY docker/php-fpm/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
ENTRYPOINT ["/entrypoint.sh"]
CMD ["php-fpm"]
