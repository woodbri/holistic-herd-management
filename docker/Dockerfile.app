FROM php:7.3-apache
RUN apt-get update && \
	apt-get install -y libpng-dev libpq-dev \
    && docker-php-ext-configure pgsql -with-pgsql=/usr/local/pgsql \
    && docker-php-ext-install gd pgsql
COPY ./ /var/www/html/
