# Doniixify CLI Workers

Standalone PHP scripts for background tasks. Run manually or via cron.

## Cron schedule (suggested)

Add to your crontab (`crontab -e`):

```cron
# Smart-rules auto-refresh (daily at 4am)
0 4 * * * cd /path/to/doniixify-php && php bin/refresh-rules.php >> storage/logs/cron.log 2>&1

# Collaborative-filtering similarity matrix rebuild (daily at 5am)
0 5 * * * cd /path/to/doniixify-php && php bin/build-recommendations.php >> storage/logs/cron.log 2>&1

# Background download queue worker (every minute, single-flight)
* * * * * cd /path/to/doniixify-php && flock -n storage/locks/dl.lock php bin/download-worker.php >> storage/logs/cron.log 2>&1

# Import worker (every minute, single-flight)
* * * * * cd /path/to/doniixify-php && flock -n storage/locks/import.lock php bin/import-worker.php >> storage/logs/cron.log 2>&1

# Process job queue (every minute, single-flight)
* * * * * cd /path/to/doniixify-php && flock -n storage/locks/queue.lock php bin/process-queue.php >> storage/logs/cron.log 2>&1

# Cleanup cache (weekly, Sunday 3am)
0 3 * * 0 cd /path/to/doniixify-php && php bin/cleanup-cache.php >> storage/logs/cron.log 2>&1
```

## Scripts

| Script | What it does | Suggested schedule |
|--------|--------------|---------------------|
| `refresh-rules.php` | Re-generates smart playlists from saved rules whose `last_run < refresh_days` ago | Daily 4am |
| `build-recommendations.php` | Builds item-based CF similarity matrix from `user_song_plays` | Daily 5am (after listening data settles) |
| `download-worker.php` | Processes Spotify/YouTube download queue | Every minute (locked) |
| `import-worker.php` | Imports new tracks from `storage/music/uploads` | Every minute (locked) |
| `process-queue.php` | Generic job queue processor | Every minute (locked) |
| `cleanup-cache.php` | Prunes old cache entries (lyrics negative, artist-bio, playlist-covers) | Weekly Sunday |

## Environment variables

Used by `build-recommendations.php`:
- `REC_MAX_PAIRS` (default 50) — max similar songs stored per song
- `REC_MIN_CO_LISTENS` (default 2) — minimum co-listens to register similarity

## Manual run examples

```bash
# Force-refresh all smart rules now
php bin/refresh-rules.php

# Rebuild recommendation matrix with looser threshold
REC_MIN_CO_LISTENS=1 php bin/build-recommendations.php

# Process pending downloads (single-flight via flock)
flock -n storage/locks/dl.lock php bin/download-worker.php
```

## Logs

All scripts append to `storage/logs/cron.log` (suggested redirect above). Individual scripts also write to their own logs:
- `refresh-rules.php` → `storage/logs/cron-rules.log`
- `build-recommendations.php` → `storage/logs/recommendations.log`
