#!/bin/bash
set -e

echo "🔄 Iniciando serviços..."

# ============================================================
# Claude Code: instalar/atualizar
# ============================================================
if ! command -v claude &> /dev/null; then
    echo "🤖 Instalando Claude Code..."
    curl -fsSL https://claude.ai/install.sh | bash
else
    echo "🤖 Claude Code já instalado: $(claude --version 2>/dev/null || echo 'ok')"
fi

# ============================================================
# Laravel: garantir que artisan existe antes de rodar comandos
# ============================================================
if [ -f "artisan" ]; then
    # Limpar caches stale
    php artisan config:clear 2>/dev/null || true
    php artisan cache:clear 2>/dev/null || true
fi

echo "✅ Container pronto para uso!"
