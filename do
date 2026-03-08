#!/bin/bash
docker exec -it -u 1000:1000 --workdir /var/www/utility php84-web-1 "$@"
