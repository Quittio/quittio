#!/usr/bin/env bash
# Downloads the official validation artifacts into tools/validators (not committed).
# Versions are pinned so that CI runs are reproducible.
set -euo pipefail

KOSIT_VALIDATOR_VERSION="${KOSIT_VALIDATOR_VERSION:-1.6.3}"
XRECHNUNG_CONFIG_TAG="${XRECHNUNG_CONFIG_TAG:-v2026-08-31}"
XRECHNUNG_CONFIG_FILE="${XRECHNUNG_CONFIG_FILE:-xrechnung-3.0.2-validator-configuration-2026-08-31.zip}"
PEPPOL_BIS_TAG="${PEPPOL_BIS_TAG:-v3.0.20}"
SCHXSLT_VERSION="${SCHXSLT_VERSION:-1.10.1}"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TARGET="${ROOT}/tools/validators"
mkdir -p "${TARGET}"
cd "${TARGET}"

if [[ ! -f validator.jar ]]; then
    echo "Downloading KoSIT validator ${KOSIT_VALIDATOR_VERSION}"
    curl -fsSL -o validator.jar \
        "https://repo1.maven.org/maven2/org/kosit/validator/${KOSIT_VALIDATOR_VERSION}/validator-${KOSIT_VALIDATOR_VERSION}-standalone.jar"
fi

if [[ ! -f xrechnung/scenarios.xml ]]; then
    echo "Downloading XRechnung validator configuration ${XRECHNUNG_CONFIG_TAG}"
    curl -fsSL -o xrechnung.zip \
        "https://github.com/itplr-kosit/validator-configuration-xrechnung/releases/download/${XRECHNUNG_CONFIG_TAG}/${XRECHNUNG_CONFIG_FILE}"
    rm -rf xrechnung && mkdir xrechnung
    unzip -q xrechnung.zip -d xrechnung
    rm xrechnung.zip
fi

if [[ ! -f peppol/PEPPOL-EN16931-UBL.xsl ]]; then
    echo "Compiling Peppol BIS Billing 3.0 rules ${PEPPOL_BIS_TAG}"
    mkdir -p peppol
    curl -fsSL -o peppol/PEPPOL-EN16931-UBL.sch \
        "https://raw.githubusercontent.com/OpenPEPPOL/peppol-bis-invoice-3/${PEPPOL_BIS_TAG}/rules/sch/PEPPOL-EN16931-UBL.sch"
    curl -fsSL -o peppol/schxslt.jar \
        "https://repo1.maven.org/maven2/name/dmaus/schxslt/schxslt/${SCHXSLT_VERSION}/schxslt-${SCHXSLT_VERSION}.jar"
    rm -rf peppol/schxslt && mkdir peppol/schxslt
    unzip -q peppol/schxslt.jar -d peppol/schxslt
    # The KoSIT validator bundles Saxon HE, which compiles the Schematron into XSLT 2.0.
    java -cp validator.jar net.sf.saxon.Transform \
        -s:peppol/PEPPOL-EN16931-UBL.sch \
        -xsl:peppol/schxslt/xslt/2.0/pipeline-for-svrl.xsl \
        -o:peppol/PEPPOL-EN16931-UBL.xsl
fi

echo "Validator artifacts are available in ${TARGET}"
