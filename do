#!/usr/bin/env bash

checkNetwork(){
    docker network inspect shopify-orders-development > /dev/null 2>&1 || docker network create shopify-orders-development > /dev/null 2>&1
}

doThis() {
    # The default value for DO_INTERACTIVE, when it is not set, is '-it'.
    docker exec ${DO_INTERACTIVE--it} -u ${SOURCE_UID:-0} ${NICKNAME}-web-1 "$@"
}

doSetup() {
    # Prep the docker-compose.yml file. By default we want local-development
    # mounts; %VOLUMES_ALIAS% can be overridden for other deployment types.
    sed -E "s/^.+%VOLUMES_ALIAS%/    volumes: *${VOLUMES_ALIAS:-local-development-volumes} #%VOLUMES_ALIAS%/" .local-development/docker-compose.yml | \
        BUILD_TARGET=${BUILD_TARGET:-base} COMMIT_HASH=${COMMIT_HASH} \
        SOURCE_UID=${SOURCE_UID} SOURCE_GID=${SOURCE_GID} \
        SERVE_PORT_1=${SERVE_PORT_1} NICKNAME=${NICKNAME} \
        docker compose -f - -p ${NICKNAME} "$@"
}

source .local-development/.env.defaults

if [ -f .local-development/.env ]; then
    source .local-development/.env
fi

if [ $# -gt 0 ];then
    # Initial build
    if [ "$1" == "build" ]; then
        shift 1
        checkNetwork
        doSetup build "$@"

    # Spin up service
    elif [ "$1" == "up" ]; then
        checkNetwork
        doSetup up -d && echo "Serving at http://localhost:${SERVE_PORT_1}" && exit 0
        exit 1

    # Spin down service
    elif [ "$1" == "down" ]; then
        doSetup down

    # Run the DB migration
    elif [ "$1" == "migrate" ]; then
        doThis php scripts/migrate.php

    # Fall through: run any other command inside the web container.
    else
        doThis "$@"
    fi
fi
