/**
 * AI WordPress Agent - Frontend Chat Widget
 */
(function () {
    'use strict';

    if (typeof AIWP_WIDGET === 'undefined') return;

    var widget = document.getElementById('aiwp-widget');
    var toggle = document.getElementById('aiwp-widget-toggle');
    var minimize = document.getElementById('aiwp-widget-minimize');
    var panel = document.getElementById('aiwp-widget-panel');
    var messagesEl = document.getElementById('aiwp-widget-messages');
    var inputEl = document.getElementById('aiwp-widget-input');
    var sendBtn = document.getElementById('aiwp-widget-send');
    var contextText = document.getElementById('aiwp-widget-context-text');

    var isLoading = false;
    var isMinimized = false;
    var pageContext = AIWP_WIDGET.page || {};

    function init() {
        if (!widget || !toggle || !messagesEl || !inputEl || !sendBtn) return;

        if (AIWP_WIDGET.position === 'left') {
            widget.classList.add('aiwp-widget-left');
        }

        updateContextDisplay();
        bindEvents();
        autoResize();
        widget.style.display = '';
    }

    function bindEvents() {
        toggle.addEventListener('click', function (e) {
            e.stopPropagation();
            if (widget.classList.contains('aiwp-widget-open')) {
                closeWidget();
            } else {
                openWidget();
            }
        });

        minimize.addEventListener('click', function () {
            closeWidget();
        });

        sendBtn.addEventListener('click', onSend);
        inputEl.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                onSend();
            }
        });
        inputEl.addEventListener('input', function () {
            autoResize();
            toggleSendBtn();
        });

        widget.addEventListener('click', function (e) {
            e.stopPropagation();
        });

        panel.addEventListener('click', function (e) {
            e.stopPropagation();
        });

        inputEl.addEventListener('focus', function () {
            setTimeout(scrollBottom, 100);
        });
    }

    function openWidget() {
        widget.classList.add('aiwp-widget-open');
        setTimeout(function () {
            inputEl.focus();
            scrollBottom();
        }, 350);
    }

    function closeWidget() {
        widget.classList.remove('aiwp-widget-open');
    }

    function updateContextDisplay() {
        if (contextText && pageContext.title) {
            contextText.textContent = pageContext.title;
        }
    }

    function autoResize() {
        inputEl.style.height = 'auto';
        inputEl.style.height = Math.min(inputEl.scrollHeight, 100) + 'px';
    }

    function toggleSendBtn() {
        sendBtn.disabled = !inputEl.value.trim() || isLoading;
    }

    function onSend() {
        var text = inputEl.value.trim();
        if (!text || isLoading) return;
        sendMessage(text);
    }

    function sendMessage(text) {
        addMessage('user', text);
        inputEl.value = '';
        autoResize();
        toggleSendBtn();
        showTyping();

        var formData = new FormData();
        formData.append('action', 'aiwp_chat');
        formData.append('nonce', AIWP_WIDGET.nonce);
        formData.append('message', text);

        isLoading = true;
        toggleSendBtn();

        fetch(AIWP_WIDGET.ajax_url, { method: 'POST', body: formData })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                hideTyping();
                if (resp.success) {
                    addMessage('ai', resp.data.message, resp.data.tool_calls || []);
                } else {
                    addMessage('ai', '⚠️ ' + (resp.data?.message || 'Ошибка. Попробуйте ещё раз.'));
                }
            })
            .catch(function () {
                hideTyping();
                addMessage('ai', '⚠️ Ошибка соединения.');
            })
            .finally(function () {
                isLoading = false;
                toggleSendBtn();
            });
    }

    function addMessage(role, content, toolCalls) {
        var div = document.createElement('div');
        div.className = 'aiwp-widget-message aiwp-widget-message-' + role;

        var bubble = document.createElement('div');
        bubble.className = 'aiwp-widget-bubble';

        if (role === 'ai') {
            bubble.innerHTML = formatHTML(content);
        } else {
            bubble.textContent = content;
        }

        if (toolCalls && toolCalls.length > 0) {
            var tcWrap = document.createElement('div');
            tcWrap.className = 'aiwp-widget-tools';
            toolCalls.forEach(function (tc) {
                var badge = document.createElement('span');
                badge.className = 'tool-badge';
                badge.textContent = '🔧 ' + tc.tool + (tc.result?.success ? ' ✅' : ' ❌');
                tcWrap.appendChild(badge);
            });
            bubble.appendChild(tcWrap);
        }

        div.appendChild(bubble);
        messagesEl.appendChild(div);
        scrollBottom();
    }

    function formatHTML(text) {
        if (!text) return '';

        var html = text;

        html = html.replace(/```(\w*)\n?([\s\S]*?)```/g, function (match, lang, code) {
            return '<pre><code>' + escapeHTML(code.trim()) + '</code></pre>';
        });

        html = html.replace(/`([^`]+)`/g, function (match, code) {
            return '<code>' + escapeHTML(code) + '</code>';
        });

        html = html.replace(/\n/g, '<br>');
        html = html.replace(/<br><br>/g, '</p><p>');
        html = '<p>' + html + '</p>';

        return html;
    }

    function escapeHTML(str) {
        var d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

    function showTyping() {
        var existing = document.querySelector('.aiwp-widget-typing');
        if (existing) return;

        var typing = document.createElement('div');
        typing.className = 'aiwp-widget-message aiwp-widget-ai aiwp-widget-typing active';

        var dots = document.createElement('div');
        dots.className = 'aiwp-widget-typing-dots';
        for (var i = 0; i < 3; i++) {
            var dot = document.createElement('div');
            dot.className = 'dot';
            dots.appendChild(dot);
        }

        typing.appendChild(dots);
        messagesEl.appendChild(typing);
        scrollBottom();
    }

    function hideTyping() {
        var typing = document.querySelector('.aiwp-widget-typing');
        if (typing) typing.remove();
    }

    function scrollBottom() {
        setTimeout(function () {
            messagesEl.scrollTop = messagesEl.scrollHeight;
        }, 50);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
