name: lab-{{NAME}}

services:
  nginx:
    image: jalendport/spark-nginx:{{NGINX_TAG}}
    depends_on:
      - php
    init: true
    ports:
      - "{{PORT}}:80"
    volumes:
      - ./web:/app/web

  php:
    build:
      context: {{LAB_DIR}}/docker/php
      args:
        BASE_IMAGE: jalendport/spark-php:{{PHP_TAG}}-fpm
        UID: {{UID}}
        GID: {{GID}}
    init: true
    expose:
      - "9000"
    environment:
      COMPOSER_CACHE_DIR: /composer-cache
    volumes:
      - .:/app
      - {{PLUGINS_DIR}}:/plugins
      - {{LAB_DIR}}/templates:/app/templates
      - composer-cache:/composer-cache
    networks:
      - default
      - lab

networks:
  lab:
    external: true
    name: spark-craft-lab

volumes:
  composer-cache:
    external: true
    name: spark-craft-lab-composer
