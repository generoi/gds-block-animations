/**
 * GDS Block Animations — settings page (Claude CSS helper).
 */
(function () {
  'use strict';

  function getConfig() {
    return typeof window.gdsBlockAnimationsAdmin === 'object' &&
      window.gdsBlockAnimationsAdmin !== null
      ? window.gdsBlockAnimationsAdmin
      : {};
  }

  function setStatus(el, message, isError) {
    if (!el) {
      return;
    }
    el.textContent = message || '';
    el.style.color = isError ? '#b32d2e' : '#50575e';
  }

  function init() {
    var cfg = getConfig();
    var btn = document.getElementById('gds-ba-claude-generate');
    var promptEl = document.getElementById('gds-ba-claude-prompt');
    var modeEl = document.getElementById('gds-ba-claude-mode');
    var cssEl = document.getElementById('gds_block_animations_global_css');
    var statusEl = document.getElementById('gds-ba-claude-status');

    if (!btn || !promptEl || !cssEl || !cfg.ajaxUrl || !cfg.nonce) {
      return;
    }

    btn.addEventListener('click', function () {
      var prompt = (promptEl.value || '').trim();
      if (!prompt) {
        setStatus(statusEl, cfg.strings.emptyPrompt || 'Enter a prompt first.', true);
        return;
      }

      var modeRaw = modeEl ? modeEl.value : 'replace';
      var mode =
        modeRaw === 'append'
          ? 'append'
          : modeRaw === 'modify'
            ? 'modify'
            : 'replace';

      setStatus(statusEl, cfg.strings.working || 'Generating…', false);
      btn.disabled = true;

      var body = new window.FormData();
      body.append('action', 'gds_ba_claude_generate_css');
      body.append('nonce', cfg.nonce);
      body.append('prompt', prompt);
      body.append('mode', mode);
      body.append('current_css', cssEl.value || '');

      window
        .fetch(cfg.ajaxUrl, {
          method: 'POST',
          credentials: 'same-origin',
          body: body,
        })
        .then(function (r) {
          return r.json();
        })
        .then(function (data) {
          if (!data || !data.success) {
            setStatus(
              statusEl,
              (data && data.data && data.data.message) ||
                cfg.strings.failed ||
                'Request failed.',
              true,
            );
            return;
          }
          var css = data.data && data.data.css ? data.data.css : '';
          cssEl.value = css;
          cssEl.dispatchEvent(new Event('input', { bubbles: true }));
          setStatus(statusEl, cfg.strings.done || 'CSS inserted. Review and save settings.', false);
        })
        .catch(function () {
          setStatus(statusEl, cfg.strings.network || 'Network error.', true);
        })
        .finally(function () {
          btn.disabled = false;
        });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
