/**
 * GDS Block Animations Plugin JavaScript
 * Scroll animations only when scrolling down; scrolling up reveals without motion.
 */

(function () {
  'use strict';

  let sharedObserver = null;

  let lastScrollY =
    window.scrollY ||
    window.pageYOffset ||
    document.documentElement.scrollTop ||
    0;
  /** @type {'down'|'up'} */
  let scrollDirection = 'down';

  function getConfig() {
    if (
      typeof window.gdsBlockAnimationsConfig === 'object' &&
      window.gdsBlockAnimationsConfig !== null
    ) {
      return window.gdsBlockAnimationsConfig;
    }
    return { skipAboveFold: false };
  }

  function updateScrollDirectionFromScroll() {
    const y =
      window.scrollY ||
      window.pageYOffset ||
      document.documentElement.scrollTop ||
      0;
    const delta = y - lastScrollY;
    if (delta > 2) {
      scrollDirection = 'down';
    } else if (delta < -2) {
      scrollDirection = 'up';
    }
    lastScrollY = y;
  }

  /**
   * @param {Element} el
   * @param {{ instant?: boolean }} options instant = suppress CSS transitions for this reveal
   */
  function markBlockVisible(el, options) {
    const instant = options && options.instant;
    if (
      !el ||
      !el.classList ||
      el.classList.contains('gds-animate-visible')
    ) {
      return;
    }

    if (instant) {
      el.classList.add('gds-block-animations-instant');
    }
    el.classList.add('gds-animate-visible', 'gds-block-animations-visible');
    if (sharedObserver) {
      sharedObserver.unobserve(el);
    }
    if (instant) {
      window.requestAnimationFrame(function () {
        window.requestAnimationFrame(function () {
          el.classList.remove('gds-block-animations-instant');
        });
      });
    }

    // Notify subscribers — themes can hook this to run extra animations
    // (e.g. number count-up, SVG draw) when a block enters view. Bubbles
    // so a single document-level listener can catch any block.
    try {
      el.dispatchEvent(
        new CustomEvent('gds-block-animations:visible', {
          bubbles: true,
          detail: { instant: !!instant, element: el },
        }),
      );
    } catch (e) {
      // Older browsers without CustomEvent constructor — silently skip.
    }
  }

  /**
   * Remove animation class and exclude blocks that intersect the initial viewport.
   *
   * @param {Element[]} blocks
   * @returns {Element[]}
   */
  function stripAboveFoldBlocks(blocks) {
    if (!getConfig().skipAboveFold || !blocks || blocks.length === 0) {
      return blocks;
    }

    const foldY =
      window.innerHeight || document.documentElement.clientHeight || 0;

    return blocks.filter(function (el) {
      if (
        !el ||
        !el.classList ||
        !el.classList.contains('gds-block-animations-block')
      ) {
        return false;
      }

      const rect = el.getBoundingClientRect();
      const intersectsInitialViewport =
        rect.width > 0 &&
        rect.height > 0 &&
        rect.top < foldY &&
        rect.bottom > 0;

      if (intersectsInitialViewport) {
        el.classList.remove('gds-block-animations-block');
        /* Instant: no entrance animation for already-visible / above-fold content */
        markBlockVisible(el, { instant: true });
        return false;
      }

      return true;
    });
  }

  // Wait for DOM to be ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  function getSharedObserver() {
    if (sharedObserver) {
      return sharedObserver;
    }

    const options = {
      root: null,
      /* Large negative bottom margin was clipping the last ~100px of the viewport, so footers
       * and nested groups at the document bottom often never received isIntersecting. */
      rootMargin: '0px 0px -24px 0px',
      threshold: 0,
    };

    const callback = function (entries, observer) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          markBlockVisible(entry.target, {
            instant: scrollDirection === 'up',
          });
          console.log('GDS Block Animations: Block visible', entry.target);
        }
      });
      sweepPendingBlocksInViewport();
    };

    sharedObserver = new IntersectionObserver(callback, options);
    return sharedObserver;
  }

  function observeBlocks(blocks) {
    if (!blocks || blocks.length === 0) {
      return;
    }
    const observer = getSharedObserver();
    blocks.forEach(function (block) {
      observer.observe(block);
    });
  }

  /**
   * Mark animated blocks already intersecting the viewport (fonts/images may shift layout after DOMContentLoaded).
   *
   * @param {Element} block
   * @returns {boolean} True if visible class was applied.
   */
  function revealBlockIfInViewport(block) {
    if (
      !block ||
      !block.classList ||
      !block.classList.contains('gds-block-animations-block') ||
      block.classList.contains('gds-animate-visible')
    ) {
      return false;
    }

    const rect = block.getBoundingClientRect();
    const vh = window.innerHeight || document.documentElement.clientHeight || 0;
    const isInViewport =
      rect.width > 0 &&
      rect.height > 0 &&
      rect.top < vh &&
      rect.bottom > 0;

    if (isInViewport) {
      markBlockVisible(block, { instant: scrollDirection === 'up' });
      sweepPendingBlocksInViewport();
      return true;
    }

    return false;
  }

  /**
   * Re-check any pending blocks (layout / late paint).
   */
  function sweepPendingBlocksInViewport() {
    const pending = document.querySelectorAll(
      '.gds-block-animations-block:not(.gds-animate-visible)',
    );
    Array.prototype.forEach.call(pending, function (el) {
      revealBlockIfInViewport(el);
    });
  }

  /**
   * Observe elements that already have .gds-block-animations-block (e.g. added at runtime).
   *
   * @param {Element[]|NodeList} elements
   */
  window.gdsBlockAnimationsObserveBlocks = function (elements) {
    const list = Array.isArray(elements)
      ? elements
      : Array.prototype.slice.call(elements || []);
    const filtered = list.filter(function (el) {
      return (
        el &&
        el.nodeType === 1 &&
        el.classList.contains('gds-block-animations-block')
      );
    });
    const afterFold = stripAboveFoldBlocks(filtered);
    if (afterFold.length === 0) {
      return;
    }
    observeBlocks(afterFold);
    triggerVisibleBlocks(afterFold);
  };

  function init() {
    console.log('GDS Block Animations Plugin Loaded! 🎨');

    lastScrollY =
      window.scrollY ||
      window.pageYOffset ||
      document.documentElement.scrollTop ||
      0;

    const blockSelector = '.gds-block-animations-block';
    const blocksToAnimate = document.querySelectorAll(blockSelector);

    if (blocksToAnimate.length === 0) {
      console.log('GDS Block Animations: No blocks found on page');
      return;
    }

    let list = Array.from(blocksToAnimate);
    list = stripAboveFoldBlocks(list);
    sweepPendingBlocksInViewport();

    if (list.length === 0) {
      console.log(
        'GDS Block Animations: No blocks left after above-the-fold skip',
      );
      return;
    }

    console.log(
      'GDS Block Animations: Found ' +
        list.length +
        ' block(s) to animate',
    );

    observeBlocks(list);
    triggerVisibleBlocks(list);

    window.addEventListener('load', sweepPendingBlocksInViewport, {
      once: true,
    });

    let resizeTimer;
    window.addEventListener('resize', function () {
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(sweepPendingBlocksInViewport, 200);
    });

    let scrollTimer;
    window.addEventListener(
      'scroll',
      function () {
        updateScrollDirectionFromScroll();
        clearTimeout(scrollTimer);
        scrollTimer = setTimeout(sweepPendingBlocksInViewport, 120);
      },
      { passive: true },
    );
  }

  /**
   * Trigger animation for blocks already in viewport on page load
   */
  function triggerVisibleBlocks(blocks) {
    blocks.forEach(function (block) {
      setTimeout(function () {
        if (revealBlockIfInViewport(block)) {
          console.log('GDS Block Animations: Block visible on load', block);
        }
      }, 50);
    });
  }

  /**
   * Subscribe to block-reveal events. Returns an unsubscribe function.
   *
   * Convenience over the raw `gds-block-animations:visible` event — the
   * helper handles selector matching, fires once per element, and runs
   * the callback for blocks that are already visible at registration
   * time (so late-loaded scripts don't miss above-the-fold reveals).
   *
   * @example
   *   gdsBlockAnimationsOnReveal('.my-counter', (el) => {
   *     animateNumber(el);
   *   });
   *
   * @param {string} selector CSS selector — only matching descendants fire.
   * @param {(el: Element) => void} callback Runs once per matched element.
   * @returns {() => void} Unsubscribe function.
   */
  window.gdsBlockAnimationsOnReveal = function (selector, callback) {
    if (typeof selector !== 'string' || typeof callback !== 'function') {
      return function noop() {};
    }

    const fired = new WeakSet();

    function handle(target) {
      if (!target || !target.querySelectorAll) {
        return;
      }
      const matches = target.matches && target.matches(selector) ? [target] : [];
      const inside = Array.from(target.querySelectorAll(selector));
      for (const el of matches.concat(inside)) {
        if (fired.has(el)) continue;
        fired.add(el);
        try {
          callback(el);
        } catch (e) {
          if (typeof console !== 'undefined') {
            console.error('gdsBlockAnimationsOnReveal callback error:', e);
          }
        }
      }
    }

    // Catch already-revealed blocks (e.g. above-the-fold + skip-above-fold).
    const alreadyVisible = document.querySelectorAll('.gds-animate-visible');
    alreadyVisible.forEach(handle);

    function listener(event) {
      handle(event.target);
    }

    document.addEventListener('gds-block-animations:visible', listener);

    return function unsubscribe() {
      document.removeEventListener('gds-block-animations:visible', listener);
    };
  };

  // Expose a global function for debugging
  window.gdsBlockAnimations = function () {
    console.log(
      '%c GDS Block Animations Plugin ',
      'background: #667eea; color: white; font-size: 16px; padding: 8px;',
    );

    const allBlocks =
      document.querySelectorAll('.gds-block-animations-block');
    const visibleBlocks = document.querySelectorAll(
      '.gds-block-animations-visible, .gds-animate-visible',
    );

    console.log('Total blocks with animation:', allBlocks.length);
    console.log('Visible blocks:', visibleBlocks.length);
    console.log('Pending blocks:', allBlocks.length - visibleBlocks.length);
    console.log('Last scroll direction:', scrollDirection);

    if (allBlocks.length > 0) {
      console.log('Block types found:');
      const blockTypes = new Set();
      allBlocks.forEach(function (block) {
        // Try to identify block type from classes
        const classes = Array.from(block.classList);
        const wpBlock = classes.find((c) => c.startsWith('wp-block-'));
        if (wpBlock) {
          blockTypes.add(wpBlock);
        }
      });
      blockTypes.forEach((type) => console.log('  -', type));
    }

    return '✨ GDS Block Animations is active!';
  };
})();
