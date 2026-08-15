/**
 * Agent 对话页面 JavaScript
 *
 * 功能：
 *  - ChatGPT-like 对话界面，SSE 流式响应
 *  - @ 引用补全（终端/QID/分组）
 *  - 工具调用可视化
 *  - Agent 工作区（文件上传/删除/AI分析）
 */

(function() {
  'use strict';

  // ── 配置 ──
  // 通过 PHP 代理转发请求，避免浏览器跨域 / 127.0.0.1 不可达问题
  const PROXY_BASE = '/plugins/admanager/ajax/agent_proxy.php';

  // ── CSRF Token（GLPI 要求每次 POST/DELETE 携带）──
  // 统一走 adm-utils.js 的 AdManager.getCsrfToken()（多源读取 #agent-csrf 等）

  // ── 状态 ──
  let chatHistory = [];       // [{role, content}]
  let references = [];        // [{type, id, name}]
  let isStreaming = false;
  let abortController = null;
  let selectedFile = null;    // 当前选中分析的工作区文件

  // ── DOM ──
  const $messages = document.getElementById('agent-messages');
  const $input = document.getElementById('agent-input');
  const $sendBtn = document.getElementById('agent-send-btn');
  const $clearBtn = document.getElementById('agent-clear-btn');
  const $stopBtn = document.getElementById('agent-stop-btn');
  // 停止生成：点击中断当前 XHR 流（makeStopHandler 暴露纯逻辑便于测试）
  if ($stopBtn) {
    $stopBtn.addEventListener('click', makeStopHandler(() => (abortController ? abortController._xhr : null)));
  }
  const $status = document.getElementById('agent-status');
  const $refs = document.getElementById('agent-references');
  const $mentions = document.getElementById('agent-mentions');

  // ── 工具函数 ──
  function scrollToBottom() {
    $messages.scrollTop = $messages.scrollHeight;
  }

  function setStatus(text, type) {
    const iconMap = {
      ready: '<i class="ti ti-point-filled text-success"></i>',
      streaming: '<i class="ti ti-point-filled text-warning"></i>',
      error: '<i class="ti ti-point-filled text-danger"></i>',
    };
    $status.innerHTML = (iconMap[type] || iconMap.ready) + ' ' + text;
    $status.className = 'agent-status' + (type !== 'ready' ? ' ' + type : '');
  }

  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  function formatContent(text) {
    // 简单 Markdown 渲染
    let html = escapeHtml(text);
    // 代码块
    html = html.replace(/```(\w*)\n?([\s\S]*?)```/g, (m, lang, code) => {
      return '<pre><code>' + code.trim() + '</code></pre>';
    });
    // 行内代码
    html = html.replace(/`([^`]+)`/g, '<code>$1</code>');
    // 粗体
    html = html.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
    // 列表
    html = html.replace(/^- (.+)$/gm, '<li>$1</li>');
    html = html.replace(/(<li>[\s\S]*?<\/li>)/g, '<ul>$1</ul>');
    // 换行
    html = html.replace(/\n/g, '<br>');
    return html;
  }

  function addMessage(role, content) {
    const msg = document.createElement('div');
    msg.className = 'agent-msg agent-msg-' + role;

    const avatar = document.createElement('div');
    avatar.className = 'agent-avatar';
    avatar.innerHTML = role === 'user'
      ? '<i class="ti ti-user"></i>'
      : '<i class="ti ti-robot"></i>';

    const bubble = document.createElement('div');
    bubble.className = 'agent-bubble';
    bubble.innerHTML = role === 'assistant' ? formatContent(content) : escapeHtml(content);

    msg.appendChild(avatar);
    msg.appendChild(bubble);
    $messages.appendChild(msg);
    scrollToBottom();
    return bubble;
  }

  function addTypingIndicator() {
    const msg = document.createElement('div');
    msg.className = 'agent-msg agent-msg-assistant';
    msg.id = 'typing-indicator';
    msg.innerHTML = `
      <div class="agent-avatar"><i class="ti ti-robot"></i></div>
      <div class="agent-bubble">
        <div class="agent-typing"><span></span><span></span><span></span></div>
      </div>
    `;
    $messages.appendChild(msg);
    scrollToBottom();
  }

  function removeTypingIndicator() {
    const el = document.getElementById('typing-indicator');
    if (el) el.remove();
  }

  function addToolCall(toolName, args) {
    const msg = $messages.lastElementChild;
    if (!msg || !msg.classList.contains('agent-msg-assistant')) return;

    const bubble = msg.querySelector('.agent-bubble');
    const toolEl = document.createElement('div');
    toolEl.className = 'agent-tool-call';

    const iconMap = {
      list_pending_tasks: 'ti-list-check',
      shell_exec: 'ti-terminal',
      dispatch_task: 'ti-send',
      get_client_status: 'ti-device-desktop',
      update_rule: 'ti-book',
      manage_package: 'ti-package',
      schedule_task: 'ti-clock',
      set_priority: 'ti-flag',
    };

    const nameMap = {
      list_pending_tasks: '查看待处理任务',
      shell_exec: '执行远程命令',
      dispatch_task: '下发修复任务',
      get_client_status: '查看终端状态',
      update_rule: '修改QID规则',
      manage_package: '管理安装包',
      schedule_task: '安排定时任务',
      set_priority: '设置优先级',
    };

    toolEl.innerHTML = `
      <div class="agent-tool-call-header">
        <i class="ti ${iconMap[toolName] || 'ti-tool'}"></i>
        ${nameMap[toolName] || toolName}
      </div>
      <div class="agent-tool-call-args">${escapeHtml(JSON.stringify(args, null, 2))}</div>
    `;
    bubble.appendChild(toolEl);
    scrollToBottom();
  }

  function addToolResult(toolName, result) {
    const msg = $messages.lastElementChild;
    if (!msg || !msg.classList.contains('agent-msg-assistant')) return;

    const bubble = msg.querySelector('.agent-bubble');
    const resultEl = document.createElement('div');
    const hasError = result && result.error;
    resultEl.className = 'agent-tool-result' + (hasError ? ' has-error' : '');

    // 格式化结果
    let displayText;
    if (typeof result === 'object') {
      // 特殊处理：任务列表
      if (result.tasks) {
        displayText = `找到 ${result.count || result.tasks.length} 个任务:\n`;
        result.tasks.slice(0, 10).forEach(t => {
          displayText += `  #${t.id} [${t.fix_type}] ${t.title || ''} → ${t.hostname || t.ip || '未匹配'} (${t.status})\n`;
        });
        if (result.tasks.length > 10) displayText += `  ...还有 ${result.tasks.length - 10} 个\n`;
      }
      // 特殊处理：终端列表
      else if (result.clients) {
        displayText = `终端总数: ${result.total}, 在线: ${result.online_count}\n`;
        result.clients.slice(0, 10).forEach(c => {
          displayText += `  ${c.online ? '🟢' : '🔴'} ${c.hostname} (${c.ip || '无IP'}) [ID:${c.id}]\n`;
        });
        if (result.clients.length > 10) displayText += `  ...还有 ${result.clients.length - 10} 个\n`;
      }
      // 特殊处理：安装包列表
      else if (result.packages) {
        displayText = `安装包 ${result.count} 个:\n`;
        result.packages.forEach(p => {
          displayText += `  #${p.id} ${p.name} v${p.version} (${p.filename})\n`;
        });
      }
      // 通用 JSON
      else {
        displayText = JSON.stringify(result, null, 2);
      }
    } else {
      displayText = String(result);
    }

    resultEl.textContent = displayText;
    bubble.appendChild(resultEl);
    scrollToBottom();
  }

  // ── 发送消息 ──
  let currentSessionId = null;

  // [SSE-PARSER-BEGIN] — 纯函数：带行缓冲的 SSE 解析器（Node 测试可直接抽取）
  // 修复"AI 回答被吞"：XHR onprogress 的网络分片会在任意字节处切断 data: 行。
  // 旧实现按 '\n' 切分每个分片并直接 JSON.parse —— 跨分片的那一截前半段
  // parse 失败被丢、后半段不以 'data: ' 开头被跳过，导致整条 content 事件丢失。
  // 这里把原始文本累积进 buffer，只处理"以 \n 结尾的完整行"，不完整的尾部留给
  // 下一个分片；流结束（flush）时再处理可能不带换行的最后一行。
  function createSSEParser(dispatch) {
    let buffer = '';
    function handleLine(line) {
      if (!line.startsWith('data: ')) return;
      const dataStr = line.slice(6).trim();
      if (!dataStr) return;
      let evt;
      try { evt = JSON.parse(dataStr); }
      catch (e) { console.warn('SSE parse error:', e, dataStr); return; }
      dispatch(evt);
    }
    return {
      push(text) {
        buffer += text;
        let idx;
        while ((idx = buffer.indexOf('\n')) >= 0) {
          handleLine(buffer.slice(0, idx));
          buffer = buffer.slice(idx + 1);
        }
      },
      flush() {
        if (buffer.trim()) handleLine(buffer);
        buffer = '';
      },
    };
  }
  // [SSE-PARSER-END]

  // [ABORT-HELPER-BEGIN]
  // 停止按钮处理器工厂：点击时中断当前活跃的 XHR 流。
  // 抽成纯函数便于 Node 单测（无需整个 DOM / XMLHttpRequest）。
  function makeStopHandler(getActiveXhr) {
    return function () {
      const x = getActiveXhr();
      if (x && typeof x.abort === 'function') {
        x.abort();
      }
    };
  }
  // [ABORT-HELPER-END]

  // 通用 SSE 流式对话（正常提问 / 审批回传 共用）
  function streamChat(payload) {
    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      xhr.open('POST', PROXY_BASE + '?action=chat');
      xhr.setRequestHeader('Content-Type', 'application/json');
      xhr.setRequestHeader('X-Glpi-Csrf-Token', AdManager.getCsrfToken());

      let lastIndex = 0;
      let resolved = false;

      removeTypingIndicator();
      const bubble = addMessage('assistant', '');
      let fullContent = '';
      let streamCursor = document.createElement('span');
      streamCursor.className = 'stream-cursor';
      bubble.appendChild(streamCursor);

      const parser = createSSEParser(function(evt) {
        if (evt.type === 'content') {
          fullContent += evt.content;
          bubble.innerHTML = formatContent(fullContent);
          bubble.appendChild(streamCursor);
          scrollToBottom();
        }
        else if (evt.type === 'tool_call') {
          if (streamCursor.parentNode) streamCursor.remove();
          addToolCall(evt.tool_name, evt.arguments);
          bubble.appendChild(streamCursor);
          scrollToBottom();
        }
        else if (evt.type === 'tool_result') {
          if (streamCursor.parentNode) streamCursor.remove();
          addToolResult(evt.tool_name, evt.result);
          bubble.appendChild(streamCursor);
          scrollToBottom();
        }
        else if (evt.type === 'session') {
          currentSessionId = evt.session_id;
        }
        else if (evt.type === 'confirmation_required') {
          if (streamCursor.parentNode) streamCursor.remove();
          showApprovalModal(evt, bubble);
        }
        else if (evt.type === 'done') {
          if (streamCursor.parentNode) streamCursor.remove();
          if (fullContent) bubble.innerHTML = formatContent(fullContent);
          resolved = true;
          resolve(fullContent);
        }
        else if (evt.type === 'error') {
          if (streamCursor.parentNode) streamCursor.remove();
          bubble.innerHTML += '<p class="text-danger">⚠ ' + escapeHtml(evt.content) + '</p>';
        }
      });

      xhr.onprogress = function() {
        const newText = xhr.responseText.substring(lastIndex);
        lastIndex = xhr.responseText.length;
        if (newText) parser.push(newText);
      };
      xhr.onload = function() {
        parser.push(xhr.responseText.substring(lastIndex));
        parser.flush();  // 处理末尾可能不带换行的最后一行
        if (!resolved) resolve(fullContent);
      };
      xhr.onerror = function() {
        removeTypingIndicator();
        addMessage('assistant', '❌ 网络错误: ' + (xhr.statusText || '连接失败'));
        setStatus('错误', 'error');
        resolve(null);
      };
      xhr.ontimeout = function() {
        if (!resolved) resolve(fullContent || null);
      };
      // 用户点击"停止生成"→ xhr.abort() 触发本处理器，reject 让 sendMessage
      // 进入 catch 的 AbortError 分支，显示"（已取消）"。
      xhr.onabort = function() {
        if (!resolved) {
          resolved = true;
          reject(new DOMException('Aborted', 'AbortError'));
        }
      };
      xhr.timeout = 300000; // 5 分钟超时
      xhr.send(JSON.stringify(payload));
      abortController._xhr = xhr;
    });
  }

  async function sendMessage() {
    const message = $input.value.trim();
    if (!message || isStreaming) return;

    addMessage('user', message);
    chatHistory.push({ role: 'user', content: message });
    $input.value = '';
    $input.style.height = 'auto';
    updateReferencesDisplay();

    addTypingIndicator();
    isStreaming = true;
    setStatus('思考中…', 'streaming');
    $sendBtn.disabled = true;
    if ($stopBtn) $stopBtn.classList.remove('d-none');
    abortController = new AbortController();
    try {
      const sseData = await streamChat({
        message: message,
        history: chatHistory.slice(-10),
        references: references.map(r => ({ type: r.type, id: r.id, name: r.name })),
      });
      if (sseData) chatHistory.push({ role: 'assistant', content: sseData });
      setStatus('就绪', 'ready');
    } catch (err) {
      removeTypingIndicator();
      if (err && err.name === 'AbortError') {
        addMessage('assistant', '（已取消）');
        setStatus('已取消', 'ready');
      } else {
        addMessage('assistant', '❌ 网络错误: ' + err.message);
        setStatus('错误', 'error');
      }
    } finally {
      isStreaming = false;
      $sendBtn.disabled = false;
      if ($stopBtn) $stopBtn.classList.add('d-none');
      abortController = null;
    }
  }

  // 用户确认/拒绝高危或批量操作后，回传后端恢复执行
  async function sendApproval(decision) {
    if (isStreaming) return;
    addTypingIndicator();
    isStreaming = true;
    setStatus('执行中…', 'streaming');
    $sendBtn.disabled = true;
    if ($stopBtn) $stopBtn.classList.remove('d-none');
    abortController = new AbortController();
    try {
      const sseData = await streamChat({
        confirm: { session_id: currentSessionId, decision: decision },
        message: '',
        history: [],
        references: [],
      });
      if (sseData) chatHistory.push({ role: 'assistant', content: sseData });
      setStatus('就绪', 'ready');
    } catch (err) {
      removeTypingIndicator();
      addMessage('assistant', '❌ 网络错误: ' + err.message);
      setStatus('错误', 'error');
    } finally {
      isStreaming = false;
      $sendBtn.disabled = false;
      if ($stopBtn) $stopBtn.classList.add('d-none');
      abortController = null;
    }
  }

  // 高危/批量操作确认弹窗
  function showApprovalModal(evt, bubble) {
    const risks = evt.risks || [];
    if (window.bootstrap) {
      // 复用插件样式与配色：容器挂 .adm-approval-modal（agent_chat.css 内定义，走 --adm-* 令牌），
      // 参数块直接复用既有 .agent-tool-call-args；不写裸 .small / bg-warning-subtle，
      // 避免与 GLPI 核心/主题碰撞或依赖 Bootstrap 5.3+。
      const rows = risks.map(r => {
        const argsStr = escapeHtml(JSON.stringify(r.args, null, 2));
        return '<div class="adm-risk-item">'
          + '<div class="adm-risk-name">' + escapeHtml(r.tool_name) + '</div>'
          + '<div class="adm-risk-desc">' + escapeHtml(r.risk) + '</div>'
          + '<pre class="agent-tool-call-args">' + argsStr + '</pre></div>';
      }).join('');
      const modalId = 'agentApprovalModal';
      const old = document.getElementById(modalId);
      if (old) old.remove();
      const html =
        '<div class="modal fade adm-approval-modal" id="' + modalId + '" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">'
        + '<div class="modal-dialog modal-dialog-centered modal-lg">'
        + '<div class="modal-content">'
        + '<div class="modal-header">'
        + '<h5 class="modal-title">⚠ 请确认高危/批量操作</h5>'
        + '<button type="button" class="btn-close" id="approvalClose" aria-label="关闭"></button>'
        + '</div>'
        + '<div class="modal-body"><p class="adm-hint-text">以下操作可能影响生产环境，确认后才会执行：</p>' + rows + '</div>'
        + '<div class="modal-footer">'
        + '<button type="button" class="btn btn-outline-secondary" id="approvalCancel">取消</button>'
        + '<button type="button" class="btn btn-danger" id="approvalReject">拒绝</button>'
        + '<button type="button" class="btn btn-success" id="approvalApprove">确认执行</button>'
        + '</div></div></div></div>';
      document.body.insertAdjacentHTML('beforeend', html);
      const modalEl = document.getElementById(modalId);
      $input.disabled = true;
      const bsModal = new bootstrap.Modal(modalEl);
      bsModal.show();
      const cancelModal = () => { bootstrap.Modal.getInstance(modalEl).hide(); };
      document.getElementById('approvalApprove').onclick = () => { bootstrap.Modal.getInstance(modalEl).hide(); sendApproval('approve'); };
      document.getElementById('approvalReject').onclick = () => { bootstrap.Modal.getInstance(modalEl).hide(); sendApproval('reject'); };
      document.getElementById('approvalCancel').onclick = cancelModal;
      document.getElementById('approvalClose').onclick = cancelModal;
      modalEl.addEventListener('hidden.bs.modal', () => { $input.disabled = false; modalEl.remove(); });
    } else {
      const summary = risks.map(r => r.tool_name + ': ' + r.risk).join('\n');
      const ok = window.confirm('⚠ 高危/批量操作确认：\n' + summary + '\n\n确定=执行，取消=拒绝');
      sendApproval(ok ? 'approve' : 'reject');
    }
  }

  // ── 清空对话 ──
  function clearChat() {
    if (!confirm('确定清空所有对话？')) return;
    chatHistory = [];
    references = [];
    // 保留欢迎消息
    const welcome = $messages.firstElementChild;
    $messages.innerHTML = '';
    if (welcome) $messages.appendChild(welcome);
    updateReferencesDisplay();
    setStatus('就绪', 'ready');
  }

  // ── @ 引用补全 ──
  let mentionQuery = '';
  let mentionStart = -1;
  let activeMentionIndex = -1;

  $input.addEventListener('input', function(e) {
    const val = this.value;
    const pos = this.selectionStart;

    // 检测 @ 引用
    const beforeCursor = val.substring(0, pos);
    const atMatch = beforeCursor.match(/@(\w*)$/);

    if (atMatch) {
      mentionQuery = atMatch[1];
      mentionStart = beforeCursor.length - atMatch[0].length;
      showMentions(mentionQuery);
    } else {
      hideMentions();
    }

    // 自动调整高度
    this.style.height = 'auto';
    this.style.height = Math.min(this.scrollHeight, 120) + 'px';
  });

  async function showMentions(query) {
    try {
      const resp = await fetch(
        PROXY_BASE + '?action=suggestions&q=' + encodeURIComponent(query),
        { headers: {} }
      );
      if (!resp.ok) return;
      const data = await resp.json();

      const hasResults = data.clients?.length || data.qids?.length || data.groups?.length;
      if (!hasResults) {
        hideMentions();
        return;
      }

      // 填充补全列表
      const sections = $mentions.querySelectorAll('.mentions-section');
      sections.forEach(section => {
        const type = section.dataset.section;
        const items = data[type] || [];
        const itemsEl = section.querySelector('.mentions-items');
        itemsEl.innerHTML = '';

        if (items.length === 0) {
          section.style.display = 'none';
          return;
        }
        section.style.display = '';

        items.forEach((item, idx) => {
          const el = document.createElement('div');
          el.className = 'mention-item';
          el.dataset.type = type;
          el.dataset.id = item.id;
          el.dataset.name = item.name;

          const iconClass = type === 'clients' ? 'client' : (type === 'qids' ? 'qid' : 'group');
          const iconIcon = type === 'clients' ? 'ti-device-desktop' : (type === 'qids' ? 'ti-book' : 'ti-users');

          el.innerHTML = `
            <div class="mention-icon ${iconClass}"><i class="ti ${iconIcon}"></i></div>
            <div class="mention-name">${escapeHtml(item.name)}</div>
            ${item.ip ? `<div class="mention-meta">${item.ip}</div>` : ''}
            ${item.fix_type ? `<div class="mention-meta">${item.fix_type}</div>` : ''}
          `;

          el.addEventListener('click', () => selectMention(type, item));
          itemsEl.appendChild(el);
        });
      });

      $mentions.style.display = '';
      activeMentionIndex = -1;
    } catch (e) {
      console.warn('Mentions fetch error:', e);
    }
  }

  function hideMentions() {
    $mentions.style.display = 'none';
    mentionStart = -1;
  }

  function selectMention(type, item) {
    // 移除输入中的 @query 文本
    const val = $input.value;
    const before = val.substring(0, mentionStart);
    const after = val.substring($input.selectionStart);
    $input.value = before + after;
    $input.focus();

    // 添加引用
    const ref = { type: type.replace('s', '') === 'client' ? 'client' : (type.replace('s', '') === 'qid' ? 'qid' : 'group'), id: item.id, name: item.name };
    // 去重
    if (!references.find(r => r.type === ref.type && r.id === ref.id)) {
      references.push(ref);
    }
    updateReferencesDisplay();
    hideMentions();
  }

  function updateReferencesDisplay() {
    $refs.innerHTML = '';
    references.forEach((ref, idx) => {
      const chip = document.createElement('span');
      chip.className = 'agent-ref-chip';
      chip.innerHTML = `
        <i class="ti ti-at"></i>${escapeHtml(ref.name)}
        <span class="ref-remove" data-idx="${idx}"><i class="ti ti-x"></i></span>
      `;
      chip.querySelector('.ref-remove').addEventListener('click', () => {
        references.splice(idx, 1);
        updateReferencesDisplay();
      });
      $refs.appendChild(chip);
    });
  }

  // ── 键盘事件 ──
  $input.addEventListener('keydown', function(e) {
    // Ctrl+Enter 发送
    if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
      e.preventDefault();
      sendMessage();
      return;
    }

    // @ 补全键盘导航
    if ($mentions.style.display !== 'none') {
      const items = $mentions.querySelectorAll('.mention-item');
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        activeMentionIndex = Math.min(activeMentionIndex + 1, items.length - 1);
        updateActiveMention(items);
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        activeMentionIndex = Math.max(activeMentionIndex - 1, 0);
        updateActiveMention(items);
      } else if (e.key === 'Enter' && activeMentionIndex >= 0) {
        e.preventDefault();
        const item = items[activeMentionIndex];
        if (item) {
          selectMention(item.dataset.type, {
            id: parseInt(item.dataset.id),
            name: item.dataset.name,
          });
        }
      } else if (e.key === 'Escape') {
        hideMentions();
      }
    }
  });

  function updateActiveMention(items) {
    items.forEach((item, idx) => {
      item.classList.toggle('active', idx === activeMentionIndex);
      if (idx === activeMentionIndex) {
        item.scrollIntoView({ block: 'nearest' });
      }
    });
  }

  // ── 事件绑定 ──
  $sendBtn.addEventListener('click', sendMessage);
  $clearBtn.addEventListener('click', clearChat);

  // ── 工作区功能 ──
  const $uploadZone = document.getElementById('ws-upload-zone');
  const $fileInput = document.getElementById('ws-file-input');
  const $filesList = document.getElementById('ws-files');
  const $refreshBtn = document.getElementById('ws-refresh-btn');
  const $analyzeArea = document.getElementById('ws-analyze-area');
  const $analyzeBtn = document.getElementById('ws-analyze-btn');
  const $analyzeResult = document.getElementById('ws-analyze-result');
  const $analyzeQuestion = document.getElementById('ws-analyze-question');

  if ($uploadZone) {
    $uploadZone.addEventListener('click', () => $fileInput.click());
    $fileInput.addEventListener('change', () => uploadFiles($fileInput.files));

    // 拖拽上传
    $uploadZone.addEventListener('dragover', e => {
      e.preventDefault();
      $uploadZone.classList.add('dragover');
    });
    $uploadZone.addEventListener('dragleave', () => $uploadZone.classList.remove('dragover'));
    $uploadZone.addEventListener('drop', e => {
      e.preventDefault();
      $uploadZone.classList.remove('dragover');
      uploadFiles(e.dataTransfer.files);
    });
  }

  async function uploadFiles(files) {
    if (!files || !files.length) return;
    const $progress = document.getElementById('ws-upload-progress');
    if ($progress) $progress.style.display = '';

    for (const file of files) {
      const formData = new FormData();
      formData.append('file', file);

      try {
        const resp = await fetch(PROXY_BASE + '?action=upload', {
          method: 'POST',
          headers: {
            'X-Glpi-Csrf-Token': AdManager.getCsrfToken(),
          },
          body: formData,
        });
        const data = await resp.json();
        if (data.ok) {
          console.log('Uploaded:', data.filename);
        } else {
          alert('上传失败: ' + (data.detail || '未知错误'));
        }
      } catch (e) {
        alert('上传错误: ' + e.message);
      }
    }

    if ($progress) $progress.style.display = 'none';
    $fileInput.value = '';
    loadFiles();
  }

  async function loadFiles() {
    try {
      const resp = await fetch(PROXY_BASE + '?action=files', {
        headers: {},
      });
      const data = await resp.json();
      renderFiles(data.files || []);
    } catch (e) {
      $filesList.innerHTML = '<div class="text-center text-muted py-3">加载失败</div>';
    }
  }

  function renderFiles(files) {
    if (!files.length) {
      $filesList.innerHTML = '<div class="text-center text-muted py-3"><i class="ti ti-inbox"></i><br>暂无文件</div>';
      return;
    }

    const extIconMap = {
      '.exe': { cls: 'exe', icon: 'ti-apps' },
      '.msi': { cls: 'msi', icon: 'ti-package' },
      '.zip': { cls: 'zip', icon: 'ti-file-zip' },
      '.7z':  { cls: 'sevenz', icon: 'ti-file-zip' },
      '.txt': { cls: 'txt', icon: 'ti-file-text' },
      '.log': { cls: 'log', icon: 'ti-file-text' },
      '.bat': { cls: 'txt', icon: 'ti-terminal' },
      '.ps1': { cls: 'txt', icon: 'ti-terminal' },
      '.cmd': { cls: 'txt', icon: 'ti-terminal' },
      '.json': { cls: 'txt', icon: 'ti-code' },
      '.xml':  { cls: 'txt', icon: 'ti-code' },
    };

    $filesList.innerHTML = '';
    files.forEach(f => {
      const ext = f.ext || '';
      const iconInfo = extIconMap[ext] || { cls: 'default', icon: 'ti-file' };

      const el = document.createElement('div');
      el.className = 'ws-file-item';
      el.dataset.filename = f.name;

      const sizeStr = f.size > 1048576
        ? (f.size / 1048576).toFixed(1) + ' MB'
        : (f.size / 1024).toFixed(0) + ' KB';

      // 统一时间格式（GLPI 风格 Y-m-d H:i:s，本地时区），避免 toLocaleString 的本地化歧义
      const dateStr = (function () {
        const d = new Date(f.modified);
        if (isNaN(d.getTime())) return '—';
        const p = function (x) { return (x < 10 ? '0' : '') + x; };
        return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate())
             + ' ' + p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
      })();

      el.innerHTML = `
        <div class="ws-file-icon ${iconInfo.cls}"><i class="ti ${iconInfo.icon}"></i></div>
        <div class="ws-file-info">
          <div class="ws-file-name" title="${escapeHtml(f.name)}">${escapeHtml(f.name)}</div>
          <div class="ws-file-meta">${sizeStr} · ${dateStr}</div>
        </div>
        <div class="ws-file-actions">
          <button class="analyze-btn" title="AI分析"><i class="ti ti-brain"></i></button>
          <button class="delete" title="删除"><i class="ti ti-trash"></i></button>
        </div>
      `;

      // 点击选中
      el.addEventListener('click', e => {
        if (e.target.closest('.ws-file-actions')) return;
        document.querySelectorAll('.ws-file-item').forEach(x => x.classList.remove('selected'));
        el.classList.add('selected');
        selectedFile = f.name;
        $analyzeArea.style.display = '';
      });

      // AI 分析
      el.querySelector('.analyze-btn').addEventListener('click', e => {
        e.stopPropagation();
        selectedFile = f.name;
        document.querySelectorAll('.ws-file-item').forEach(x => x.classList.remove('selected'));
        el.classList.add('selected');
        $analyzeArea.style.display = '';
        analyzeFile();
      });

      // 删除
      el.querySelector('.delete').addEventListener('click', async e => {
        e.stopPropagation();
        if (!confirm('确定删除 ' + f.name + '?')) return;
        try {
          await fetch(PROXY_BASE + '?action=delete&filename=' + encodeURIComponent(f.name), {
            method: 'DELETE',
            headers: {
              'X-Glpi-Csrf-Token': AdManager.getCsrfToken(),
            },
          });
          loadFiles();
        } catch (err) {
          alert('删除失败: ' + err.message);
        }
      });

      $filesList.appendChild(el);
    });
  }

  async function analyzeFile() {
    if (!selectedFile) return;
    const question = $analyzeQuestion.value.trim() || '分析这个文件的用途和关键信息';

    $analyzeResult.innerHTML = '<div class="analyze-loading"><i class="ti ti-loader ti-spin"></i> AI 分析中…</div>';
    $analyzeBtn.disabled = true;

    try {
      const resp = await fetch(PROXY_BASE + '?action=analyze', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Glpi-Csrf-Token': AdManager.getCsrfToken(),
        },
        body: JSON.stringify({ filename: selectedFile, question: question }),
      });
      const data = await resp.json();

      if (data.analysis) {
        $analyzeResult.innerHTML = '<div class="analyze-content">' + formatContent(data.analysis) + '</div>';
      } else {
        $analyzeResult.innerHTML = '<div class="text-danger">分析失败: ' + (data.detail || '') + '</div>';
      }
    } catch (e) {
      $analyzeResult.innerHTML = '<div class="text-danger">错误: ' + e.message + '</div>';
    } finally {
      $analyzeBtn.disabled = false;
    }
  }

  if ($refreshBtn) $refreshBtn.addEventListener('click', loadFiles);
  if ($analyzeBtn) $analyzeBtn.addEventListener('click', analyzeFile);

  // ── Agent Prompt 管理 ──
  const $promptBtn = document.getElementById('agent-prompt-btn');
  const $promptModal = document.getElementById('promptModal');
  const $promptBase = document.getElementById('prompt-base');
  const $promptCustom = document.getElementById('prompt-custom');
  const $promptPreview = document.getElementById('prompt-preview');
  const $promptSave = document.getElementById('prompt-save-btn');

  if ($promptBtn) {
    $promptBtn.addEventListener('click', async function() {
      // Show modal
      var modal = bootstrap.Modal.getInstance($promptModal) || new bootstrap.Modal($promptModal);
      $promptBase.value = '加载中…';
      $promptCustom.value = '';
      modal.show();

      // Load current prompt
      try {
        var resp = await fetch(PROXY_BASE + '?action=prompt', { headers: {} });
        var data = await resp.json();
        $promptBase.value = data.base_prompt || '';
        $promptCustom.value = data.custom_prompt || '';
        updatePromptPreview();
      } catch(e) {
        $promptBase.value = '加载失败: ' + e.message;
      }
    });
  }

  if ($promptCustom) {
    $promptCustom.addEventListener('input', updatePromptPreview);
  }

  function updatePromptPreview() {
    var base = $promptBase.value;
    var custom = $promptCustom.value;
    $promptPreview.value = base + (custom ? '\n\n# 企业自定义指令\n' + custom : '');
  }

  if ($promptSave) {
    $promptSave.addEventListener('click', async function() {
      $promptSave.disabled = true;
      $promptSave.innerHTML = '<i class="ti ti-loader ti-spin me-1"></i>保存中…';
      try {
        var resp = await fetch(PROXY_BASE + '?action=prompt', {
          method: 'PUT',
          headers: {
            'Content-Type': 'application/json',
            'X-Glpi-Csrf-Token': AdManager.getCsrfToken(),
          },
          body: JSON.stringify({ custom_prompt: $promptCustom.value }),
        });
        var data = await resp.json();
        if (data.ok) {
          bootstrap.Modal.getInstance($promptModal).hide();
        } else {
          alert('保存失败: ' + (data.error || ''));
        }
      } catch(e) {
        alert('保存错误: ' + e.message);
      } finally {
        $promptSave.disabled = false;
        $promptSave.innerHTML = '<i class="ti ti-device-floppy me-1"></i>保存';
      }
    });
  }

  // ── 初始化 ──
  loadFiles();

  // 点击其他区域关闭 @ 补全
  document.addEventListener('click', e => {
    if (!$mentions.contains(e.target) && e.target !== $input) {
      hideMentions();
    }
  });

  console.log('[Agent Chat] Initialized, proxy:', PROXY_BASE);
})();
