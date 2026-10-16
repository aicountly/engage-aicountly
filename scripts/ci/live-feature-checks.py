#!/usr/bin/env python3
"""live-feature-checks.py — Engage's Console staff roles, proven against the live Engage.

    python3 scripts/ci/live-feature-checks.py production

Run by verify-live.yml (workflow_dispatch) and by deploy-production.yml after its post-deploy checks. Signs the
verification accounts in (a Engage session each); changes nothing else.

  * GET /api/health reports console_role_sync configured (CONSOLE_API_URL + this portal's own
    CONSOLE_API_TOKEN: without them a token refresh cannot re-check roles with Console).
  * Sign-in without a Console session is refused; a forged token is not refreshed.
  * The no-role verification account (Console signs it in) is refused by Engage.
  * The staff verification account signs in with its Console roles (VERIFY_STAFF_EXPECTED_ROLES
    compares them) and a refresh — which re-checks Console — succeeds.
  * With LIVE_VERIFY_REVOCATION_DRILL=1 (input revocation_drill): the drill account signs in while
    it holds a engage role, an operator revokes it in Console while the job waits, and the next
    refresh must be refused (live_verify.revocation_drill).

Secrets: the shared VERIFY_IMAP_*, VERIFY_STAFF_*, VERIFY_NOROLE_*, VERIFY_DRILL_* (live_verify.py).
VERIFY_BASE_URL overrides https://engage.aicountly.org (tests).
"""
from __future__ import annotations

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import live_verify as lv  # noqa: E402

BASE = lv.env("VERIFY_BASE_URL", "https://engage.aicountly.org").rstrip("/")
API = f"{BASE}/api"

PORTAL = lv.Portal(
    app_code="engage", name="Engage",
    health_url=f"{API}/health",
    session_url=f"{API}/v1/auth/console-session",
    refresh_url=f"{API}/v1/auth/refresh", refresh_mode="body_token",
)


def main() -> int:
    target = sys.argv[1] if len(sys.argv) > 1 else "production"
    if target != "production":
        print("usage: live-feature-checks.py production    (Engage has production only)", file=sys.stderr)
        return 2
    r = lv.Report("Engage live feature checks")
    lv.portal_access_suite(r, PORTAL, lv.Mailbox())
    return r.finish()


if __name__ == "__main__":
    lv.main_guard(main)
