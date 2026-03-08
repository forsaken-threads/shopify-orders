#!/bin/bash
args=(-u 1000:1000 --workdir /var/www/utility)
[ -t 0 ] && args=(-it "${args[@]}")
docker exec "${args[@]}" php84-web-1 "$@"
