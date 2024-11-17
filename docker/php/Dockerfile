FROM php:8.4-rc-zts-alpine

RUN apk --update --no-cache add $PHPIZE_DEPS zip shadow bzip2-dev curl-dev libpng-dev libzip-dev gmp-dev icu-dev \
    oniguruma-dev libzip-dev ffmpeg jpeg-dev libpng-dev \
    && usermod -u 1000 www-data && groupmod -g 1000 www-data \
    && pecl install parallel && docker-php-ext-enable parallel \
    && docker-php-ext-install bcmath \
    && docker-php-ext-install bz2 \
    && docker-php-ext-install curl \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd \
    && docker-php-ext-install gmp \
    && docker-php-ext-install intl \
    && docker-php-ext-install mbstring \
    && docker-php-ext-install zip

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin --filename=composer

WORKDIR /opt/app

RUN chown -R root:root .

COPY docker/php/php.ini /usr/local/etc/php/conf.d/php100.ini
