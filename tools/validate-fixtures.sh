#!/usr/bin/env bash
# Generates all example invoices and validates them with the official validators
# (KoSIT validator with the XRechnung configuration and the Peppol BIS rules).
# PDF/A checks with veraPDF run when Docker is available and PDFs were generated.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VALIDATORS="${ROOT}/tools/validators"
OUT="${ROOT}/build/fixtures"

"${ROOT}/tools/schemas/download.sh"

rm -rf "${OUT}" && mkdir -p "${OUT}"
php "${ROOT}/tools/validate-fixtures.php" "${OUT}"

mkdir -p "${OUT}/reports"
java -jar "${VALIDATORS}/validator.jar" \
    -s "${VALIDATORS}/xrechnung/scenarios.xml" -r "${VALIDATORS}/xrechnung" \
    -s "${ROOT}/packages/core/resources/kosit/peppol-scenarios.xml" -r "${VALIDATORS}" \
    -o "${OUT}/reports" "${OUT}"/*.xml > "${OUT}/kosit.log" 2>&1 || true

# EXTENDED is not covered by the KoSIT configuration; those files are checked against the XSD only.
rejected="$(grep -L '<rep:accept>' "${OUT}"/reports/*-report.xml | grep -v '\.extended\.' || true)"
if [[ -n "${rejected}" ]]; then
    echo "Official validation failed for:" >&2
    echo "${rejected}" >&2
    exit 1
fi
echo "KoSIT: $(ls "${OUT}"/reports/*-report.xml | wc -l | tr -d ' ') reports, all accepted (EXTENDED excluded)."

if compgen -G "${OUT}/*.pdf" > /dev/null && command -v docker > /dev/null; then
    docker run --rm -v "${OUT}:/data" verapdf/cli --flavour 3b /data/*.pdf | tee "${OUT}/verapdf.log"
    if grep -q 'isCompliant="false"' "${OUT}/verapdf.log"; then
        echo "PDF/A validation failed." >&2
        exit 1
    fi
fi

echo "All fixtures passed the official validation."
