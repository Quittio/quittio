#!/usr/bin/env bash
# Downloads phpDocumentor into build/tools (not committed) for the API reference of the
# documentation website (docs/_versioning/build.mjs --api). The version is pinned with its SHA-256,
# because the PHAR is executed and release assets can be replaced.
set -euo pipefail

PHPDOC_VERSION="3.10.0"
PHPDOC_SHA256="fe1e7c23ba3329aa6f19ac3c807446159a431a195ec5d9163b0c281a15105207"

root="$(cd "$(dirname "$0")/../.." && pwd)"
target="${root}/build/tools/phpDocumentor.phar"
mkdir -p "$(dirname "${target}")"

if [[ -f "${target}" ]] && echo "${PHPDOC_SHA256}  ${target}" | shasum -a 256 -c - >/dev/null 2>&1; then
    echo "${target}"
    exit 0
fi

tmp="$(mktemp)"
curl -fsSL -o "${tmp}" "https://github.com/phpDocumentor/phpDocumentor/releases/download/v${PHPDOC_VERSION}/phpDocumentor.phar"
if ! echo "${PHPDOC_SHA256}  ${tmp}" | shasum -a 256 -c - >/dev/null 2>&1; then
    rm -f "${tmp}"
    echo "phpDocumentor ${PHPDOC_VERSION}: the download does not match the pinned SHA-256." >&2
    exit 1
fi
mv "${tmp}" "${target}"
echo "${target}"
