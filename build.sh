#!/usr/bin/env bash

set -xe

while
  PS3="Orchestrator: "

  select orchestrator in DockerCompose DockerSwarm
  do
    break
  done
  [[ -z $orchestrator ]]
do true; done

while
  read -p "Directory: " path
  path=$(realpath "$path")
  [[ ! -d "$path" ]]
do true; done

case $orchestrator in

  DockerCompose)
    docker stop releaser && docker rm releaser

    # docker build -t releaser --no-cache .
    docker build -t releaser .

    docker run --name releaser \
      -v $path:/data \
      -v /var/run/docker.sock:/var/run/docker.sock \
      --restart unless-stopped -d \
      releaser

    # docker exec -it releaser io info

    ;;

  DockerSwarm)
    docker service rm releaser

    docker build -t releaser .

    docker service create --name releaser \
      --constraint=node.hostname==$(hostname) \
      --mount=type=bind,src=$path,dst=/data \
      --mount=type=bind,src=/var/run/docker.sock,dst=/var/run/docker.sock \
      releaser

    # docker exec -it $(docker ps -q -f name=releaser) io info

    ;;

esac
