FROM php:8.4-cli-bookworm

ARG USERNAME=dev
ARG USER_UID=1000
ARG USER_GID=$USER_UID

# Dependências do sistema
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    curl \
    zip \
    unzip \
    libpq-dev \
    libzip-dev \
    libicu-dev \
    libonig-dev \
    libxml2-dev \
    libcurl4-openssl-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libwebp-dev \
    sqlite3 \
    libsqlite3-dev \
    openssh-client \
    nano \
    vim \
    sudo \
    zsh \
    wget \
    ca-certificates \
    gnupg \
    && rm -rf /var/lib/apt/lists/*

# Extensões PHP para Laravel
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) \
    pdo \
    pdo_pgsql \
    pdo_sqlite \
    pgsql \
    zip \
    intl \
    bcmath \
    mbstring \
    xml \
    curl \
    gd \
    opcache \
    pcntl \
    exif \
    sockets

# Redis extension
RUN pecl install redis && docker-php-ext-enable redis

# PHP config otimizado para dev
RUN cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini" \
    && echo "memory_limit=512M" >> "$PHP_INI_DIR/conf.d/custom.ini" \
    && echo "upload_max_filesize=100M" >> "$PHP_INI_DIR/conf.d/custom.ini" \
    && echo "post_max_size=100M" >> "$PHP_INI_DIR/conf.d/custom.ini" \
    && echo "max_execution_time=300" >> "$PHP_INI_DIR/conf.d/custom.ini" \
    && echo "display_errors=On" >> "$PHP_INI_DIR/conf.d/custom.ini" \
    && echo "error_reporting=E_ALL" >> "$PHP_INI_DIR/conf.d/custom.ini"

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Node.js 22 LTS (para Vite, Tailwind, Claude Code)
RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y nodejs \
    && rm -rf /var/lib/apt/lists/*

# Usuário não-root
RUN groupadd --gid $USER_GID $USERNAME \
    && useradd --uid $USER_UID --gid $USER_GID -m -s /bin/zsh $USERNAME \
    && echo "$USERNAME ALL=(ALL) NOPASSWD:ALL" >> /etc/sudoers.d/$USERNAME \
    && chmod 0440 /etc/sudoers.d/$USERNAME

# Oh My Zsh para DX
RUN su $USERNAME -c 'sh -c "$(curl -fsSL https://raw.githubusercontent.com/ohmyzsh/ohmyzsh/master/tools/install.sh)" "" --unattended'

# Diretórios de cache/config
RUN mkdir -p /home/$USERNAME/.composer /home/$USERNAME/.npm /home/$USERNAME/.claude /commandhistory \
    && chown -R $USERNAME:$USERNAME /home/$USERNAME /commandhistory

# Bash history persistente
RUN echo 'export HISTFILE=/commandhistory/.zsh_history' >> /home/$USERNAME/.zshrc \
    && echo 'export PROMPT_COMMAND="history -a"' >> /home/$USERNAME/.zshrc

USER $USERNAME
WORKDIR /workspace
