# KoSIT validator in daemon mode with the XRechnung configuration (EN 16931 and
# XRechnung for CII and UBL) and the Peppol BIS Billing 3.0 rules.
#
#   docker build -t quittio/kosit -f tools/docker/kosit/Dockerfile .
#   docker run --rm -p 8081:8080 quittio/kosit
FROM eclipse-temurin:21-jre

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl unzip \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /opt/quittio
COPY tools/schemas/download.sh tools/schemas/download.sh
COPY packages/core/resources/kosit/peppol-scenarios.xml packages/core/resources/kosit/peppol-scenarios.xml
RUN bash tools/schemas/download.sh

WORKDIR /opt/quittio/tools/validators
EXPOSE 8080

ENTRYPOINT ["java", "-jar", "validator.jar", \
    "-s", "xrechnung/scenarios.xml", "-r", "/opt/quittio/tools/validators/xrechnung", \
    "-s", "/opt/quittio/packages/core/resources/kosit/peppol-scenarios.xml", "-r", "/opt/quittio/tools/validators", \
    "-D", "-G", "-H", "0.0.0.0", "-P", "8080"]
