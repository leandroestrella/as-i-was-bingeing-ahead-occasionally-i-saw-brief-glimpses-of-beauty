(function () {
  'use strict';

  const POLL_INTERVAL = 30_000;        // ms between sync checks
  const SYNC_THRESHOLD = 2;            // seconds of drift before re-seek
  const YOUTUBE_API_READY_TIMEOUT = 10_000; // ms to wait for YouTube API

  let player;
  let currentVideoId = null;
  let isGateOpen = false;

  // ============================================================================
  // PHASE 1: Load YouTube IFrame API
  // ============================================================================

  function injectYouTubeAPI() {
    return new Promise((resolve) => {
      const tag = document.createElement('script');
      tag.src = 'https://www.youtube.com/iframe_api';
      tag.async = true;

      tag.onload = () => {
        // Wait for global onYouTubeIframeAPIReady to be called
        const waitForReady = setInterval(() => {
          if (window.YT && window.YT.Player) {
            clearInterval(waitForReady);
            resolve();
          }
        }, 100);

        // Timeout in case YouTube takes too long
        setTimeout(() => {
          clearInterval(waitForReady);
          resolve();
        }, YOUTUBE_API_READY_TIMEOUT);
      };

      document.body.appendChild(tag);
    });
  }

  window.onYouTubeIframeAPIReady = function () {
    // Called by YouTube API when ready
  };

  // ============================================================================
  // PHASE 2: Initialize YouTube Player
  // ============================================================================

  function initPlayer() {
    player = new YT.Player('player', {
      playerVars: {
        autoplay: 1,
        controls: 0,
        modestbranding: 1,
        playsinline: 1,
        iv_load_policy: 3,  // Hide annotations
        disablekb: 1,       // Disable keyboard
        rel: 0,             // No related videos
        fs: 0               // No fullscreen button
      },
      events: {
        onReady: onPlayerReady,
        onStateChange: onStateChange,
        onError: onPlayerError
      }
    });
  }

  function onPlayerReady() {
    // Open the gate for desktop (no gesture required)
    // Mobile will show tap-to-start overlay
    if (shouldShowGate()) {
      // Gate will remain visible, waiting for tap
    } else {
      openGate();
    }

    // Start polling for stream state updates
    fetchState();
    setInterval(fetchState, POLL_INTERVAL);
  }

  function shouldShowGate() {
    // Show gate on all platforms (safer for YouTube autoplay policies)
    return true;
  }

  function openGate() {
    const gate = document.getElementById('gate');
    if (gate && !isGateOpen) {
      gate.classList.add('hidden');
      isGateOpen = true;
      player.playVideo();
    }
  }

  // ============================================================================
  // PHASE 3: Poll stream state
  // ============================================================================

  function fetchState() {
    fetch('api/stream.php')
      .then((response) => {
        if (!response.ok) throw new Error('Stream fetch failed');
        return response.json();
      })
      .then((state) => {
        syncToState(state);
      })
      .catch((error) => {
        console.error('[stream.php]', error);
      });
  }

  // ============================================================================
  // PHASE 4: Sync player to stream state
  // ============================================================================

  function syncToState(state) {
    if (!state || !state.videoId) return;

    // Compute current playback offset based on server-side clock
    const offset = (Date.now() - state.startedAt) / 1000;

    // If we're past the slot duration, wait for next poll (server will advance)
    if (offset >= state.slotDuration) {
      return;
    }

    // If this is a different video, load it
    const currentId = player.getVideoData?.call(player)?.video_id;
    if (currentId !== state.videoId) {
      currentVideoId = state.videoId;
      player.loadVideoById({
        videoId: state.videoId,
        startSeconds: Math.floor(offset)
      });
    } else {
      // Same video — check for drift and re-sync if needed
      const playerTime = player.getCurrentTime?.call(player) || 0;
      if (Math.abs(playerTime - offset) > SYNC_THRESHOLD) {
        player.seekTo(offset, true);
      }
    }
  }

  // ============================================================================
  // PHASE 5: Player state change handler
  // ============================================================================

  function onStateChange(event) {
    const playerState = event.data;

    // Never allow paused state — force resume
    if (playerState === YT.PlayerState.PAUSED) {
      player.playVideo();
    }

    // If video ended, wait for next poll to advance
    if (playerState === YT.PlayerState.ENDED) {
      // Do nothing; server will advance on next interval
    }

    // If error, immediately fetch new stream state
    if (playerState === YT.PlayerState.UNSTARTED) {
      // Common for errors
    }
  }

  function onPlayerError(event) {
    // Error codes: 2 (invalid param), 5 (HTML5 error), 100 (not found),
    // 101 (not allowed to embed), 150 (same as 101)
    console.error('[player error]', event.data);

    // Unembeddable or not available — skip to next video
    skipToNextVideo();
  }

  function skipToNextVideo() {
    fetch('api/stream.php?skip=1', { method: 'GET' })
      .then((response) => response.json())
      .then((state) => {
        syncToState(state);
      })
      .catch((error) => {
        console.error('[skip]', error);
      });
  }

  // ============================================================================
  // PHASE 6: UI Interactions
  // ============================================================================

  function setupUI() {
    // Gate: tap to start
    const gate = document.getElementById('gate');
    if (gate) {
      gate.addEventListener('click', openGate);
    }

    // Info panel toggle
    const infoBtn = document.getElementById('info');
    const infoPanel = document.getElementById('info-panel');
    if (infoBtn && infoPanel) {
      infoBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        infoPanel.classList.toggle('show');
      });
      infoPanel.addEventListener('click', (e) => {
        if (e.target === infoPanel) {
          infoPanel.classList.remove('show');
        }
      });
    }

    // Fullscreen toggle
    const expandBtn = document.getElementById('expand');
    if (expandBtn) {
      expandBtn.addEventListener('click', () => {
        if (!document.fullscreenElement) {
          document.documentElement.requestFullscreen?.().catch(() => {
            // Fullscreen not available or denied
          });
        } else {
          document.exitFullscreen?.();
        }
      });
    }

    // Reload
    const reloadBtn = document.getElementById('reload');
    if (reloadBtn) {
      reloadBtn.addEventListener('click', () => {
        location.reload();
      });
    }

    // Glitch animation data attribute (for pseudo-elements)
    const glitch = document.querySelector('.glitch');
    if (glitch) {
      glitch.setAttribute('data-text', glitch.textContent);
    }
  }

  // ============================================================================
  // PHASE 7: Bootstrap
  // ============================================================================

  async function bootstrap() {
    try {
      setupUI();
      await injectYouTubeAPI();
      initPlayer();
    } catch (error) {
      console.error('[bootstrap]', error);
    }
  }

  // Start when DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootstrap);
  } else {
    bootstrap();
  }
})();
