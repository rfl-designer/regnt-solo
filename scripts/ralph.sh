#!/usr/bin/env bash
# Ralph Loop — SoloBoard Integration
# Usage: ./scripts/ralph.sh [--tool claude|codex] [max_iterations]
#
# Runs an autonomous AI coding loop that picks user stories from
# storage/ralph/prd.json and implements them one at a time.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
RALPH_DIR="${PROJECT_DIR}/storage/ralph"

# Defaults
TOOL="claude"
MAX_ITERATIONS=10

# Helper: parse JSON with php (no jq dependency)
prd_read() {
    php -r "\$d=json_decode(file_get_contents('${RALPH_DIR}/prd.json'),true);echo $1;"
}

# Parse arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        --tool)
            TOOL="$2"
            shift 2
            ;;
        --help|-h)
            echo "Usage: $0 [--tool claude|codex] [max_iterations]"
            echo ""
            echo "Options:"
            echo "  --tool    AI tool to use (default: claude)"
            echo "  max_iterations  Maximum number of iterations (default: 10)"
            exit 0
            ;;
        *)
            MAX_ITERATIONS="$1"
            shift
            ;;
    esac
done

# Validate Ralph files exist
if [[ ! -f "${RALPH_DIR}/prd.json" ]]; then
    echo "Error: ${RALPH_DIR}/prd.json not found."
    echo "Run: php artisan soloboard:ralph <feature_id>"
    exit 1
fi

if [[ ! -f "${RALPH_DIR}/CLAUDE.md" ]]; then
    echo "Error: ${RALPH_DIR}/CLAUDE.md not found."
    echo "Run: php artisan soloboard:ralph <feature_id>"
    exit 1
fi

# Archive previous run if progress exists
if [[ -s "${RALPH_DIR}/progress.txt" ]]; then
    ARCHIVE_DIR="${RALPH_DIR}/archive/$(date +%Y%m%d_%H%M%S)"
    mkdir -p "$ARCHIVE_DIR"
    cp "${RALPH_DIR}/progress.txt" "$ARCHIVE_DIR/"
    cp "${RALPH_DIR}/prd.json" "$ARCHIVE_DIR/"
    echo "Archived previous run to ${ARCHIVE_DIR}"
    : > "${RALPH_DIR}/progress.txt"
fi

echo "=== Ralph Loop ==="
echo "Project: $(prd_read "\$d['project']")"
echo "Stories: $(prd_read "count(\$d['userStories'])")"
echo "Pending: $(prd_read "count(array_filter(\$d['userStories'],fn(\$s)=>!\$s['passes']))")"
echo "Max iterations: ${MAX_ITERATIONS}"
echo "Tool: ${TOOL}"
echo "=================="
echo ""

cd "$PROJECT_DIR"

for i in $(seq 1 "$MAX_ITERATIONS"); do
    # Check if all stories pass
    PENDING=$(prd_read "count(array_filter(\$d['userStories'],fn(\$s)=>!\$s['passes']))")
    if [[ "$PENDING" -eq 0 ]]; then
        echo ""
        echo "=== All stories pass! Loop complete. ==="
        exit 0
    fi

    echo ""
    echo "--- Iteration ${i}/${MAX_ITERATIONS} (${PENDING} stories remaining) ---"
    echo ""

    # Run the AI tool
    if [[ "$TOOL" == "claude" ]]; then
        OUTPUT=$(claude --dangerously-skip-permissions --print < "${RALPH_DIR}/CLAUDE.md" 2>&1) || true
    elif [[ "$TOOL" == "codex" ]]; then
        OUTPUT=$(codex --approval-mode full-auto --input-file "${RALPH_DIR}/CLAUDE.md" 2>&1) || true
    else
        echo "Error: Unknown tool '${TOOL}'"
        exit 1
    fi

    echo "$OUTPUT"

    # Check for completion signal
    if echo "$OUTPUT" | grep -q "<promise>COMPLETE</promise>"; then
        echo ""
        echo "=== COMPLETE signal received. Loop finished. ==="
        exit 0
    fi

    # Brief pause between iterations
    if [[ "$i" -lt "$MAX_ITERATIONS" ]]; then
        sleep 2
    fi
done

echo ""
echo "=== Max iterations (${MAX_ITERATIONS}) reached. ==="
REMAINING=$(prd_read "count(array_filter(\$d['userStories'],fn(\$s)=>!\$s['passes']))")
echo "Stories remaining: ${REMAINING}"
exit 1
