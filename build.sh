#!/bin/sh

# docker build -t releaser --no-cache .
docker build -t releaser .

docker stop releaser && docker rm releaser

docker run --name releaser -v /Users/camilo/Projects/embrapa.io/data:/data -d releaser
