# Multi-stage Dockerfile!
# https://zupzup.org/docker-multi-stage-react/

# Composer
FROM php:8.0-apache AS vendor
RUN apt-get update
RUN apt-get install -y git unzip libonig-dev libcurl4-openssl-dev
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
RUN docker-php-ext-install curl && docker-php-ext-enable curl
RUN docker-php-ext-install mbstring && docker-php-ext-enable mbstring
RUN docker-php-ext-install pdo_mysql && docker-php-ext-enable pdo_mysql
WORKDIR /usr/src/app
COPY composer* ./
RUN php composer.phar update --no-dev

# Final image
FROM php:8.0-apache
#RUN apt-get clean && apt-get update && apt-get install -y locales
#RUN echo "sv_SE.UTF-8 UTF-8" >> /etc/locale.gen
#UN locale-gen

RUN a2enmod rewrite
COPY --from=vendor "$PHP_INI_DIR" "$PHP_INI_DIR"
COPY --from=vendor /usr/local/lib/php/extensions /usr/local/lib/php/extensions
COPY --from=vendor /usr/local/include/php /usr/local/include/php
WORKDIR /var/www/html
COPY --from=vendor /usr/src/app/vendor /var/www/vendor
COPY public/. ./
COPY src /var/www/src

EXPOSE 80

