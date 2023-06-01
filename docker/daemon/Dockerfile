FROM php:8.1-cli-alpine AS base

RUN mkdir -p /etc/periodic/minutely \
 && echo "*	*	*	*	*	run-parts /etc/periodic/minutely" >> /var/spool/cron/crontabs/root

COPY ./ssh /app/

RUN set -ex \
 && apk add --update --no-cache \
    autoconf \
    bash \
    curl \
    g++ \
    git \
    libtool \
    make \
    openssh-client \
    tini \
 && eval $(ssh-agent -s) \
 && mkdir -p ~/.ssh \
 && cp /app/ssh ~/.ssh/id_rsa \
 && chmod 600 ~/.ssh/id_rsa \
 && ssh-add ~/.ssh/id_rsa \
 && echo -e "Host *\n\tStrictHostKeyChecking no\n\n" > ~/.ssh/config \
 && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
 && rm -rf /var/cache/apk/*

FROM base AS docker

RUN set -ex \
 && apk add --update --no-cache \
    docker \
    findmnt \
    sshfs \
    yaml-dev \
 && curl -L https://github.com/docker/compose/releases/download/v2.18.1/docker-compose-linux-$(uname -m) > /usr/bin/docker-compose \
 && chmod +x /usr/bin/docker-compose \
 && pecl install yaml \
 && docker-php-ext-enable yaml \
 && docker-php-source delete \
 && rm -rf /var/cache/apk/*

FROM docker AS releaser

ARG PHP_ENV
ARG DEBUG

ENV PHP_ENV $PHP_ENV
ENV DEBUG $DEBUG

COPY ./docker/daemon/minutely/* /etc/periodic/minutely/
COPY ./docker/daemon/15min/* /etc/periodic/15min/
COPY ./docker/daemon/hourly/* /etc/periodic/hourly/
COPY ./docker/daemon/daily/* /etc/periodic/daily/

WORKDIR /app

COPY ./composer.* /app/

RUN set -ex \
 && composer install --no-interaction -d /app \
 && chmod a+x /etc/periodic/minutely/* \
 && chmod a+x /etc/periodic/15min/* \
 && chmod a+x /etc/periodic/hourly/* \
 && chmod a+x /etc/periodic/daily/*

ENTRYPOINT ["/sbin/tini", "--"]

CMD ["/usr/sbin/crond", "-f"]
