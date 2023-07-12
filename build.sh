#!/bin/sh

docker stop releaser && docker rm releaser

# docker build -t releaser --no-cache .
docker build -t releaser .

docker run --name releaser \
  -v /Users/camilo/Projects/embrapa.io/data:/data \
  -v /var/run/docker.sock:/var/run/docker.sock \
  -d releaser
