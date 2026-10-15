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

shopt -s nullglob
inputs=("${OUT}"/*.xml)
pdfs=("${OUT}"/*.pdf)
if (( ${#inputs[@]} == 0 )); then
    echo "No XML fixtures were generated." >&2
    exit 1
fi

mkdir -p "${OUT}/reports"
# The validator exits with a non-zero code when it rejects a document; the reports decide below.
java -jar "${VALIDATORS}/validator.jar" \
    -s "${VALIDATORS}/xrechnung/scenarios.xml" -r "${VALIDATORS}/xrechnung" \
    -s "${ROOT}/packages/core/resources/kosit/peppol-scenarios.xml" -r "${VALIDATORS}" \
    -o "${OUT}/reports" "${inputs[@]}" > "${OUT}/kosit.log" 2>&1 || true

# Every file needs a report: a crashed validator must not count as "all accepted".
missing=()
for input in "${inputs[@]}"; do
    [[ -f "${OUT}/reports/$(basename "${input}" .xml)-report.xml" ]] || missing+=("$(basename "${input}")")
done
if (( ${#missing[@]} > 0 )); then
    echo "KoSIT wrote no report for ${#missing[@]} of ${#inputs[@]} files:" >&2
    printf '  %s\n' "${missing[@]}" >&2
    echo "Last lines of ${OUT}/kosit.log:" >&2
    tail -n 20 "${OUT}/kosit.log" >&2
    exit 1
fi

# EXTENDED is not covered by the KoSIT configuration; those files are checked against the XSD only.
rejected="$(grep -L '<rep:accept>' "${OUT}"/reports/*-report.xml | grep -v '\.extended\.' || true)"
if [[ -n "${rejected}" ]]; then
    echo "Official validation failed for:" >&2
    echo "${rejected}" >&2
    exit 1
fi
echo "KoSIT: ${#inputs[@]} reports, all accepted (EXTENDED excluded)."

if (( ${#pdfs[@]} > 0 )); then
    if command -v docker > /dev/null; then
        docker run --rm -v "${OUT}:/data" verapdf/cli --flavour 3b /data/*.pdf | tee "${OUT}/verapdf.log"
        # One report per PDF, each compliant; a report without results must not pass.
        compliant="$(grep -o 'isCompliant="true"' "${OUT}/verapdf.log" | wc -l | tr -d ' ')"
        if [[ "${compliant}" != "${#pdfs[@]}" ]]; then
            echo "PDF/A validation failed: ${compliant} of ${#pdfs[@]} PDFs are compliant." >&2
            exit 1
        fi
        echo "veraPDF: ${#pdfs[@]} PDFs, all PDF/A-3b compliant."
    elif [[ "${CI:-}" == "true" ]]; then
        echo "Docker is not available: the ${#pdfs[@]} PDFs cannot be checked with veraPDF." >&2
        exit 1
    else
        echo "Docker is not available: the ${#pdfs[@]} PDFs were not checked with veraPDF." >&2
    fi
fi

echo "All fixtures passed the official validation."
