/**
 * GDS Block Animations Plugin JavaScript
 * Block animations on scroll - no build process required
 */

(function () {
  'use strict';

  // Wait for DOM to be ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  function init() {
    console.log('GDS Block Animations Plugin Loaded! 🎨');

    // Find all blocks with animation classes (added by PHP)
    const blockSelector = '.gds-block-animations-block';
    const blocksToAnimate = document.querySelectorAll(blockSelector);

    if (blocksToAnimate.length === 0) {
      console.log('GDS Block Animations: No blocks found on page');
      return;
    }

    console.log(
      'GDS Block Animations: Found ' +
        blocksToAnimate.length +
        ' block(s) to animate',
    );

    // Set up Intersection Observer
    setupIntersectionObserver(Array.from(blocksToAnimate));

    // Manually trigger animation for blocks already in viewport
    triggerVisibleBlocks(Array.from(blocksToAnimate));
  }

  /**
   * Set up Intersection Observer to detect when blocks enter viewport
   */
  function setupIntersectionObserver(blocks) {
    // Options for the observer
    const options = {
      root: null, // viewport
      rootMargin: '0px 0px -100px 0px', // trigger slightly before block is fully visible
      threshold: 0.1, // trigger when 10% of block is visible
    };

    // Callback function when block intersects
    const callback = function (entries, observer) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          // Add visible class - theme CSS handles the animation
          entry.target.classList.add(
            'gds-animate-visible',
            'gds-block-animations-visible',
          );

          // Optional: Stop observing after animation (remove if you want repeated animations)
          observer.unobserve(entry.target);

          console.log('GDS Block Animations: Block visible', entry.target);
        }
      });
    };

    // Create observer
    const observer = new IntersectionObserver(callback, options);

    // Observe each block
    blocks.forEach(function (block) {
      observer.observe(block);
    });
  }

  /**
   * Trigger animation for blocks already in viewport on page load
   */
  function triggerVisibleBlocks(blocks) {
    blocks.forEach(function (block) {
      const rect = block.getBoundingClientRect();
      const isInViewport = rect.top < window.innerHeight && rect.bottom > 0;

      if (isInViewport) {
        // Add visible class immediately for blocks in viewport on load
        setTimeout(function () {
          block.classList.add(
            'gds-animate-visible',
            'gds-block-animations-visible',
          );
          console.log('GDS Block Animations: Block visible on load', block);
        }, 50);
      }
    });
  }

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
