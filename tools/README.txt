Ops scripts for the fralenuvole plugin.

- backup-local.sh
Zips the plugin into a versioned backup archive.
LOCAL ONLY (hardcoded local mirror paths).

- deploy-remote.sh  Git-pulls latest main to production + PHP lint check.
KINSTA SSH ONLY (hardcoded server path). Supports
--dry-run and -y/--yes.

- sync-to-fralenuvole.sh  Mirrors the plugin into the Fralenuvole.art workspace
(repo fralenuvole.art.git), excluding IDE/git and
dev-only files. LOCAL ONLY (hardcoded paths).

./tools/sync-to-fralenuvole.sh --dry-run  # preview without writing (also -n)
./tools/sync-to-fralenuvole.sh            # sync only, then review with git status
./tools/sync-to-fralenuvole.sh --push     # sync + auto commit + push to fralenuvole.art repo
