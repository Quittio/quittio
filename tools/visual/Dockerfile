# Linux environment like the CI visual job (Ubuntu 24.04, PHP 8.4 from the ondrej PPA, poppler).
FROM --platform=linux/amd64 ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive
RUN apt-get update \
    && apt-get install -y --no-install-recommends software-properties-common gpg-agent ca-certificates \
    && add-apt-repository -y ppa:ondrej/php \
    && apt-get update \
    && apt-get install -y --no-install-recommends php8.4-cli php8.4-gd php8.4-intl php8.4-mbstring php8.4-xml php8.4-zip php8.4-bcmath poppler-utils \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
