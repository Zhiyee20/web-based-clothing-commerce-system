/* ====== AI Chat Widget (fixed API path + better errors + timeout) ====== */
(() => {
  const mount = document.getElementById('ai-chat');
  if (!mount) return;

  // Build DOM
  mount.innerHTML = `
    <div class="ai-root" aria-live="polite">
      <button class="ai-toggle" type="button" aria-expanded="false" aria-controls="ai-panel">
        <span>Chat</span><span class="ai-badge" aria-hidden="true">AI</span>
      </button>

      <div id="ai-panel" class="ai-panel" role="dialog" aria-label="AI Support Assistant">
        <div class="ai-head">
          <div class="ai-title">Support Assistant</div>
          <button class="ai-close" type="button" aria-label="Close chat">×</button>
        </div>

        <div class="ai-body" id="ai-body">
          <div class="ai-msg bot">
            <div class="ai-bubble">
              Hi! I’m your assistant. Ask me about orders (e.g. “Where’s my order #21?”), refunds, products, or rewards.
            </div>
          </div>
        </div>

        <div class="ai-typing" id="ai-typing" style="display:none;">Assistant is typing…</div>

        <div class="ai-input">
          <input id="ai-input" type="text" placeholder="Type a message…" autocomplete="off" />
          <button id="ai-send" class="ai-send" type="button">Send</button>
        </div>
      </div>
    </div>
  `;

  // Elements
  const panel     = mount.querySelector('#ai-panel');
  const toggleBtn = mount.querySelector('.ai-toggle');
  const closeBtn  = mount.querySelector('.ai-close');
  const bodyEl    = mount.querySelector('#ai-body');
  const typingEl  = mount.querySelector('#ai-typing');
  const inputEl   = mount.querySelector('#ai-input');
  const sendBtn   = mount.querySelector('#ai-send');

  // Config
  const API_URL = '/api/chat.php';                // ⬅️ absolute path
  const REQUEST_TIMEOUT_MS = 20000;              // 20s

  // Toggle behavior
  const open = () => {
    panel.classList.add('is-open');
    toggleBtn.setAttribute('aria-expanded', 'true');
    inputEl.focus({ preventScroll: false });
  };
  const close = () => {
    panel.classList.remove('is-open');
    toggleBtn.setAttribute('aria-expanded', 'false');
  };
  toggleBtn.addEventListener('click', () => {
    if (panel.classList.contains('is-open')) close(); else open();
  });
  closeBtn.addEventListener('click', close);

  // Utilities
  const addMsg = (who, text) => {
    const wrap = document.createElement('div');
    wrap.className = `ai-msg ${who}`;
    const bubble = document.createElement('div');
    bubble.className = 'ai-bubble';
    bubble.textContent = text;
    wrap.appendChild(bubble);
    bodyEl.appendChild(wrap);
    bodyEl.scrollTop = bodyEl.scrollHeight;
  };

  const setTyping = (on) => {
    typingEl.style.display = on ? 'block' : 'none';
    bodyEl.scrollTop = bodyEl.scrollHeight;
  };

  const enableInputs = (on) => {
    inputEl.disabled = !on;
    sendBtn.disabled = !on;
  };

  // Safe fetch with timeout & better error extraction
  const safeFetchJSON = async (url, options = {}, timeoutMs = REQUEST_TIMEOUT_MS) => {
    const controller = new AbortController();
    const t = setTimeout(() => controller.abort(), timeoutMs);
    try {
      const res = await fetch(url, { ...options, signal: controller.signal });
      // Try to parse JSON; if it fails, try plain text so we can show something useful
      let data;
      try {
        data = await res.clone().json();
      } catch {
        const txt = await res.text();
        data = txt ? { error: txt } : null;
      }
      return { res, data };
    } finally {
      clearTimeout(t);
    }
  };

  // Send flow
  const send = async () => {
    const text = (inputEl.value || '').trim();
    if (!text) return;

    addMsg('me', text);
    inputEl.value = '';
    enableInputs(false);
    setTyping(true);

    try {
      const { res, data } = await safeFetchJSON(API_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ message: text })
      });

      if (!res.ok) {
        const serverErr = (data && (data.error || data.message)) || `HTTP ${res.status}`;
        addMsg('bot', `Server error: ${serverErr}`);
        return;
      }

      if (!data) {
        addMsg('bot', 'Sorry, I did not receive a response.');
        return;
      }

      if (data.error) {
        addMsg('bot', `Server error: ${data.error}`);
      } else {
        addMsg('bot', data.reply || 'Hmm, I couldn’t generate a reply right now.');
      }
    } catch (err) {
      const reason = err?.name === 'AbortError' ? 'Request timed out.' : 'Network error.';
      addMsg('bot', `${reason} Please try again.`);
      console.error('[AI Chat] send error:', err);
    } finally {
      setTyping(false);
      enableInputs(true);
      inputEl.focus();
    }
  };

  // Events
  sendBtn.addEventListener('click', send);
  inputEl.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      send();
    }
  });

  // open(); // optional
})();
