#!/bin/bash
mkdir -p ./db/mysql
chown -R 1001:1001 ./db/mysql
if (which docker-compose  > /dev/null 2>&1); then
    docker-compose stop
elif (which podman-compose  > /dev/null 2>&1); then
    podman-compose stop
else
    echo "Can not find docker-compose or podman-compose"
    exit 1
fi
