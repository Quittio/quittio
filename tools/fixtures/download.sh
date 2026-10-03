#!/usr/bin/env bash
# Downloads the official XRechnung test suite used as golden files.
# The files are not committed; tests that need them are skipped when they are missing.
set -euo pipefail

TESTSUITE_TAG="${TESTSUITE_TAG:-v2026-08-31}"
TESTSUITE_FILE="${TESTSUITE_FILE:-xrechnung-3.0.2-testsuite-2026-08-31.zip}"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TARGET="${ROOT}/packages/country-de/tests/Fixtures/xrechnung-testsuite"

if [[ -d "${TARGET}/instances" ]]; then
    echo "Test suite already present in ${TARGET}"
    exit 0
fi

mkdir -p "${TARGET}"
TMP="$(mktemp -d)"
trap 'rm -rf "${TMP}"' EXIT

curl -fsSL -o "${TMP}/testsuite.zip" \
    "https://github.com/itplr-kosit/xrechnung-testsuite/releases/download/${TESTSUITE_TAG}/${TESTSUITE_FILE}"
unzip -q "${TMP}/testsuite.zip" -d "${TARGET}"

echo "Test suite extracted to ${TARGET}"
