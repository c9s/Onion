#!/bin/bash
./scripts/onion -d compile \
    --lib src \
    --lib vendor/pear \
    --classloader \
    --bootstrap scripts/onion.embed \
    --executable \
    --output onion.phar
