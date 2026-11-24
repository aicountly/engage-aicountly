#!/usr/bin/env python3
"""Install only the Email linking service credential, received on stdin. Never prints it."""
import os
import re
import sys
import tempfile
from pathlib import Path


def configure(path, role, secret):
    if role not in ('email', 'engage') or not re.fullmatch(r'[a-f0-9]{64}', secret):
        raise ValueError('Expected the linking role and a 256-bit hex credential on stdin.')
    target = Path(path)
    if not target.is_file() or target.is_symlink():
        raise ValueError('Expected the existing regular API .env file.')
    original = target.read_text()
    pattern = re.compile(r'^\s*([A-Z][A-Z0-9_]*)\s*=\s*(.*?)\s*$')
    existing = {}
    for line in original.splitlines():
        match = pattern.fullmatch(line)
        if match:
            existing[match[1]] = match[2].strip('\"\'')
    key_id = 'email-links-2026-11'
    if role == 'email':
        environment = existing.get('AIC_ENVIRONMENT') or existing.get('APP_ENV')
        if environment not in ('production', 'prod', 'live'):
            raise ValueError('Email must already be explicitly configured as production.')
        values = {'ENGAGE_EMAIL_KEY_ID': key_id, 'ENGAGE_EMAIL_SECRET': secret,
                  'ENGAGE_API_BASE': 'https://engage.aicountly.org', 'EMAIL_ACCOUNT_LINKING_ENABLED': '1', 'EMAIL_DEFAULT_QUOTA_BYTES': '100000000'}
        secret_key = 'ENGAGE_EMAIL_SECRET'
    else:
        if existing.get('ENGAGE_S2S_ENVIRONMENT', '') not in ('', 'production'):
            raise ValueError('Refusing to change a non-production Engage environment.')
        ids = [x.strip() for x in existing.get('ENGAGE_INBOUND_EMAIL_KEY_IDS', '').split(',') if x.strip()]
        if key_id not in ids:
            ids.append(key_id)
        secret_key = 'ENGAGE_INBOUND_EMAIL_SECRET_EMAIL_LINKS_2026_11'
        values = {'ENGAGE_S2S_ENVIRONMENT': 'production', 'ENGAGE_INBOUND_EMAIL_KEY_IDS': ','.join(ids),
                  secret_key: secret, 'ENGAGE_INBOUND_EMAIL_SCOPE_EMAIL_LINKS_2026_11': 'issue'}
    if existing.get(secret_key, '') not in ('', secret):
        raise ValueError('A different linking credential exists; use the documented rotation procedure.')
    lines = [line for line in original.splitlines() if not ((m := pattern.fullmatch(line)) and m[1] in values)]
    result = '\n'.join(lines) + '\n' + ''.join(k + '=' + v + '\n' for k, v in values.items())
    fd, temporary = tempfile.mkstemp(prefix='.email-links-', dir=target.parent)
    try:
        with os.fdopen(fd, 'w') as stream:
            stream.write(result)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temporary, target)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


if __name__ == '__main__':
    try:
        if len(sys.argv) != 3:
            raise ValueError('Usage: configure-email-links.py API_ENV email|engage (credential on stdin)')
        configure(sys.argv[1], sys.argv[2], sys.stdin.read().strip())
        print('Email account-link service configuration installed; no credential values printed.')
    except (ValueError, OSError) as error:
        print('Configuration refused: ' + str(error), file=sys.stderr)
        sys.exit(1)
