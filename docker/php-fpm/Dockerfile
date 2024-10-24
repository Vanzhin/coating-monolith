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

COPY php.ini /usr/local/etc/php/php.ini
#RUN sed -i -e "s#TIMEZONE#$timezone#g" /usr/local/etc/php/php.ini

COPY xdebug.ini /tmp/xdebug.ini
#RUN cat /tmp/xdebug.ini >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini
RUN cat /tmp/xdebug.ini >> /usr/local/etc/php/conf.d/xdebug.ini
RUN rm /tmp/xdebug.ini

COPY --from=composer:2.7.2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER 1

RUN mkdir -p /var/www/.composer && chown -R www-data /var/www/.composer
#WORKDIR /app
# выполняю команду генерации закрытого и открытого ключей для JWT
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
ENTRYPOINT ["/entrypoint.sh"]
CMD ["php-fpm"]
