#!/bin/sh
set -e
cd /app

# Scaffold the app on first run if the folder is empty
if [ ! -f composer.json ]; then
  case "$APP_TYPE" in
    laravel)
      composer create-project laravel/laravel /tmp/app --no-interaction
      cp -a /tmp/app/. /app/ && rm -rf /tmp/app
      composer require vladimir-yuldashev/laravel-queue-rabbitmq --no-interaction
      sed -i "s/^QUEUE_CONNECTION=.*/QUEUE_CONNECTION=rabbitmq/" .env
      cat >> .env <<ENV
RABBITMQ_HOST=${RABBITMQ_HOST}
RABBITMQ_PORT=5672
RABBITMQ_USER=${RABBITMQ_USER}
RABBITMQ_PASSWORD=${RABBITMQ_PASSWORD}
RABBITMQ_VHOST=/
RABBITMQ_QUEUE=default
ENV
      ;;
    symfony)
      composer create-project symfony/skeleton /tmp/app --no-interaction
      cp -a /tmp/app/. /app/ && rm -rf /tmp/app
      composer require symfony/messenger symfony/amqp-messenger symfony/runtime --no-interaction
      echo "MESSENGER_TRANSPORT_DSN=amqp://${RABBITMQ_USER}:${RABBITMQ_PASSWORD}@${RABBITMQ_HOST}:5672/%2f/messages" >> .env
      ;;
  esac
fi

[ -d vendor ] || composer install --no-interaction
exec "$@"
