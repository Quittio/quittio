#!/usr/bin/env bash
# Downloads the official validation artifacts into tools/validators (not committed).
# Versions are pinned so that CI runs are reproducible.
set -euo pipefail

KOSIT_VALIDATOR_VERSION="${KOSIT_VALIDATOR_VERSION:-1.6.3}"
XRECHNUNG_CONFIG_TAG="${XRECHNUNG_CONFIG_TAG:-v2026-08-31}"
XRECHNUNG_CONFIG_FILE="${XRECHNUNG_CONFIG_FILE:-xrechnung-3.0.2-validator-configuration-2026-08-31.zip}"
PEPPOL_BIS_TAG="${PEPPOL_BIS_TAG:-v3.0.20}"
SCHXSLT_VERSION="${SCHXSLT_VERSION:-1.10.1}"

# SHA-256 of the pinned versions (the same as KositArtifacts::SHA256). The validator JAR is run with
# java and release tags can be moved, so a download that does not match is discarded. Versions
# overridden through the environment have no checksum here and are only checked with QUITTIO_UNPINNED=1.
expected_sha256() {
    case "$1" in
        validator-1.6.3-standalone.jar) echo 799e64befca97d4080e03608c80b85dd5a5ecc5f4ae4f35d1116ec2855b9a7c9 ;;
        xrechnung-3.0.2-validator-configuration-2026-08-31.zip) echo 2530cd107c414511c5d0462ec10f886910395abfca820db82e83d70bf01221a8 ;;
        PEPPOL-EN16931-UBL.sch@v3.0.20) echo 5ddf3a2f6633147b20b7805df9902d825af1a984f10014fcee186d4170364b1d ;;
        schxslt-1.10.1.jar) echo 4f4f21edab7b37f96ad59ae12a344d3510f1092ac46b6d81a4efa0120b73cb58 ;;
    esac
}

# download URL FILE KEY: downloads URL to FILE and checks it against the checksum of KEY.
download() {
    local url="$1" file="$2" key="$3" expected actual
    curl -fsSL -o "${file}" "${url}"
    expected="$(expected_sha256 "${key}")"
    if [[ -z "${expected}" ]]; then
        if [[ "${QUITTIO_UNPINNED:-0}" != "1" ]]; then
            rm -f "${file}"
            echo "No checksum is known for ${key}; set QUITTIO_UNPINNED=1 to accept it unchecked." >&2
            exit 1
        fi
        echo "Warning: ${key} is not checked against a checksum." >&2
        return
    fi
    if command -v sha256sum > /dev/null; then
        actual="$(sha256sum "${file}" | cut -d' ' -f1)"
    else
        actual="$(shasum -a 256 "${file}" | cut -d' ' -f1)"
    fi
    if [[ "${actual}" != "${expected}" ]]; then
        rm -f "${file}"
        echo "Checksum mismatch for ${key}: expected ${expected}, got ${actual}. The download was discarded." >&2
        exit 1
    fi
}

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TARGET="${ROOT}/tools/validators"
mkdir -p "${TARGET}"
cd "${TARGET}"

if [[ ! -f validator.jar ]]; then
    echo "Downloading KoSIT validator ${KOSIT_VALIDATOR_VERSION}"
    download "https://repo1.maven.org/maven2/org/kosit/validator/${KOSIT_VALIDATOR_VERSION}/validator-${KOSIT_VALIDATOR_VERSION}-standalone.jar" \
        validator.jar "validator-${KOSIT_VALIDATOR_VERSION}-standalone.jar"
fi

if [[ ! -f xrechnung/scenarios.xml ]]; then
    echo "Downloading XRechnung validator configuration ${XRECHNUNG_CONFIG_TAG}"
    download "https://github.com/itplr-kosit/validator-configuration-xrechnung/releases/download/${XRECHNUNG_CONFIG_TAG}/${XRECHNUNG_CONFIG_FILE}" \
        xrechnung.zip "${XRECHNUNG_CONFIG_FILE}"
    rm -rf xrechnung && mkdir xrechnung
    unzip -q xrechnung.zip -d xrechnung
    rm xrechnung.zip
fi

if [[ ! -f peppol/PEPPOL-EN16931-UBL.xsl ]]; then
    echo "Compiling Peppol BIS Billing 3.0 rules ${PEPPOL_BIS_TAG}"
    mkdir -p peppol
    download "https://raw.githubusercontent.com/OpenPEPPOL/peppol-bis-invoice-3/${PEPPOL_BIS_TAG}/rules/sch/PEPPOL-EN16931-UBL.sch" \
        peppol/PEPPOL-EN16931-UBL.sch "PEPPOL-EN16931-UBL.sch@${PEPPOL_BIS_TAG}"
    download "https://repo1.maven.org/maven2/name/dmaus/schxslt/schxslt/${SCHXSLT_VERSION}/schxslt-${SCHXSLT_VERSION}.jar" \
        peppol/schxslt.jar "schxslt-${SCHXSLT_VERSION}.jar"
    rm -rf peppol/schxslt && mkdir peppol/schxslt
    unzip -q peppol/schxslt.jar -d peppol/schxslt
    # The KoSIT validator bundles Saxon HE, which compiles the Schematron into XSLT 2.0.
    java -cp validator.jar net.sf.saxon.Transform \
        -s:peppol/PEPPOL-EN16931-UBL.sch \
        -xsl:peppol/schxslt/xslt/2.0/pipeline-for-svrl.xsl \
        -o:peppol/PEPPOL-EN16931-UBL.xsl
fi

echo "Validator artifacts are available in ${TARGET}"
