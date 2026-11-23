FROM ubuntu:22.04

# Configuração de variáveis de ambiente
ENV NVM_VERSION=v0.40.1 \
    NODE_VERSION=22 \
    NVM_DIR=/usr/local/nvm \
    TZ=America/Sao_Paulo \
    PATH=/usr/local/nvm/current/bin:$PATH

WORKDIR /var/www/html

# Configuração de timezone e instalação de pacotes
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone \
    && apt-get update && apt-get upgrade -y \
    && mkdir -p /etc/apt/keyrings \
    && apt-get install -y --no-install-recommends \
       gnupg gosu curl ca-certificates zip unzip git supervisor sqlite3 \
       libcap2-bin libpng-dev dnsutils librsvg2-bin fswatch poppler-utils \
       nano mariadb-client nginx \
    # Configuração do PHP
    && curl -sS 'https://keyserver.ubuntu.com/pks/lookup?op=get&search=0x14aa40ec0831756756d7f66c4f4ea0aae5267a6c' | gpg --dearmor | tee /etc/apt/keyrings/ppa_ondrej_php.gpg > /dev/null \
    && echo "deb [signed-by=/etc/apt/keyrings/ppa_ondrej_php.gpg] https://ppa.launchpadcontent.net/ondrej/php/ubuntu jammy main" > /etc/apt/sources.list.d/ppa_ondrej_php.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
       php8.4 php8.4-fpm php8.4-mbstring php8.4-xml php8.4-bcmath \
       php8.4-curl php8.4-redis php8.4-mysql php8.4-zip php8.4-gd \
       php8.4-intl php8.4-readline php8.4-xsl php8.4-sqlite3 \
    && mkdir -p /run/php \
    # Instalação do Composer
    && curl -sLS https://getcomposer.org/installer | php -- --install-dir=/usr/bin/ --filename=composer \
    # Configuração do NVM
    && mkdir -p $NVM_DIR \
    && curl -o- https://raw.githubusercontent.com/creationix/nvm/$NVM_VERSION/install.sh | bash \
    && . $NVM_DIR/nvm.sh \
    && nvm install $NODE_VERSION \
    && nvm alias default $NODE_VERSION \
    && nvm use default \
    && ln -snf "$NVM_DIR/versions/node/$(nvm version default)" "$NVM_DIR/current" \
    && corepack enable \
    # Limpeza
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

# Configuração dos arquivos
COPY fpm-pool.conf /etc/php/8.4/fpm/pool.d/www.conf
COPY supervisord.conf /etc/supervisord.conf
COPY nginx.conf /etc/nginx/nginx.conf

EXPOSE 80

CMD ["supervisord", "-c", "/etc/supervisord.conf"]
