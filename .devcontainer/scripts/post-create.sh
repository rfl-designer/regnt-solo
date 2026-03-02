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
# Claude Plugins: criar symlinks para caminhos do host macOS
# ============================================================
# Plugins registram installPath com caminho absoluto do host (ex: /Users/xxx/.claude).
# Dentro do container, ~/.claude é montado em /home/dev/.claude.
# Criamos symlinks para que os caminhos absolutos do host resolvam corretamente.
if [ -f "$HOME/.claude/plugins/installed_plugins.json" ]; then
    # Extrair todos os caminhos únicos de usuário do host
    HOST_PATHS=$(grep -oE '"/Users/[^/]+/\.claude' "$HOME/.claude/plugins/installed_plugins.json" | tr -d '"' | sort -u)

    for HOST_CLAUDE_DIR in $HOST_PATHS; do
        if [ -n "$HOST_CLAUDE_DIR" ] && [ "$HOST_CLAUDE_DIR" != "$HOME/.claude" ]; then
            # Criar diretório pai se não existir
            sudo mkdir -p "$(dirname "$HOST_CLAUDE_DIR")"
            # Remover symlink antigo se existir
            sudo rm -f "$HOST_CLAUDE_DIR" 2>/dev/null || true
            # Criar novo symlink
            sudo ln -sf "$HOME/.claude" "$HOST_CLAUDE_DIR"
            echo "✅ Symlink: $HOST_CLAUDE_DIR -> $HOME/.claude"
        fi
    done
fi

# Também criar symlink para ~/.claude.json (configuração principal)
if [ -f "$HOME/.claude.json" ]; then
    HOST_PATHS=$(grep -oE '"/Users/[^/]+' "$HOME/.claude/plugins/installed_plugins.json" 2>/dev/null | tr -d '"' | sort -u)

    for HOST_HOME in $HOST_PATHS; do
        if [ -n "$HOST_HOME" ] && [ "$HOST_HOME" != "$HOME" ] && [ ! -e "$HOST_HOME/.claude.json" ]; then
            sudo ln -sf "$HOME/.claude.json" "$HOST_HOME/.claude.json" 2>/dev/null || true
            echo "✅ Symlink: $HOST_HOME/.claude.json -> $HOME/.claude.json"
        fi
    done
fi

# ============================================================
# Claude MCP: configurar servidores MCP para o container
# ============================================================
# Usa .mcp.json específico do devcontainer (sem Herd que é macOS-only)
# Substitui variáveis de ambiente no arquivo
if [ -f ".devcontainer/.mcp.json" ]; then
    envsubst < .devcontainer/.mcp.json > .mcp.json
    echo "✅ Configuração MCP do devcontainer aplicada"
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
