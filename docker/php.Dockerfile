FROM php:8.4-cli
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/install-php-extensions
RUN chmod +x /usr/local/bin/install-php-extensions \
    && install-php-extensions redis pcntl zip @composer
WORKDIR /app
