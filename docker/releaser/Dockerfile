FROM php:cli-alpine AS base

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
 && mkdir -p /etc/periodic/releaser \
 && rm -rf /var/cache/apk/*

FROM base AS docker

RUN set -ex \
 && apk add --update --no-cache \
    docker \
    findmnt \
    sshfs \
    yaml-dev \
 && curl -L https://github.com/docker/compose/releases/download/v2.19.0/docker-compose-linux-$(uname -m) > /usr/bin/docker-compose \
 && chmod +x /usr/bin/docker-compose \
 && pecl install yaml \
 && docker-php-ext-enable yaml \
 && docker-php-source delete \
 && rm -rf /var/cache/apk/*

FROM docker AS releaser

ARG SCHEDULER_MINUTES

WORKDIR /app

COPY ./composer.* /app/

COPY ./docker/releaser/periodic/deploy /etc/periodic/deploy/
COPY ./docker/releaser/periodic/backup /etc/periodic/daily/
COPY ./docker/releaser/periodic/sanitize /etc/periodic/monthly/

RUN set -ex \
 && echo "*/$SCHEDULER_MINUTES	*	*	*	*	run-parts /etc/periodic/deploy" >> /var/spool/cron/crontabs/root \
 && chmod a+x /etc/periodic/deploy/* \
 && chmod a+x /etc/periodic/daily/* \
 && chmod a+x /etc/periodic/monthly/* \
 && composer install --no-interaction -d /app

ENTRYPOINT ["/sbin/tini", "--"]

CMD ["/usr/sbin/crond", "-f"]
