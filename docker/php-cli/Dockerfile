FROM php:8.3-cli-alpine3.19

ENV COMPOSER_ALLOW_SUPERUSER 1

ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions

RUN apk add --update zip unzip grep wget bash git openssh

RUN install-php-extensions redis gd intl pcntl pdo_pgsql pdo_mysql sockets zip amqp opcache bz2 @composer
RUN rm  -rf /tmp/* /var/cache/apk/*
