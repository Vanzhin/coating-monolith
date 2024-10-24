FROM php:8.3-cli-alpine3.19

ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions

RUN apk add --update zip unzip grep wget bash supervisor

RUN install-php-extensions redis gd intl pcntl pdo_pgsql pdo_mysql sockets zip amqp opcache bz2

COPY ./conf.d/supervisord.conf /etc/supervisor/supervisord.conf
RUN rm  -rf /tmp/* /var/cache/apk/*

RUN echo user=root >>  /etc/supervisor/supervisord.conf
CMD ["/usr/bin/supervisord"]