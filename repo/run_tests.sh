#!/usr/bin/env bash
# ============================================================
# Unified test runner for Admissions System
# Self-sufficient: installs deps automatically.
#   - Backend: uses local PHP or spins up a Docker test container
#   - Frontend: uses local Node
#
# Usage:  ./run_tests.sh              Run all tests
#         ./run_tests.sh backend      Run backend tests only
#         ./run_tests.sh frontend     Run frontend tests only
#         ./run_tests.sh unit         Run unit tests only (both)
#         ./run_tests.sh api          Run API/integration tests only
# ============================================================
set -eo pipefail

ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"
BACKEND_DIR="$ROOT_DIR/backend"
FRONTEND_DIR="$ROOT_DIR/frontend"
FILTER="${1:-all}"
TEST_CONTAINER="admissions-test-runner"

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

pass=0
fail=0

HAS_PHP=false
HAS_NODE=false
command -v php &>/dev/null && command -v composer &>/dev/null && HAS_PHP=true
command -v node &>/dev/null && HAS_NODE=true

# ── Frontend deps ─────────────────────────────────────────

ensure_frontend_deps() {
    cd "$FRONTEND_DIR"
    if [ ! -d node_modules ]; then
        echo -e "${CYAN}  Installing frontend dependencies…${NC}"
        npm install --silent 2>&1
        echo -e "${GREEN}  Done.${NC}"
    fi
}

# ── Backend via Docker (ephemeral container) ──────────────
# Mounts backend/ into a PHP+composer image, installs deps
# with dev, then runs phpunit.  SQLite in-memory — no MySQL.

docker_backend_test() {
    local suite="$1"

    # Remove leftover test container if present
    docker rm -f "$TEST_CONTAINER" &>/dev/null || true

    echo -e "${CYAN}  Launching test container…${NC}"
    # MSYS_NO_PATHCONV prevents Git Bash from mangling /app to C:/Program Files/Git/app
    MSYS_NO_PATHCONV=1 docker run --rm --name "$TEST_CONTAINER" \
        -v "$BACKEND_DIR:/app" \
        -w //app \
        -e APP_ENV=testing \
        -e DB_CONNECTION=sqlite \
        -e "DB_DATABASE=:memory:" \
        -e SESSION_TOKEN_SECRET=test-secret-key-for-testing-purposes-only \
        -e ENCRYPTION_KEY=0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef \
        -e QUEUE_CONNECTION=sync \
        composer:2 \
        sh -c "
            composer install --no-interaction --quiet 2>/dev/null
            ./vendor/bin/phpunit --testsuite $suite --colors=always
        "
}

# ── Backend via local PHP ─────────────────────────────────

local_backend_test() {
    local suite="$1"
    cd "$BACKEND_DIR"
    if [ ! -d vendor ] || [ ! -f vendor/bin/phpunit ]; then
        echo -e "${CYAN}  Installing backend dependencies…${NC}"
        composer install --no-interaction --quiet
    fi
    ./vendor/bin/phpunit --testsuite "$suite" --colors=always
}

# ── Test suite runners ────────────────────────────────────

run_backend_suite() {
    local suite="$1"
    local label="$2"
    echo -e "${YELLOW}── $label ──${NC}"

    if [ "$HAS_PHP" = true ]; then
        local_backend_test "$suite" && pass=$((pass+1)) || fail=$((fail+1))
    else
        docker_backend_test "$suite" && pass=$((pass+1)) || fail=$((fail+1))
    fi
}

run_backend_unit() { run_backend_suite "Unit" "Backend unit tests"; }
run_backend_api()  { run_backend_suite "API"  "Backend API tests"; }

run_frontend_unit() {
    echo -e "${YELLOW}── Frontend unit tests ──${NC}"
    if [ "$HAS_NODE" = false ]; then
        echo -e "${RED}  Node.js not found — skipping frontend tests.${NC}"
        fail=$((fail+1))
        return
    fi
    ensure_frontend_deps
    cd "$FRONTEND_DIR"
    npx vitest run --reporter=verbose && pass=$((pass+1)) || fail=$((fail+1))
}

