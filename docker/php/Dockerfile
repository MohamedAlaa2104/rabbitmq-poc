FROM php:8.3-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
      git unzip libzip-dev librabbitmq-dev libicu-dev \
    && pecl install amqp \
    && docker-php-ext-enable amqp \
    && docker-php-ext-install zip intl pcntl sockets bcmath pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /app
ENTRYPOINT ["entrypoint.sh"]
