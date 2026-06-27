/**
 * AI WordPress Agent - Chat Interface
 */
(function () {
    'use strict';

    const messagesEl = document.getElementById('aiwp-messages');
    const inputEl = document.getElementById('aiwp-input');
    const sendBtn = document.getElementById('aiwp-send');
    const clearBtn = document.getElementById('aiwp-clear');

    let isLoading = false;

    function init() {
        if (!messagesEl || !inputEl || !sendBtn) return;

        loadHistory();
        bindEvents();
        autoResizeInput();
    }

    function bindEvents() {
        sendBtn.addEventListener('click', onSend);
        inputEl.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                onSend();
            }
        });
        inputEl.addEventListener('input', autoResizeInput);
        inputEl.addEventListener('input', toggleSendButton);

        if (clearBtn) {
            clearBtn.addEventListener('click', onClear);
        }
    }

    function autoResizeInput() {
        inputEl.style.height = 'auto';
        inputEl.style.height = Math.min(inputEl.scrollHeight, 120) + 'px';
    }

    function toggleSendButton() {
        const hasText = inputEl.value.trim().length > 0;
        sendBtn.disabled = !hasText || isLoading;
    }

    function onSend() {
        const text = inputEl.value.trim();
        if (!text || isLoading) return;

        sendMessage(text);
    }

    function onClear() {
        if (isLoading) return;
        if (!confirm('Очистить историю чата?')) return;

        const formData = new FormData();
        formData.append('action', 'aiwp_chat');
        formData.append('nonce', AIWP.nonce);
        formData.append('message', '');
        formData.append('reset', '1');

        isLoading = true;
        fetch(AIWP.ajax_url, { method: 'POST', body: formData })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                if (resp.success) {
                    messagesEl.innerHTML = '';
                    addMessage('ai', resp.data.message);
                }
            })
            .catch(function () {
                addMessage('ai', '⚠️ Ошибка при очистке истории.');
            })
            .finally(function () {
                isLoading = false;
                toggleSendButton();
            });
    }

    function sendMessage(text) {
        addMessage('user', text);
        inputEl.value = '';
        autoResizeInput();
        toggleSendButton();

        showTyping();

        const formData = new FormData();
        formData.append('action', 'aiwp_chat');
        formData.append('nonce', AIWP.nonce);
        formData.append('message', text);

        isLoading = true;
        toggleSendButton();

        fetch(AIWP.ajax_url, { method: 'POST', body: formData })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                hideTyping();
                if (resp.success) {
                    const data = resp.data;
                    addMessage('ai', data.message, data.tool_calls || []);
                } else {
                    addMessage('ai', '⚠️ ' + (resp.data?.message || 'Произошла ошибка. Попробуйте ещё раз.'));
                }
            })
            .catch(function (err) {
                hideTyping();
                addMessage('ai', '⚠️ Ошибка соединения. Проверьте подключение к интернету.');
                if (AIWP.debug) console.error('AIWP Chat Error:', err);
            })
            .finally(function () {
                isLoading = false;
                toggleSendButton();
            });
    }

    function addMessage(role, content, toolCalls) {
        const div = document.createElement('div');
        div.className = 'aiwp-message aiwp-message-' + role;

        if (content.includes('⚠️') && content.includes('Ошибк')) {
            div.classList.add('aiwp-message-error');
        }

        const avatar = document.createElement('div');
        avatar.className = 'aiwp-avatar aiwp-avatar-' + role;
        avatar.textContent = role === 'user' ? '👤' : '🤖';

        const bubble = document.createElement('div');
        bubble.className = 'aiwp-bubble';

        if (role === 'ai') {
            bubble.innerHTML = formatAIResponse(content);
        } else {
            bubble.textContent = content;
        }

        if (toolCalls && toolCalls.length > 0) {
            const tcContainer = document.createElement('div');
            tcContainer.className = 'aiwp-tool-calls';
            toolCalls.forEach(function (tc) {
                const badge = document.createElement('span');
                badge.className = 'aiwp-tool-call-badge';
                badge.textContent = '🔧 ' + tc.tool;
                if (tc.result) {
                    badge.textContent += tc.result.success ? ' ✅' : ' ❌';
                }
                tcContainer.appendChild(badge);
            });
            bubble.appendChild(tcContainer);
        }

        div.appendChild(avatar);
        div.appendChild(bubble);
        messagesEl.appendChild(div);

        scrollToBottom();
    }

    function formatAIResponse(text) {
        if (!text) return '';

        var html = text;

        html = html.replace(/```(\w*)\n?([\s\S]*?)```/g, function (match, lang, code) {
            return '<pre><code>' + escapeHtml(code.trim()) + '</code></pre>';
        });

        html = html.replace(/`([^`]+)`/g, function (match, code) {
            return '<code>' + escapeHtml(code) + '</code>';
        });

        if (/<\/?[a-z][\s\S]*>/i.test(html)) {
            return '<div class="aiwp-response-html">' + html + '</div>';
        }

        html = html.replace(/\n{2,}/g, '</p><p>');
        html = html.replace(/\n/g, '<br>');
        html = '<p>' + html + '</p>';

        return html;
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function showTyping() {
        var existing = document.querySelector('.aiwp-typing');
        if (existing) return;

        var typing = document.createElement('div');
        typing.className = 'aiwp-message aiwp-message-ai aiwp-typing active';

        var avatar = document.createElement('div');
        avatar.className = 'aiwp-avatar aiwp-avatar-ai';
        avatar.textContent = '🤖';

        var dots = document.createElement('div');
        dots.className = 'aiwp-typing-dots';
        for (var i = 0; i < 3; i++) {
            var dot = document.createElement('div');
            dot.className = 'aiwp-typing-dot';
            dots.appendChild(dot);
        }

        typing.appendChild(avatar);
        typing.appendChild(dots);
        messagesEl.appendChild(typing);
        scrollToBottom();
    }

    function hideTyping() {
        var typing = document.querySelector('.aiwp-typing');
        if (typing) {
            typing.remove();
        }
    }

    function scrollToBottom() {
        setTimeout(function () {
            messagesEl.scrollTop = messagesEl.scrollHeight;
        }, 50);
    }

    function loadHistory() {
        var formData = new FormData();
        formData.append('action', 'aiwp_history');
        formData.append('nonce', AIWP.nonce);

        fetch(AIWP.ajax_url, { method: 'POST', body: formData })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                if (resp.success && resp.data.history) {
                    resp.data.history.forEach(function (entry) {
                        addMessage('user', entry.user);
                        addMessage('ai', entry.assistant, entry.tool_calls || []);
                    });
                }
            })
            .catch(function () {
                // silently fail, welcome message already shown
            });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
