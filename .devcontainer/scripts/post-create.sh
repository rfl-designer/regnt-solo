#!/bin/bash
set -e

echo "🚀 Configurando ambiente Laravel + Flux UI..."

# ============================================================
# Shell: configurar PATH para Claude CLI
# ============================================================
if ! grep -q 'export PATH="$HOME/.local/bin:$PATH"' ~/.zshrc 2>/dev/null; then
    echo 'export PATH="$HOME/.local/bin:$PATH"' >> ~/.zshrc
    echo "✅ PATH do Claude adicionado ao .zshrc"
fi

# ============================================================
# Claude Plugins: criar symlink para caminhos do host macOS
# ============================================================
# Plugins registram installPath com caminho absoluto do host.
# Dentro do container, ~/.claude é montado em /home/dev/.claude,
# mas os caminhos no installed_plugins.json apontam para /Users/<user>/.claude.
# O symlink resolve esse mismatch.
if [ -f "$HOME/.claude/plugins/installed_plugins.json" ]; then
    HOST_CLAUDE_DIR=$(grep -oP '"installPath":\s*"\K[^"]+' "$HOME/.claude/plugins/installed_plugins.json" | head -1 | sed 's|/.claude/.*|/.claude|')
    if [ -n "$HOST_CLAUDE_DIR" ] && [ "$HOST_CLAUDE_DIR" != "$HOME/.claude" ] && [ ! -e "$HOST_CLAUDE_DIR" ]; then
        sudo mkdir -p "$(dirname "$HOST_CLAUDE_DIR")"
        sudo ln -sf "$HOME/.claude" "$HOST_CLAUDE_DIR"
        echo "✅ Symlink criado: $HOST_CLAUDE_DIR -> $HOME/.claude (plugins compatíveis)"
    fi
fi

# ============================================================
# Composer: instalar dependências
# ============================================================
if [ -f "composer.json" ]; then
    echo "📦 Instalando dependências do Composer..."

    # Configurar autenticação do Flux Pro (se licença disponível)
    if [ -n "$FLUX_EMAIL" ] && [ -n "$FLUX_LICENSE_KEY" ]; then
        echo "🔑 Configurando autenticação Flux Pro..."
        composer config http-basic.composer.fluxui.dev "$FLUX_EMAIL" "$FLUX_LICENSE_KEY"
        echo "✅ Flux Pro autenticado com sucesso"
    fi

    # Fallback: usar COMPOSER_AUTH se definido
    if [ -n "$COMPOSER_AUTH" ]; then
        echo "$COMPOSER_AUTH" > auth.json
        echo "✅ auth.json criado via COMPOSER_AUTH"
    fi

    composer install --no-interaction --prefer-dist --optimize-autoloader
fi

# ============================================================
# NPM: instalar dependências frontend
# ============================================================
if [ -f "package.json" ]; then
    echo "📦 Instalando dependências NPM..."
    npm install
fi

# ============================================================
# Laravel: setup inicial
# ============================================================
if [ -f "artisan" ]; then
    echo "⚙️  Configurando Laravel..."

    # Copiar .env se não existir
    if [ ! -f ".env" ]; then
        if [ -f ".env.devcontainer" ]; then
            cp .env.devcontainer .env
        elif [ -f ".env.example" ]; then
            cp .env.example .env
        fi
    fi

    # Gerar app key se necessário
    php artisan key:generate --force --no-interaction 2>/dev/null || true

    # Aguardar PostgreSQL e rodar migrations
    echo "⏳ Aguardando PostgreSQL..."
    sleep 3
    php artisan migrate --force --no-interaction 2>/dev/null || echo "⚠️  Migrations pendentes (rode manualmente)"

    # Storage link
    php artisan storage:link 2>/dev/null || true

    # Cache de config/rotas
    php artisan config:clear
    php artisan route:clear
    php artisan view:clear
fi

echo ""
echo "============================================"
echo "✅ Ambiente pronto!"
echo "============================================"
echo ""
echo "  🌐 Laravel:    php artisan serve --host=0.0.0.0"
echo "  ⚡ Vite:       npm run dev -- --host 0.0.0.0"
echo "  🤖 Claude:     claude"
echo "  🗄️  PostgreSQL: postgres:5432 (laravel/secret)"
echo "  📮 Redis:      redis:6379"
echo ""