run_e2e() {
    echo -e "${YELLOW}── E2E tests (Playwright) ──${NC}"
    if [ "$HAS_NODE" = false ]; then
        echo -e "${RED}  Node.js not found — skipping e2e tests.${NC}"
        fail=$((fail+1))
        return
    fi
    ensure_frontend_deps
    cd "$FRONTEND_DIR"

    # Check if Playwright browsers are installed
    if ! npx playwright --version &>/dev/null; then
        echo -e "${CYAN}  Installing Playwright…${NC}"
        npm install --save-dev @playwright/test 2>&1
    fi
    if [ ! -d "$HOME/.cache/ms-playwright" ] && [ ! -d "$APPDATA/ms-playwright" ]; then
        echo -e "${CYAN}  Installing Playwright browsers…${NC}"
        npx playwright install chromium 2>&1
    fi

    # Verify the app is reachable
    local BASE_URL="${E2E_BASE_URL:-http://localhost:8000}"
    if ! curl -s -o /dev/null -w "" "$BASE_URL" 2>/dev/null; then
        echo -e "${CYAN}  App not running at $BASE_URL — starting Docker stack…${NC}"
        cd "$ROOT_DIR"
        docker compose up -d --wait 2>&1
        # Wait for app readiness
        local retries=0
        while ! curl -s -o /dev/null "$BASE_URL" 2>/dev/null; do
            retries=$((retries+1))
            if [ "$retries" -ge 60 ]; then
                echo -e "${RED}  App not ready at $BASE_URL after 120s — aborting e2e.${NC}"
                fail=$((fail+1))
                return
            fi
            sleep 2
        done
        echo -e "${GREEN}  App is ready.${NC}"
        cd "$FRONTEND_DIR"
    fi

    # Clear rate-limit data so logins don't get throttled from prior runs
    echo -e "${CYAN}  Clearing rate-limit data…${NC}"
    docker exec repo-app-1 php -r "
        require '/var/www/html/vendor/autoload.php';
        \\\$app = require '/var/www/html/bootstrap/app.php';
        \\\$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
        \App\Models\LoginAttempt::truncate();
    " 2>/dev/null || true

    npx playwright test --reporter=list && pass=$((pass+1)) || fail=$((fail+1))
}

# ── Main ──────────────────────────────────────────────────

echo ""
echo "============================================================"
echo "  Admissions System — Test Runner"
echo "  Filter:   $FILTER"
echo "  Backend:  $([ "$HAS_PHP" = true ] && echo "local PHP $(php -r 'echo PHP_VERSION;')" || echo "Docker (composer:2)")"
echo "  Frontend: $([ "$HAS_NODE" = true ] && echo "local Node $(node --version)" || echo "not available")"
echo "============================================================"
echo ""

case "$FILTER" in
    all)
        run_backend_unit;  echo ""
        run_backend_api;   echo ""
        run_frontend_unit; echo ""
        run_e2e
        ;;
    backend)
        run_backend_unit;  echo ""
        run_backend_api
        ;;
    frontend)
        run_frontend_unit
        ;;
    unit)
        run_backend_unit;  echo ""
        run_frontend_unit
        ;;
    api)
        run_backend_api
        ;;
    e2e)
        run_e2e
        ;;
    *)
        echo -e "${RED}Unknown filter: $FILTER${NC}"
        echo "Usage: $0 [all|backend|frontend|unit|api|e2e]"
        exit 1
        ;;
esac

echo ""
echo "============================================================"
if [ $fail -eq 0 ]; then
    echo -e "  ${GREEN}All $pass suite(s) passed.${NC}"
else
    echo -e "  ${RED}$fail suite(s) failed, $pass passed.${NC}"
fi
echo "============================================================"

exit $fail
