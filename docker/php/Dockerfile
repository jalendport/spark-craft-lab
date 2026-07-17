ARG BASE_IMAGE=jalendport/spark-php:8.2-fpm
FROM ${BASE_IMAGE}

ARG UID=1000
ARG GID=1000

COPY ./phpfpm.conf /usr/local/etc/php-fpm.d/zz-lab.conf

# MacOS staff group's gid is 20; so is the dialout group in alpine linux. We're not using it so let's just remove it.
RUN delgroup dialout \
	&& deluser www-data \
	&& addgroup -g ${GID} --system spark \
	&& adduser -G spark --system -D -s /bin/sh -u ${UID} spark \
	&& mkdir /composer-cache \
	&& chown spark:spark /composer-cache

USER spark
