Ops scripts for the fralenuvole plugin.

- warmup.sh
Warms the WP page cache by crawling the live sitemap.
Runs anywhere (local or Kinsta SSH). Usage: ./warmup.sh
Logs (warmup.sh) are written to ../logs/, not here.

- backup-local.sh
Zips the plugin into a versioned backup archive.
LOCAL ONLY (hardcoded local mirror paths).

- deploy-remote.sh  Git-pulls latest main to production + PHP lint check.
KINSTA SSH ONLY (hardcoded server path). Supports
--dry-run and -y/--yes.


