# Email fallback and linked accounts

For a new Email Basic offer, seed `email.max_users = 3` and `email.storage_bytes_per_user = 100000000` (100 decimal MB). The Basic-offer command creates a draft only and preserves existing offers. Existing published offers require a reviewed revision through the existing offer lifecycle; running the generator does not update them.

The Email API implements the missing-plan fallback. Three users means distinct people with access, not three mailboxes. The current Email patch limits individual mailbox quotas; aggregate allocation for a person with multiple mailboxes must be completed before this entitlement is advertised as an enforced per-user allowance.

Account linking will be initiated only in aicountly.io Settings. Engage must eventually consolidate Email licences under the verified main AICOUNTLY holder, preserve company scope and purchased validity, deduplicate free allowances, and attribute new usage to that holder while retaining the child actor. This branch does not implement licence consolidation or change any licence holder.

## Verified Email account consolidation

`POST /api/v1/email/account-links/preview` and `/commit` require an Email-specific AIC-S2S issue credential. Email verifies both identities and explicit consent; Engage owns the transfer transaction. Preview includes a version digest, company scope and expiry. Commit is idempotent by link ID and source identity, preserves company scope and historical records, emits holder-change events and outbox updates, and prevents cycles or duplicate free-plan stacking. Mixed-product bundles are refused before any change. Subsequent Email purchases and Basic provisioning resolve the canonical holder; other products do not.

Deployment uses the optional `configure_email_links` workflow input and shared `EMAIL_ACCOUNT_LINK_SECRET` repository secret. Deploy Engage first, then Connect, Email and aicountly.io. The new database migration is required before any linking is enabled. Actual user links require interactive dual-account proof and confirmation in aicountly.io Settings.
