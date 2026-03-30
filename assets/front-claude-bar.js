/**
 * GDS Block Animations — floating Claude bar (frontend, admins only).
 */
(function () {
  'use strict';

  var SESSION_KEY = 'gdsBaFrontClaudeConv_v1';
  var EXCERPT_LEN = 1200;
  var MAX_TURNS = 15;

  function cfg() {
    return typeof window.gdsBlockAnimationsFrontClaude === 'object' &&
      window.gdsBlockAnimationsFrontClaude !== null
      ? window.gdsBlockAnimationsFrontClaude
      : null;
  }

  function t(key, fallback) {
    var c = cfg();
    var s = c && c.strings ? c.strings : {};
    return s[key] || fallback || '';
  }

  function setStatus(statusEl, message, state) {
    if (!statusEl) {
      return;
    }
    var textEl = statusEl.querySelector('.gds-ba-front-claude-status__text');
    statusEl.classList.remove('is-idle', 'is-working', 'is-success', 'is-error');
    if (state) {
      statusEl.classList.add('is-' + state);
    }
    if (textEl) {
      textEl.textContent = message || '';
    }
  }

  function escapeHtml(s) {
    var d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }

  function escapeAttr(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/</g, '&lt;');
  }

  function excerptCss(css) {
    var txt = (css || '').trim();
    if (!txt) {
      return '';
    }
    if (txt.length <= EXCERPT_LEN) {
      return txt;
    }
    return txt.slice(0, EXCERPT_LEN) + '\n… (' + txt.length + ' characters)';
  }

  function loadConversationTurns() {
    try {
      var raw = window.sessionStorage.getItem(SESSION_KEY);
      if (!raw) {
        return [];
      }
      var parsed = JSON.parse(raw);
      if (!Array.isArray(parsed)) {
        return [];
      }
      var out = [];
      for (var i = 0; i < parsed.length; i++) {
        var row = parsed[i];
        if (!row || typeof row !== 'object') {
          continue;
        }
        var u = typeof row.user === 'string' ? row.user : '';
        var a = typeof row.assistant === 'string' ? row.assistant : '';
        if (u.trim() === '') {
          continue;
        }
        out.push({ user: u, assistant: a });
      }
      if (out.length > MAX_TURNS) {
        out = out.slice(-MAX_TURNS);
      }
      return out;
    } catch (e) {
      return [];
    }
  }

  function persistConversationTurns(turns) {
    try {
      var slice = turns.length > MAX_TURNS ? turns.slice(-MAX_TURNS) : turns;
      window.sessionStorage.setItem(SESSION_KEY, JSON.stringify(slice));
    } catch (e) {
      /* quota / private mode */
    }
  }

  function renderTranscript(transcriptEl, turns) {
    if (!transcriptEl) {
      return;
    }
    transcriptEl.innerHTML = '';
    if (!turns.length) {
      transcriptEl.setAttribute('data-empty', 'true');
      var emptyP = document.createElement('p');
      emptyP.className = 'gds-ba-front-claude-transcript__empty';
      emptyP.textContent = t(
        'transcriptEmpty',
        'No turns yet. Generate and apply to see your prompts and CSS excerpts here.',
      );
      transcriptEl.appendChild(emptyP);
      return;
    }
    transcriptEl.removeAttribute('data-empty');
    for (var i = 0; i < turns.length; i++) {
      var turn = turns[i];
      var exchange = document.createElement('section');
      exchange.className = 'gds-ba-front-claude-exchange';
      exchange.setAttribute('aria-label', t('exchangeHeading', 'Round') + ' ' + (i + 1));

      var head = document.createElement('header');
      head.className = 'gds-ba-front-claude-exchange__head';
      head.textContent = t('exchangeHeading', 'Round') + ' ' + (i + 1);
      exchange.appendChild(head);

      var userBlock = document.createElement('div');
      userBlock.className = 'gds-ba-front-claude-turn gds-ba-front-claude-turn--user';
      var userLbl = document.createElement('span');
      userLbl.className = 'gds-ba-front-claude-turn__label';
      userLbl.textContent = t('turnYou', 'You');
      var userP = document.createElement('p');
      userP.className = 'gds-ba-front-claude-turn__body';
      userP.textContent = turn.user;
      userBlock.appendChild(userLbl);
      userBlock.appendChild(userP);
      exchange.appendChild(userBlock);

      var asstBlock = document.createElement('div');
      asstBlock.className =
        'gds-ba-front-claude-turn gds-ba-front-claude-turn--assistant';
      var asstLbl = document.createElement('span');
      asstLbl.className = 'gds-ba-front-claude-turn__label';
      asstLbl.textContent = t('turnApplied', 'Applied CSS (excerpt)');
      var pre = document.createElement('pre');
      pre.className = 'gds-ba-front-claude-turn__code';
      pre.textContent = turn.assistant || '—';
      asstBlock.appendChild(asstLbl);
      asstBlock.appendChild(pre);
      exchange.appendChild(asstBlock);

      transcriptEl.appendChild(exchange);
    }
    transcriptEl.scrollTop = transcriptEl.scrollHeight;
  }

  function buildUi() {
    var c = cfg();
    if (!c || !c.ajaxUrl) {
      return null;
    }

    var wrap = document.createElement('div');
    wrap.className = 'gds-ba-front-claude';
    wrap.setAttribute('data-gds-ba-front-claude', '');

    var fab = document.createElement('button');
    fab.type = 'button';
    fab.className = 'gds-ba-front-claude-fab';
    fab.setAttribute('aria-expanded', 'false');
    fab.setAttribute('aria-label', t('toggleLabel', 'Animation CSS'));
    fab.innerHTML =
      '<svg class="gds-ba-front-claude-fab-svg" width="22" height="22" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M9.4 16.6L4.8 12l4.6-4.6L8 6l-6 6 6 6 1.4-1.4zm5.2 0l4.6-4.6-4.6-4.6L16 6l6 6-6 6-1.4-1.4z"/></svg>';

    var panel = document.createElement('div');
    panel.className = 'gds-ba-front-claude-panel';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-label', t('panelTitle', 'Claude'));

    var actionsHtml =
      '<div class="gds-ba-front-claude-actions">' +
      '<div class="gds-ba-front-claude-actions__row gds-ba-front-claude-actions__row--single">' +
      '<button type="button" class="gds-ba-front-claude-btn gds-ba-front-claude-btn--primary" id="gds-ba-front-claude-apply">' +
      '<span class="gds-ba-front-claude-btn__title">' +
      '<span class="gds-ba-front-claude-btn__title-inner">' +
      '<span class="gds-ba-front-claude-btn__spinner" aria-hidden="true"></span>' +
      '<span class="gds-ba-front-claude-btn__title-text">' +
      escapeHtml(t('generateApply', 'Generate and apply')) +
      '</span></span></span>' +
      '<span class="gds-ba-front-claude-btn__meta">' +
      escapeHtml(t('generateApplyMeta', '')) +
      '</span></button></div>';

    if (c.settingsUrl) {
      actionsHtml +=
        '<p class="gds-ba-front-claude-settings"><a href="' +
        escapeAttr(c.settingsUrl) +
        '">' +
        escapeHtml(t('openSettings', 'Settings')) +
        '</a></p>';
    }

    actionsHtml += '</div>';

    panel.innerHTML =
      '<h2>' +
      escapeHtml(t('panelTitle', 'Claude')) +
      '</h2>' +
      '<p class="gds-ba-front-claude-hint">' +
      escapeHtml(t('contextHint', '')) +
      '</p>' +
      '<div class="gds-ba-front-claude-transcript-head">' +
      '<span class="gds-ba-front-claude-transcript-title">' +
      escapeHtml(t('transcriptLabel', 'Conversation')) +
      '</span>' +
      '<button type="button" class="gds-ba-front-claude-clear-conv" id="gds-ba-front-claude-clear-conv">' +
      escapeHtml(t('clearConversation', 'Clear conversation')) +
      '</button></div>' +
      '<div class="gds-ba-front-claude-transcript" id="gds-ba-front-claude-transcript" tabindex="0" aria-label="' +
      escapeAttr(t('transcriptLabel', 'Conversation')) +
      '"></div>' +
      '<label class="screen-reader-text" for="gds-ba-front-claude-mode">' +
      escapeHtml(t('modeFieldLabel', 'Generation mode')) +
      '</label>' +
      '<select id="gds-ba-front-claude-mode" style="width:100%; margin-bottom:10px;"></select>' +
      '<label for="gds-ba-front-claude-prompt">' +
      escapeHtml(t('promptLabel', 'Prompt')) +
      '</label>' +
      '<textarea id="gds-ba-front-claude-prompt" class="gds-ba-front-claude-prompt" spellcheck="true"></textarea>' +
      '<label for="gds-ba-front-claude-result">' +
      escapeHtml(t('resultLabel', 'CSS')) +
      '</label>' +
      '<textarea id="gds-ba-front-claude-result" class="gds-ba-front-claude-result" spellcheck="false"></textarea>' +
      actionsHtml +
      '<div class="gds-ba-front-claude-status is-idle" id="gds-ba-front-claude-status" role="status" aria-live="polite">' +
      '<span class="gds-ba-front-claude-status__icon" aria-hidden="true"></span>' +
      '<p class="gds-ba-front-claude-status__text"></p>' +
      '</div>';

    var modeEl = panel.querySelector('#gds-ba-front-claude-mode');
    var optMod = document.createElement('option');
    optMod.value = 'modify';
    optMod.textContent = t('modeModify', 'Modify');
    var optRep = document.createElement('option');
    optRep.value = 'replace';
    optRep.textContent = t('modeReplace', 'Replace');
    var optApp = document.createElement('option');
    optApp.value = 'append';
    optApp.textContent = t('modeAppend', 'Append');
    modeEl.appendChild(optMod);
    modeEl.appendChild(optRep);
    modeEl.appendChild(optApp);

    wrap.appendChild(panel);
    wrap.appendChild(fab);
    document.body.appendChild(wrap);

    return {
      fab: fab,
      panel: panel,
      transcriptEl: panel.querySelector('#gds-ba-front-claude-transcript'),
      clearConvBtn: panel.querySelector('#gds-ba-front-claude-clear-conv'),
      modeEl: modeEl,
      promptEl: panel.querySelector('#gds-ba-front-claude-prompt'),
      resultEl: panel.querySelector('#gds-ba-front-claude-result'),
      applyBtn: panel.querySelector('#gds-ba-front-claude-apply'),
      statusEl: panel.querySelector('#gds-ba-front-claude-status'),
    };
  }

  function init() {
    var c = cfg();
    var ui = buildUi();
    if (!ui || !c) {
      return;
    }

    var conversationTurns = loadConversationTurns();
    renderTranscript(ui.transcriptEl, conversationTurns);

    ui.resultEl.value =
      typeof c.savedGlobalCss === 'string' ? c.savedGlobalCss : '';
    ui.modeEl.value =
      (ui.resultEl.value || '').trim() !== '' ? 'modify' : 'replace';

    setStatus(ui.statusEl, t('statusIdle', ''), 'idle');

    var open = false;
    function setOpen(v) {
      open = v;
      ui.panel.classList.toggle('is-open', v);
      ui.fab.setAttribute('aria-expanded', v ? 'true' : 'false');
    }

    ui.fab.addEventListener('click', function () {
      setOpen(!open);
    });

    function setBusy(busy) {
      ui.applyBtn.disabled = busy;
      ui.applyBtn.classList.toggle('is-busy', busy);
      ui.clearConvBtn.disabled = busy;
    }

    ui.clearConvBtn.addEventListener('click', function () {
      conversationTurns = [];
      persistConversationTurns(conversationTurns);
      renderTranscript(ui.transcriptEl, conversationTurns);
      setStatus(ui.statusEl, t('statusIdle', ''), 'idle');
    });

    ui.applyBtn.addEventListener('click', function () {
      var prompt = (ui.promptEl.value || '').trim();
      if (!prompt) {
        setStatus(ui.statusEl, t('emptyPrompt', 'Enter a prompt.'), 'error');
        return;
      }

      var modeRaw = ui.modeEl.value;
      var mode =
        modeRaw === 'append'
          ? 'append'
          : modeRaw === 'modify'
            ? 'modify'
            : 'replace';
      var currentCss = ui.resultEl.value || '';

      setStatus(ui.statusEl, t('working', 'Working…'), 'working');
      setBusy(true);

      var body = new window.FormData();
      body.append('action', 'gds_ba_claude_generate_and_save_global_css');
      body.append('nonce', c.nonceGenerate);
      body.append('prompt', prompt);
      body.append('mode', mode);
      body.append('current_css', currentCss);
      body.append('conversation', JSON.stringify(conversationTurns));

      window
        .fetch(c.ajaxUrl, {
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
              ui.statusEl,
              (data && data.data && data.data.message) ||
                t('failed', 'Failed'),
              'error',
            );
            return;
          }
          var out = data.data && data.data.css ? data.data.css : '';
          ui.resultEl.value = out;
          c.savedGlobalCss = out;

          conversationTurns.push({
            user: prompt,
            assistant: excerptCss(out),
          });
          persistConversationTurns(conversationTurns);
          renderTranscript(ui.transcriptEl, conversationTurns);

          ui.promptEl.value = '';
          setStatus(ui.statusEl, t('saved', 'Saved.'), 'success');
        })
        .catch(function () {
          setStatus(ui.statusEl, t('network', 'Network error'), 'error');
        })
        .finally(function () {
          setBusy(false);
        });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
