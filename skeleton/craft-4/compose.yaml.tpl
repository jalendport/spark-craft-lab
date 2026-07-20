name: {{PROJECT}}

services:
  nginx:
    image: jalendport/spark-nginx:{{NGINX_TAG}}
    depends_on:
      - php
    init: true
    ports:
      - "127.0.0.1:{{WEB_PORT}}:80"
    volumes:
      - ./web:/app/web

  php:
    build:
      context: ./.docker/php
      args:
        BASE_IMAGE: jalendport/spark-php:{{PHP_TAG}}-fpm
        UID: {{UID}}
        GID: {{GID}}
    depends_on:
      - {{DB_SERVER}}
    init: true
    expose:
      - "9000"
    environment:
      COMPOSER_CACHE_DIR: /composer-cache
      LAB_PLUGIN_DIR: /plugin
    volumes:
      - .:/app
      - ../..:/plugin:ro
      - composer-cache:/composer-cache

{{DB_BLOCK}}
  redis:
    image: jalendport/spark-redis:7.2
    healthcheck:
      test: ["CMD", "redis-cli", "-a", "root", "ping"]
      interval: 5s
      timeout: 3s
      retries: 5
      start_period: 5s
    init: true

  mailpit:
    image: axllent/mailpit:latest
    environment:
      MP_MAX_MESSAGES: 5000
      MP_SMTP_AUTH_ACCEPT_ANY: "true"
      MP_SMTP_AUTH_ALLOW_INSECURE: "true"
    init: true
    ports:
      - "127.0.0.1:{{MAILPIT_PORT}}:8025"

volumes:
  db-data:
  composer-cache:
    external: true
    name: spark-craft-lab-composer
