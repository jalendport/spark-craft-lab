ARG BASE_IMAGE=jalendport/spark-php:8.2-fpm
FROM ${BASE_IMAGE}

ARG UID=1000
ARG GID=1000

COPY ./phpfpm.conf /usr/local/etc/php-fpm.d/zz-lab.conf

# The base spark-php image ships pdo_mysql/pdo_sqlite only; add pdo_pgsql so
# instances minted with `--db pg` can talk to their bundled Postgres service.
RUN apk add --no-cache --virtual .pgsql-build postgresql-dev \
	&& docker-php-ext-install pdo_pgsql \
	&& apk add --no-cache libpq \
	&& apk del .pgsql-build

# Create a host-UID/GID `spark` user so files the container writes into the
# bind-mounted instance are owned by the host user. GID collisions are common:
# macOS's staff group is gid 20, which is alpine's dialout group, and a Linux
# host's gid may already belong to some other group. So: drop dialout only when
# it doesn't already hold the target gid, and if the target gid is taken, reuse
# that group for `spark` rather than failing on a duplicate-gid addgroup.
RUN if getent group dialout >/dev/null 2>&1 && [ "$(getent group dialout | cut -d: -f3)" != "${GID}" ]; then delgroup dialout; fi \
	&& (deluser www-data 2>/dev/null || true) \
	&& if getent group ${GID} >/dev/null 2>&1; then \
		spark_grp="$(getent group ${GID} | cut -d: -f1)"; \
	else \
		addgroup -g ${GID} --system spark; spark_grp=spark; \
	fi \
	&& adduser -G "${spark_grp}" --system -D -s /bin/sh -u ${UID} spark \
	&& mkdir /composer-cache \
	&& chown ${UID}:${GID} /composer-cache

USER spark
