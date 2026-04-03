# as i was bingeing ahead occasionally i saw brief glimpses of beauty

A synchronized global video stream artwork inspired by Jonas Mekas' 5-hour home-movie film. An endless sequence of random YouTube videos plays in sequence — like channel-zapping — across all devices worldwide. All visitors see the exact same video at the exact same playback position.

## Architecture

- **Hybrid video pool** — Fetches from Invidious API (primary) with YouTube playlist RSS fallback
- **PHP state manager** — Fixed-slot playback (5 min default) synchronized via server-side timestamps
- **Vanilla JS frontend** — YouTube IFrame API player with drift correction (±2 seconds)
- **House style** — Black background, Roboto font, glitch animation signature (matching stime.leandroestrella.com and assistedselfportrait.leandroestrella.com)
- **Deployment** — GitHub Actions pushes to cPanel via FTP

## Sync Mechanism

All clients compute the same playback offset using a simple formula:

```
offset = (Date.now() - startedAt) / 1000
player.seekTo(offset, true)
```

`startedAt` is a UTC Unix millisecond timestamp written by the server. No WebSockets, Firebase, or external dependencies needed.

## Project Structure

```
bingeing-ahead/
├── .github/
│   └── workflows/
│       └── deploy.yml              # GitHub Actions: push master → FTP deploy public/
├── .gitignore
├── README.md
└── public/
    ├── index.html                  # Minimal shell with iOS gate
    ├── css/
    │   └── style.css               # House style
    ├── js/
    │   └── app.js                  # YouTube IFrame + stream polling
    └── api/
        ├── .htaccess               # Protect .json from direct access
        ├── stream.php              # Stream state manager
        └── pool.php                # Video pool fetcher (Invidious + RSS)
```

Runtime files (created by PHP, gitignored):
- `public/api/state.json` — current stream state
- `public/api/pool-cache.json` — cached video pool

## Deployment

### 1. Set up cPanel FTP credentials

Add GitHub repository secrets:
- `FTP_SERVER` — your cPanel FTP server (e.g., `ftp.example.com`)
- `FTP_USERNAME` — cPanel FTP username
- `FTP_PASSWORD` — cPanel FTP password

### 2. Push to master branch

```bash
git push origin master
```

GitHub Actions will automatically deploy the `public/` directory to your cPanel server via FTP.

### 3. Verify deployment

- Visit your deployed URL in a browser
- Check `api/stream.php` directly — should return a JSON object with `videoId`, `startedAt`, and `slotDuration`
- Open in two tabs — both should sync to the same video/offset

## Configuration

### Video slot duration

Edit `public/api/stream.php`, line ~11:

```php
define('SLOT_DURATION', 300);  // seconds (5 minutes)
```

### Search queries (for Invidious)

Edit `public/api/pool.php`, lines ~18–34, the `$SEARCH_QUERIES` array. The pool fetcher randomly picks one query per refresh.

### Fallback playlist IDs

Edit `public/api/pool.php`, lines ~36–40, the `$PLAYLIST_IDS` array. Get playlist IDs from YouTube URLs:
```
https://www.youtube.com/watch?v=VIDEO&list=PLAYLIST_ID
                                            ^^^^^^^^^^^^ copy this
```

## How It Works

### On first load

1. Browser loads `index.html`, injects YouTube IFrame API, shows tap-to-start gate
2. Client calls `api/stream.php`, gets current state or bootstraps if empty
3. `stream.php` reads `state.json` (or creates it if missing)
4. `pool.php` fetches ~30 video IDs from Invidious or YouTube RSS
5. Client loads first video via YouTube IFrame Player API

### Every 30 seconds

1. Client polls `api/stream.php`
2. `stream.php` checks: has the current slot expired?
   - If no: return current state
   - If yes: pick a random video from pool, write new state, return it
3. Client syncs to current video/offset via `player.seekTo(offset, true)`

### On video error

If YouTube reports error 101/150 (not embeddable), client calls `stream.php?skip=1` to force advance to next video.

## Technical Notes

### Clock Skew

UTC Unix timestamps are used. Typical clock skew between devices is < 1 second. For an art piece, this is acceptable. No NTP correction needed.

### Single-instance server

The PHP state file is write-locked to prevent race conditions. If you deploy to a multi-instance load balancer, only one instance should run `stream.php`. For cPanel shared hosting, this is not a concern.

### YouTube API quotas

This project uses **zero** YouTube Data API quotas at runtime:
- Invidious API is free and unrestricted
- YouTube RSS feeds are free
- YouTube IFrame Player API is free

Video selection is dynamic and requires no API calls once the pool is fetched.

### iOS autoplay

iOS Safari requires a user gesture to start autoplay. A full-screen tap-to-start gate is shown on all platforms for simplicity and policy compliance.

## Troubleshooting

### `stream.php` returns error

Check that `public/api/` is writable by the web server (Apache/LiteSpeed on cPanel). PHP needs write permission to create `state.json` and `pool-cache.json`.

**cPanel solution:** Use File Manager → Set permissions to 755 for directories, 644 for files. Or contact hosting support.

### Videos not loading / only fallback videos show

Invidious instances may be down or rate-limiting. Check if the instances in `pool.php` are accessible. The fallback hardcoded pool will be used automatically.

### Videos not syncing across devices

Check browser console for errors. Verify `api/stream.php` returns JSON when visited directly. Ensure `php.ini` `allow_url_fopen` is enabled (needed for `fopen()` in `pool.php`).

### YouTube IFrame shows controls

Edit `public/js/app.js`, `playerVars`: set `controls: 0` to hide controls. Default is already hidden.

## License

This work is an artwork inspired by Jonas Mekas' "As I Was Moving Ahead Occasionally I Saw Brief Glimpses of Beauty" (1983).

## Contact

Created by Leandro Estrella.
