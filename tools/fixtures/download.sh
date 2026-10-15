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

# SHA-256 of the pinned test suite; release assets can be replaced. Other versions need QUITTIO_UNPINNED=1.
case "${TESTSUITE_FILE}" in
    xrechnung-3.0.2-testsuite-2026-08-31.zip) EXPECTED=1e81ef7563e0fa04c0e0b0631318a7e0843a3c0beb57d83ef95ac0c4a095e3d7 ;;
    *) EXPECTED="" ;;
esac
if [[ -n "${EXPECTED}" ]]; then
    if command -v sha256sum > /dev/null; then
        ACTUAL="$(sha256sum "${TMP}/testsuite.zip" | cut -d' ' -f1)"
    else
        ACTUAL="$(shasum -a 256 "${TMP}/testsuite.zip" | cut -d' ' -f1)"
    fi
    if [[ "${ACTUAL}" != "${EXPECTED}" ]]; then
        echo "Checksum mismatch for ${TESTSUITE_FILE}: expected ${EXPECTED}, got ${ACTUAL}." >&2
        exit 1
    fi
elif [[ "${QUITTIO_UNPINNED:-0}" != "1" ]]; then
    echo "No checksum is known for ${TESTSUITE_FILE}; set QUITTIO_UNPINNED=1 to accept it unchecked." >&2
    exit 1
fi
unzip -q "${TMP}/testsuite.zip" -d "${TARGET}"

echo "Test suite extracted to ${TARGET}"
