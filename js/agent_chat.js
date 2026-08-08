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
  function getCsrfToken() {
    const el = document.getElementById('agent-csrf');
    return el ? el.value : '';
  }

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
  async function sendMessage() {
    const message = $input.value.trim();
    if (!message || isStreaming) return;

    // 显示用户消息
    addMessage('user', message);
    chatHistory.push({ role: 'user', content: message });

    // 清空输入
    $input.value = '';
    $input.style.height = 'auto';
    updateReferencesDisplay();

    // 显示打字指示器
    addTypingIndicator();
    isStreaming = true;
    setStatus('思考中…', 'streaming');
    $sendBtn.disabled = true;

    abortController = new AbortController();

    try {
      // GLPI 覆写了全局 fetch，对非 JSON 响应（SSE）会返回假 Response 对象（无 text()/body）。
      // 改用 XMLHttpRequest 处理 SSE 流式响应。
      const sseData = await new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', PROXY_BASE + '?action=chat');
        xhr.setRequestHeader('Content-Type', 'application/json');
        xhr.setRequestHeader('X-Glpi-Csrf-Token', getCsrfToken());

        let lastIndex = 0;
        let sseBuffer = '';        // 缓存跨 onprogress 的不完整 SSE 行
        let resolved = false;

        // 移除打字指示器，创建 assistant 消息
        removeTypingIndicator();
        const bubble = addMessage('assistant', '');
        let fullContent = '';
        let streamCursor = document.createElement('span');
        streamCursor.className = 'stream-cursor';
        bubble.appendChild(streamCursor);

        // 处理单条完整 SSE 行（以 data: 开头）。任意被网络分片的行先进入
        // sseBuffer，等后续 onprogress 补齐后再整行解析，避免大段内容（如
        // 最终报告）被截断后前半段 JSON 解析失败、后半段不以 "data: " 开头
        // 而被整体丢弃——这正是"长对话最终报告不显示、但日志里有"的根因。
        function handleSSELine(line) {
          if (!line.startsWith('data: ')) return;
          const dataStr = line.slice(6).trim();
          if (!dataStr) return;

          try {
            const evt = JSON.parse(dataStr);

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
            else if (evt.type === 'done') {
              if (streamCursor.parentNode) streamCursor.remove();
              if (fullContent) {
                bubble.innerHTML = formatContent(fullContent);
              }
              resolved = true;
              resolve(fullContent);
            }
            else if (evt.type === 'error') {
              if (streamCursor.parentNode) streamCursor.remove();
              bubble.innerHTML += '<p class="text-danger">⚠ ' + escapeHtml(evt.content) + '</p>';
            }
            // 兜底：未知类型但携带文本内容（如后端新增的 report/message），
            // 直接并入对话，避免最终报告在日志里有、对话框里却消失
            else if (evt.content || evt.message || evt.text) {
              fullContent += (evt.content || evt.message || evt.text);
              bubble.innerHTML = formatContent(fullContent);
              bubble.appendChild(streamCursor);
              scrollToBottom();
            }
          } catch (e) {
            console.warn('SSE parse error:', e, dataStr);
          }
        }

        // 累积文本，仅在遇到完整换行时才解析对应行；剩余不完整的行留在
        // sseBuffer 中等下一次 onprogress 补齐。
        function feedSSE(text) {
          sseBuffer += text;
          let nl;
          while ((nl = sseBuffer.indexOf('\n')) !== -1) {
            const rawLine = sseBuffer.slice(0, nl);
            sseBuffer = sseBuffer.slice(nl + 1);
            handleSSELine(rawLine);
          }
        }

        xhr.onprogress = function() {
          const newText = xhr.responseText.substring(lastIndex);
          lastIndex = xhr.responseText.length;
          if (newText) feedSSE(newText);
        };

        xhr.onload = function() {
          // 冲刷可能残留的最后一行（流结束但未带换行符时）
          if (sseBuffer.length) {
            handleSSELine(sseBuffer);
            sseBuffer = '';
          }
          if (!resolved) {
            resolve(fullContent);
          }
        };

        xhr.onerror = function() {
          removeTypingIndicator();
          addMessage('assistant', '❌ 网络错误: ' + (xhr.statusText || '连接失败'));
          setStatus('错误', 'error');
          resolve(null);
        };

        xhr.ontimeout = function() {
          if (!resolved) {
            resolve(fullContent || null);
          }
        };

        xhr.timeout = 300000; // 5 分钟超时
        xhr.send(JSON.stringify({
          message: message,
          history: chatHistory.slice(-10),
          references: references.map(r => ({ type: r.type, id: r.id, name: r.name })),
        }));

        // 保存 abortController 引用以便取消
        abortController._xhr = xhr;
      });

      // 保存到历史
      if (sseData) {
        chatHistory.push({ role: 'assistant', content: sseData });
      }
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
      abortController = null;
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
            'X-Glpi-Csrf-Token': getCsrfToken(),
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

      const dateStr = new Date(f.modified).toLocaleString('zh-CN', {
        month: '2-digit', day: '2-digit',
        hour: '2-digit', minute: '2-digit',
      });

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
              'X-Glpi-Csrf-Token': getCsrfToken(),
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
          'X-Glpi-Csrf-Token': getCsrfToken(),
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
            'X-Glpi-Csrf-Token': getCsrfToken(),
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
