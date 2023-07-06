FROM php:cli-alpine AS base

COPY . /app/

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
 && curl -L https://github.com/docker/compose/releases/download/v2.19.1/docker-compose-linux-$(uname -m) > /usr/bin/docker-compose \
 && chmod +x /usr/bin/docker-compose \
 && pecl install yaml \
 && docker-php-ext-enable yaml \
 && docker-php-source delete \
 && rm -rf /var/cache/apk/*

FROM docker AS releaser

WORKDIR /app

RUN set -ex \
 && cp /app/docker/releaser/periodic/deploy /etc/periodic/15min \
 && cp /app/docker/releaser/periodic/backup /etc/periodic/daily/ \
 && cp /app/docker/releaser/periodic/sanitize /etc/periodic/monthly/ \
 && chmod a+x /etc/periodic/15min/* \
 && chmod a+x /etc/periodic/daily/* \
 && chmod a+x /etc/periodic/monthly/* \
 && composer install --no-interaction -d /app

ENTRYPOINT ["/sbin/tini", "--"]

CMD ["/usr/sbin/crond", "-f"]
