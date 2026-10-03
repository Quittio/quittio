#!/usr/bin/env bash
# Runs the visual regression test in a container that matches the CI image
# (Ubuntu 24.04, PHP 8.4, poppler), e.g. after updating the baselines on macOS.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

docker build --platform linux/amd64 -t quittio-visual "${ROOT}/tools/visual"
docker run --rm --platform linux/amd64 -e CI=1 -v "${ROOT}:/app" quittio-visual \
    php vendor/bin/pest --group=visual
