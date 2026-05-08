#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
HOOK_SOURCE="${ROOT_DIR}/scripts/git-hooks/pre-push-clinical-copilot-evals"
HOOK_TARGET="${ROOT_DIR}/.git/hooks/pre-push"

if [[ ! -d "${ROOT_DIR}/.git" ]]; then
  echo "Git repository metadata not found at ${ROOT_DIR}/.git"
  exit 1
fi

if [[ ! -f "${HOOK_SOURCE}" ]]; then
  echo "Hook source not found: ${HOOK_SOURCE}"
  exit 1
fi

install -m 0755 "${HOOK_SOURCE}" "${HOOK_TARGET}"
echo "Installed clinical co-pilot pre-push hook at ${HOOK_TARGET}"
echo "CI remains the mandatory PR-blocking gate. This hook is only local convenience."
