/**
 * Bingeing Ahead — Client-side stream synchronization
 *
 * All visitors worldwide see the same video at the same playback position.
 * The server (stream.php) provides a shared state { videoId, startedAt, slotDuration }.
 * This script polls that state and computes the playback offset locally:
 *
 *   offset = (Date.now() - startedAt) / 1000
 *
 * No WebSockets or Firebase — just periodic polling + math.
 */
(function () {
  'use strict';

  // --- Configuration ---------------------------------------------------------
  // These values are trade-offs between responsiveness and server load.

  const POLL_INTERVAL = 30_000;  // How often to check the server (ms).
                                 // 30s keeps load low while catching slot changes quickly.

  const SYNC_THRESHOLD = 2;      // Maximum acceptable drift (seconds) before re-seeking.
                                 // YouTube's player has ~1-2s natural jitter, so seeking
                                 // for less than 2s causes more jank than it fixes.

  const API_TIMEOUT = 5_000;     // Abort fetch if server doesn't respond within 5s.

  let player;

  // ===========================================================================
  // YouTube API injection
  // ===========================================================================

  /**
   * Load the YouTube IFrame API and wait for it to be ready.
   *
   * YouTube provides a callback (window.onYouTubeIframeAPIReady) but it's
   * unreliable when the script is injected dynamically. Polling for
   * window.YT.Player at 100ms intervals is more robust.
   */
  function injectYouTubeAPI() {
    return new Promise((resolve) => {
      if (window.YT && window.YT.Player) {
        resolve();
        return;
      }

      const tag = document.createElement('script');
      tag.src = 'https://www.youtube.com/iframe_api';
      tag.async = true;
      document.body.appendChild(tag);

      const checkReady = () => {
        if (window.YT && window.YT.Player) {
          resolve();
        } else {
          setTimeout(checkReady, 100);
        }
      };
      checkReady();
    });
  }

  // ===========================================================================
  // Player initialization
  // ===========================================================================

  /**
   * Create the YouTube IFrame player with all UI stripped.
   * playerVars reference: https://developers.google.com/youtube/player_api#Parameters
   */
  function initPlayer() {
    player = new YT.Player('player', {
      playerVars: {
        autoplay: 1,
        controls: 0,           // hide play/pause bar
        modestbranding: 1,     // minimize YouTube logo
        showinfo: 0,           // hide video title overlay
        playsinline: 1,        // iOS: play inline instead of fullscreen
        iv_load_policy: 3,     // hide video annotations
        disablekb: 1,          // disable keyboard shortcuts (space = pause, etc.)
        rel: 0,                // don't show related videos when video ends
        fs: 0,                 // hide fullscreen button
        cc_load_policy: 0,     // don't auto-show closed captions
        origin: location.origin
      },
      events: {
        onReady: onPlayerReady,
        onStateChange: onStateChange,
        onError: onPlayerError
      }
    });
  }

  function onPlayerReady() {
    fetchState();
    setInterval(fetchState, POLL_INTERVAL);
  }

  // ===========================================================================
  // Stream synchronization
  // ===========================================================================

  /**
   * Fetch a stream endpoint, parse JSON, sync player, and update the UI.
   * Shared by both regular polling and skip requests.
   */
  function fetchAndSync(url, errorLabel) {
    fetch(url, { signal: AbortSignal.timeout(API_TIMEOUT) })
      .then((response) => {
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        return response.json();
      })
      .then((state) => {
        syncToState(state);
        updateStreamStateDisplay(state);
        warnIfFallback(state);
      })
      .catch((error) => {
        console.warn(`[${errorLabel}]`, error.message);
      });
  }

  /** Poll the server for the current stream state. */
  function fetchState() {
    fetchAndSync('api/stream.php', 'stream sync error');
  }

  /**
   * Align the player with the server state.
   *
   * Three outcomes:
   *   1. Slot expired → do nothing, server will advance on next poll
   *   2. New video    → load it at the computed offset
   *   3. Same video   → check drift, re-seek only if > SYNC_THRESHOLD
   */
  function syncToState(state) {
    if (!state || typeof state.videoId !== 'string' || !state.startedAt) {
      return;
    }

    // This is the core sync formula — same math on every client
    const offset = (Date.now() - state.startedAt) / 1000;

    if (offset >= state.slotDuration) {
      return; // slot expired; server will pick a new video on next poll
    }

    // getVideoData() can be undefined while the player is still initializing
    const currentVideoId = player.getVideoData?.()?.video_id;

    if (!currentVideoId || currentVideoId !== state.videoId) {
      // New video — load it, starting at the current offset so we're in sync
      // floor() avoids seeking past a frame boundary on short videos
      player.loadVideoById({
        videoId: state.videoId,
        startSeconds: Math.floor(offset)
      });
    } else {
      // Same video — correct drift if it's grown too large
      const playerTime = player.getCurrentTime();
      const drift = Math.abs(playerTime - offset);

      if (drift > SYNC_THRESHOLD) {
        // true = allow seeking ahead (buffers new data if needed)
        player.seekTo(offset, true);
      }
    }
  }

  // ===========================================================================
  // Player state handling
  // ===========================================================================

  function onStateChange(event) {
    const state = event.data;

    // Never allow pausing — this is a continuous stream
    if (state === YT.PlayerState.PAUSED) {
      player.playVideo();
    }

    // If the video is shorter than its slot, skip immediately
    // rather than sitting on a black screen until the next poll
    if (state === YT.PlayerState.ENDED) {
      skipToNextVideo();
    }
  }

  /**
   * Handle YouTube player errors.
   * Common codes: 101/150 = not embeddable, 100 = deleted, 5 = HTML5 error.
   * In all cases, tell the server to advance to the next video.
   */
  function onPlayerError(event) {
    console.warn('[player error]', event.data);
    skipToNextVideo();
  }

  /** Ask the server to advance to the next video via ?skip=1. */
  function skipToNextVideo() {
    fetchAndSync('api/stream.php?skip=1', 'skip error');
  }

  // ===========================================================================
  // Stream state display
  // ===========================================================================

  /** Show the current stream state in the info panel as a diary-style note. */
  function updateStreamStateDisplay(state) {
    const el = document.getElementById('stream-state');
    if (!el || !state) return;

    const elapsed = Math.floor((Date.now() - state.startedAt) / 1000);
    const link = `https://youtu.be/${state.videoId}`;

    el.innerHTML =
      `currently watching <a href="${link}" target="_blank" rel="noopener">${state.videoId}</a><br>`
      + `this fragment lasts ${state.slotDuration} seconds.<br>`
      + `${elapsed} seconds have passed.`;
  }

  // ===========================================================================
  // Pool source warning
  // ===========================================================================

  let fallbackWarned = false;

  /** Log a console warning once when the pool is serving from the hardcoded fallback. */
  function warnIfFallback(state) {
    if (fallbackWarned || !state || !state.poolSource) return;
    if (state.poolSource === 'fallback') {
      console.warn(
        '[bingeing-ahead] Pool is serving from hardcoded fallback. '
        + 'All external sources failed (YouTube API, Piped, RSS). '
        + 'Check your YOUTUBE_API_KEY in .env and verify Piped instance availability.'
      );
      fallbackWarned = true;
    }
  }

  // ===========================================================================
  // UI setup
  // ===========================================================================

  /** Wire up the four corner UI elements and the info panel overlay. */
  function setupUI() {
    // Info panel toggle (top-right ℹ icon)
    const infoBtn = document.getElementById('info');
    const infoPanel = document.getElementById('info-panel');
    if (infoBtn && infoPanel) {
      infoBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        infoPanel.classList.toggle('show');
      });
      // Clicking the dark overlay (but not the text) closes the panel
      infoPanel.addEventListener('click', (e) => {
        if (e.target === infoPanel) {
          infoPanel.classList.remove('show');
        }
      });
    }

    // Fullscreen toggle (bottom-right ⬉ icon)
    const expandBtn = document.getElementById('expand');
    if (expandBtn) {
      expandBtn.addEventListener('click', () => {
        if (!document.fullscreenElement) {
          document.documentElement.requestFullscreen?.().catch(() => {});
        } else {
          document.exitFullscreen?.();
        }
      });
    }

    // Page reload (top-left ↺ icon)
    const reloadBtn = document.getElementById('reload');
    if (reloadBtn) {
      reloadBtn.addEventListener('click', () => {
        location.reload();
      });
    }

    // Glitch effect on "le" signature is CSS-only (hover-triggered pseudo-elements)
  }

  // ===========================================================================
  // Bootstrap
  // ===========================================================================

  async function bootstrap() {
    try {
      setupUI();
      await injectYouTubeAPI();
      initPlayer();
    } catch (error) {
      console.error('[bootstrap]', error);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootstrap);
  } else {
    bootstrap();
  }
})();
