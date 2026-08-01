/* js/vuln.js — 漏洞修复前端交互（导入/任务/规则） */
(function () {
  'use strict';

  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  function csrf() { return ($('#vuln-csrf') || {}).value || ''; }

  function api(params, method = 'GET') {
    let url = '/plugins/admanager/ajax/vuln_data.php';
    const opt = { method, headers: { 'Accept': 'application/json' } };
    if (method === 'GET') {
      url += '?' + new URLSearchParams(params).toString();
    } else {
      const body = new URLSearchParams();
      body.append('_glpi_csrf_token', csrf());
      for (const [k, v] of Object.entries(params)) {
        if (Array.isArray(v)) v.forEach(x => body.append(k, x));
        else body.append(k, v);
      }
      opt.headers['Content-Type'] = 'application/x-www-form-urlencoded';
      opt.headers['X-Glpi-Csrf-Token'] = csrf();  // 全局 CSRF 校验（inc/includes.php AJAX 分支用 header 取值）
      opt.body = body.toString();
    }
    return fetch(url, opt).then(r => r.json()).then(d => {
        // 每次响应都带回新的一次性 CSRF token，刷新页面级 #vuln-csrf（GLPI 合规做法）
        if (d && d._csrf) {
            const el = document.getElementById('vuln-csrf');
            if (el) el.value = d._csrf;
        }
        return d;
    });
  }
  const get = (p) => api(p, 'GET');
  const post = (p) => api(p, 'POST');

  function riskBadge(r) {
    const m = { low: 'bg-success', medium: 'bg-warning text-dark', high: 'bg-danger' };
    const t = { low: '低', medium: '中', high: '高' };
    return `<span class="badge ${m[r] || 'bg-secondary'}">${t[r] || r}</span>`;
  }
  function statusBadge(s) {
    const m = { pending: 'bg-secondary', approved: 'bg-success', rejected: 'bg-danger',
                needs_manual: 'bg-info text-dark', dispatched: 'bg-primary',
                done: 'bg-success', failed: 'bg-danger',
                pending_verify: 'bg-warning text-dark', rollback_required: 'bg-danger' };
    const t = { pending: '待审批', approved: '已批准', rejected: '已拒绝',
                needs_manual: '已手动处理', dispatched: '已下发',
                done: '执行成功', failed: '执行失败',
                pending_verify: '待后校验', rollback_required: '需回滚' };
    return `<span class="badge ${m[s] || 'bg-secondary'}">${t[s] || s}</span>`;
  }
  function ruleStatusBadge(s) {
    const m = { active: 'bg-success', draft: 'bg-warning text-dark', disabled: 'bg-secondary' };
    const t = { active: '生效', draft: '草稿', disabled: '停用' };
    return `<span class="badge ${m[s] || 'bg-secondary'}">${t[s] || s}</span>`;
  }
  function fixLabel(t) {
    return ({
      registry_fix: '注册表修复', software_upgrade: '软件升级', software_uninstall: '软件卸载',
      patch_install: '补丁安装', manual_review: '人工处理', unsupported: '暂不支持'
    })[t] || t;
  }
  function esc(s) {
    return (s == null ? '' : String(s))
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }
  function toast(msg, ok = true) {
    // 简单提示：复用 GLPI 的 messageAfterRedirect 风格（此处用 alert 兜底）
    if (window.GLPI && GLPI.renderMessages) {
      GLPI.renderMessages(ok ? ['ok'] : ['error'], [msg], true);
    } else {
      alert((ok ? '✅ ' : '❌ ') + msg);
    }
  }

  // ── 弹窗无障碍修复 ──
  // Bootstrap 关闭弹窗时会先给 .modal 设 aria-hidden=true，再归还焦点；
  // 若关闭瞬间焦点还在弹窗内的按钮上，Chromium 会报
  // "Blocked aria-hidden on an element because its descendant retained focus"。
  // 在 hide.bs.modal（早于 Bootstrap 设 aria-hidden）把焦点移出弹窗即可消除警告。
  function initModalA11y() {
    $$('.modal').forEach(m => {
      m.addEventListener('hide.bs.modal', () => {
        const a = document.activeElement;
        if (a && m.contains(a)) a.blur();
      });
    });
  }

  // ── 客户端表格搜索（QID规则库 / 待处理任务 / 导入记录）──
  function initTableSearch(inputSel, tableSel) {
    const input = $(inputSel);
    const table = $(tableSel);
    if (!input || !table) return;
    input.addEventListener('input', () => {
      const q = input.value.trim().toLowerCase();
      $$('tbody tr', table).forEach(tr => {
        if (tr.dataset.id === undefined) return;  // 跳过「暂无数据」空行
        const hit = !q || tr.textContent.toLowerCase().includes(q);
        tr.style.display = hit ? '' : 'none';
      });
    });
  }

  // ── 导入记录页 ──
  function initImportPage() {
    const rows = $$('#imports-table tbody tr[data-id]');
    if (!rows.length) return;

    function poll() {
      let pending = false;
      rows.forEach(tr => {
        const id = tr.dataset.id;
        const st = tr.dataset.status;
        if (st === 'pending' || st === 'parsing') {
          pending = true;
          get({ action: 'import_stats', id }).then(d => {
            if (!d || d.error) return;
            tr.dataset.status = d.status || st;
            const stCell = $('.cell-status', tr);
            const prCell = $('.cell-progress', tr);
            const mrCell = $('.cell-match', tr);
            if (stCell) stCell.innerHTML = statusBadge(d.status);
            // 失败时把 error_message 放在 title 里方便排查
            if (d.status === "failed" && d.error_message) {
                if (stCell) stCell.firstElementChild.title = d.error_message;
                tr.title = d.error_message;
            }
            if (prCell) prCell.textContent = `${d.processed_count || 0}/${d.row_count || 0}`;
            if (mrCell) mrCell.textContent = ((d.match_rate || 0) * 100).toFixed(1) + '%';
            const btn = $('.btn-view', tr);
            if (btn) btn.disabled = (d.status !== 'completed');
          }).catch(() => {});
        }
      });
      if (pending) setTimeout(poll, 3000);
    }
    poll();

    // 查看明细
    $$('.btn-view').forEach(btn => {
      btn.addEventListener('click', () => {
        const id = btn.dataset.id;
        const body = $('#findings-body');
        if (body) body.innerHTML = '<tr><td colspan="6" class="text-center text-muted">加载中…</td></tr>';
        const md = new bootstrap.Modal($('#findingsModal'));
        md.show();
        Promise.all([
          get({ action: 'findings', id, match: '' }),
        ]).then(([list]) => {
          if (!Array.isArray(list)) { body.innerHTML = '<tr><td colspan="6" class="text-danger">加载失败</td></tr>'; return; }
          if (!list.length) { body.innerHTML = '<tr><td colspan="6" class="text-center text-muted">无数据</td></tr>'; return; }
          body.innerHTML = list.map(f => `<tr class="${f.match_confidence === 'unmatched' ? 'table-warning' : ''}">
            <td>${esc(f.qid)}</td>
            <td>${esc(f.title)}</td>
            <td>${esc(f.ip)}</td>
            <td>${esc(f.dns_name)}</td>
            <td>${esc(f.asset_hostname || '—')}</td>
            <td><span class="badge bg-info text-dark">${esc(f.match_confidence)}</span></td>
          </tr>`).join('');
        }).catch(() => { body.innerHTML = '<tr><td colspan="6" class="text-danger">加载失败</td></tr>'; });
      });
    });

    // 重新解析（使用当前 AI 网关/提示配置重跑；覆盖旧任务）
    $$('.btn-reparse').forEach(btn => {
      btn.addEventListener('click', () => {
        const id = btn.dataset.id;
        const tr = btn.closest('tr');
        if (!confirm(`确认重新解析批次 #${id}？将使用当前 AI 网关配置重新生成任务（原任务会被覆盖）。`)) return;
        btn.disabled = true;
        post({ action: 'reparse_import', import_id: id }).then(r => {
          if (r && r.ok) {
            tr.dataset.status = 'parsing';
            const stCell = $('.cell-status', tr);
            const prCell = $('.cell-progress', tr);
            const mrCell = $('.cell-match', tr);
            if (stCell) stCell.innerHTML = statusBadge('parsing');
            if (prCell) prCell.textContent = '0/0';
            if (mrCell) mrCell.textContent = '—';
            const vbtn = $('.btn-view', tr);
            if (vbtn) vbtn.disabled = true;
            toast(r.message);
            poll();  // 重新进入轮询
          } else {
            btn.disabled = false;
            toast((r && r.message) || '重新解析失败', false);
          }
        }).catch(() => { btn.disabled = false; toast('网络错误', false); });
      });
    });
  }

  // ── 待处理任务页 ──
  const EXEC_FIX_TYPES = ['registry_fix', 'software_uninstall', 'software_upgrade', 'patch_install'];

  function initTasksPage() {
    if (!$('#tasks-table')) return;

    function onTaskAct(btn) {
      const tr = btn.closest('tr');
      const id = tr.dataset.id;
      const action = btn.dataset.action; // approve|reject|mark_manual|dispatch|rematch|delete_task
      if (action === 'dispatch' && !confirm(`确认将任务 #${id} 下发到客户端执行？`)) return;
      if (action === 'rematch' && !confirm(`确认对任务 #${id} 重新匹配软件安装包？`)) return;
      if (action === 'delete_task') {
        if (!confirm(`确认删除任务 #${id}？删除后不可恢复（用于清理重复任务）。`)) return;
        btn.disabled = true;
        post({ action, task_id: id }).then(r => {
          if (r && r.ok) { tr.remove(); toast(r.message); }
          else { btn.disabled = false; toast((r && r.message) || '删除失败', false); }
        }).catch(() => { btn.disabled = false; toast('网络错误', false); });
        return;
      }
      post({ action, task_id: id }).then(r => {
        if (r && r.ok) {
          if (action === 'rematch') {
            // 重新匹配：成功则补「确认下发」，失败则保留「重新匹配」并提示
            const cell = tr.querySelector('td.text-nowrap');
            const warn = $('.rematch-warn', tr);
            if (warn) warn.remove();
            if (r.task && r.task.matched_package_id) {
              tr.dataset.matched = r.task.matched_package_id;
              $$('.task-act', tr).forEach(b => b.remove());
              if (cell) cell.appendChild(makeDispatchBtn());
              toast('已匹配到安装包，可点「确认下发」执行升级');
            } else {
              toast((r.message || '仍未匹配到安装包，请先在软件部署库上传对应安装包'), false);
            }
          } else {
            rowAfterAction(tr, r.task || { status: action === 'approve' ? 'approved' : action === 'reject' ? 'rejected' : action === 'dispatch' ? 'dispatched' : 'needs_manual' });
            toast(r.message);
          }
        }
        else toast((r && r.message) || '操作失败', false);
      }).catch(() => toast('网络错误', false));
    }

    function makeDispatchBtn() {
      const btn = document.createElement('button');
      btn.className = 'btn btn-xs btn-primary task-act';
      btn.dataset.action = 'dispatch';
      btn.title = '确认下发执行';
      btn.innerHTML = '<i class="ti ti-send"></i>';
      return btn;
    }

    function makeRematchBtn() {
      const btn = document.createElement('button');
      btn.className = 'btn btn-xs btn-warning task-act';
      btn.dataset.action = 'rematch';
      btn.title = '重新匹配软件安装包';
      btn.innerHTML = '<i class="ti ti-refresh"></i>';
      return btn;
    }

    // 批准（或被门禁拦下停在 approved）时，按类型渲染可执行动作
    function renderApprovedActions(tr, task, blockReason) {
      const cell = tr.querySelector('td.text-nowrap');
      if (!cell) return;
      $$('.task-act', tr).forEach(b => b.remove());
      $('.rematch-warn', tr)?.remove();
      $('.block-reason', tr)?.remove();
      $('.task-manual-match', tr)?.remove();

      if (!EXEC_FIX_TYPES.includes(task.fix_type)) {
        // 非可执行类型 → 展示原因
        if (blockReason) {
          const w = document.createElement('div');
          w.className = 'block-reason small text-warning mt-1';
          w.textContent = blockReason;
          cell.appendChild(w);
        }
        return;
      }
      // 软件升级未匹配到安装包：展示「重新匹配」入口（不再提示跳转文案）
      if (task.fix_type === 'software_upgrade' && !task.matched_package_id) {
        cell.appendChild(makeRematchBtn());
        return;
      }
      // 门禁拦下（有 blockReason）→ 展示原因，但仍给出人工确认下发的入口
      if (blockReason) {
        const w = document.createElement('div');
        w.className = 'block-reason small text-warning mt-1';
        w.textContent = '未自动下发：' + blockReason + ' — 可点击下方按钮确认下发';
        cell.appendChild(w);
      }
      cell.appendChild(makeDispatchBtn());
    }

    function rowAfterAction(tr, task) {
      const st = task.status;
      $('.cell-status', tr).innerHTML = statusBadge(st);
      const cb = $('.task-check', tr);
      if (cb) { cb.checked = false; cb.disabled = true; }
      if (st === 'approved' || st === 'dispatched' || st === 'done') tr.classList.remove('table-danger');
      // 批准但被门禁拦下（仍为 approved）且属可执行类型 → 动态补「确认下发」/「重新匹配」
      if (st === 'approved') renderApprovedActions(tr, task, task.dispatch_block_reason || '');
    }

    // 事件委托：覆盖静态按钮 + 动态生成按钮（重新匹配/确认下发）+ 删除按钮
    const tasksTable = $('#tasks-table');
    if (tasksTable) {
      tasksTable.addEventListener('click', function (e) {
        const btn = e.target.closest('.task-act, .task-delete');
        if (!btn || !tasksTable.contains(btn)) return;
        onTaskAct(btn);
      });
    }

    const batchBtn = $('#batch-approve');
    if (batchBtn) {
      batchBtn.addEventListener('click', () => {
        const ids = $$('.task-check:checked').map(c => c.value);
        if (!ids.length) { toast('请先勾选任务', false); return; }
        if (!confirm(`确认批量批准 ${ids.length} 个任务？\n（注册表修复/软件卸载类将自动下发到客户端执行）`)) return;
        post({ action: 'batch_approve', 'task_ids[]': ids }).then(r => {
          if (r && r.ok) {
            const dispatched = new Set(((r.result && r.result.dispatched) || []).map(String));
            $$('.task-check:checked').forEach(c => {
              const tr = c.closest('tr');
              const isDispatched = dispatched.has(String(c.value));
              // 已自动下发的显示「已下发」，其余显示「已批准」
              $('.cell-status', tr).innerHTML = statusBadge(isDispatched ? 'dispatched' : 'approved');
              c.checked = false; c.disabled = true;
              // 被门禁拦下（approved）且可执行类型 → 补「确认下发」或「重新匹配」
              if (!isDispatched) {
                // 查该任务的 held reason
                const held = ((r.result && r.result.held) || []).find(h => String(h.id) === String(c.value));
                const blockReason = held ? held.reason : '';
                const miniTask = {
                  fix_type: tr.dataset.fix,
                  matched_package_id: tr.dataset.matched ? Number(tr.dataset.matched) : null,
                };
                renderApprovedActions(tr, miniTask, blockReason);
              }
            });
            // held 原因逐条打到控制台，便于排查
            ((r.result && r.result.held) || []).forEach(h => console.warn('[Vuln] held #' + h.id + ': ' + h.reason));
            toast(r.message || `已批准 ${ids.length} 个任务`);
          } else toast((r && r.message) || '批量批准失败', false);
        }).catch(() => toast('网络错误', false));
      });
    }

    // ── 轻量可输入下拉（combobox）：保留原生 select.value 语义，叠加输入过滤 + 无障碍 ──
    function makeCombobox(select) {
      if (!select || select._cmb) return select._cmb;
      select.style.display = 'none';
      var listId = select.id ? select.id + '_listbox' : '';
      var wrap = document.createElement('div'); wrap.className = 'cmb';
      wrap.setAttribute('role', 'combobox');
      wrap.setAttribute('aria-expanded', 'false');
      wrap.setAttribute('aria-haspopup', 'listbox');
      if (listId) wrap.setAttribute('aria-owns', listId);

      var input = document.createElement('input');
      input.type = 'text'; input.autocomplete = 'off';
      input.className = 'form-control form-control-sm cmb-input';
      input.placeholder = '输入关键字搜索…';
      input.setAttribute('role', 'combobox');
      input.setAttribute('aria-autocomplete', 'list');
      input.setAttribute('aria-expanded', 'false');
      if (listId) input.setAttribute('aria-controls', listId);

      var list = document.createElement('div'); list.className = 'cmb-list'; list.style.display = 'none';
      list.setAttribute('role', 'listbox');
      if (listId) list.id = listId;
      list.setAttribute('aria-label', select.getAttribute('aria-label') || '可搜索下拉选项');

      wrap.appendChild(input); wrap.appendChild(list);
      select.parentNode.insertBefore(wrap, select);

      var activeIdx = -1;
      var api = {
        input: input, list: list,
        sync: function () {
          var v = select.value, txt = '';
          for (var i = 0; i < select.options.length; i++) {
            if (select.options[i].value === v) { txt = select.options[i].text; break; }
          }
          if (!txt) txt = input.value; // 加载中等无匹配时保留已输入文本
          input.value = txt;
          input.setAttribute('aria-activedescendant', '');
        },
        render: function () {
          var q = (input.value || '').trim().toLowerCase(), html = '', n = 0;
          for (var i = 0; i < select.options.length; i++) {
            var o = select.options[i];
            if (!o.value) continue;
            if (q && o.text.toLowerCase().indexOf(q) < 0) continue;
            var optId = (select.id ? select.id + '_opt_' : 'cmb_opt_') + i;
            html += '<div class="cmb-opt" role="option" id="' + optId + '" data-val="' + esc(o.value) +
              '" aria-selected="false">' + esc(o.text) + '</div>';
            if (++n > 300) break;
          }
          list.innerHTML = html || '<div class="cmb-empty">无匹配</div>';
          activeIdx = -1;
          input.setAttribute('aria-activedescendant', '');
        },
        open: function () {
          api.render(); list.style.display = 'block';
          wrap.setAttribute('aria-expanded', 'true');
          input.setAttribute('aria-expanded', 'true');
        },
        close: function () {
          list.style.display = 'none';
          wrap.setAttribute('aria-expanded', 'false');
          input.setAttribute('aria-expanded', 'false');
        },
        move: function (dir) {
          var opts = list.querySelectorAll('.cmb-opt');
          if (!opts.length) return;
          activeIdx = activeIdx < 0 ? 0 : (activeIdx + dir + opts.length) % opts.length;
          for (var i = 0; i < opts.length; i++) {
            var on = i === activeIdx;
            opts[i].setAttribute('aria-selected', on ? 'true' : 'false');
            opts[i].classList.toggle('cmb-active', on);
          }
          var active = opts[activeIdx];
          if (active.scrollIntoView) active.scrollIntoView({ block: 'nearest' });
          input.setAttribute('aria-activedescendant', active.id);
        },
        choose: function (el) {
          if (!el) return;
          select.value = el.getAttribute('data-val');
          select.dispatchEvent(new Event('change'));
          input.value = el.textContent;
          api.close();
        }
      };
      input.addEventListener('focus', api.open);
      input.addEventListener('input', api.open);
      input.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown') { e.preventDefault(); api.move(1); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); api.move(-1); }
        else if (e.key === 'Enter') {
          var opts = list.querySelectorAll('.cmb-opt');
          if (opts.length && activeIdx >= 0) { e.preventDefault(); api.choose(opts[activeIdx]); }
        }
        else if (e.key === 'Escape') { api.close(); input.blur(); }
      });
      list.addEventListener('mousedown', function (e) {
        var t = e.target.closest('.cmb-opt'); if (!t) return;
        e.preventDefault();
        api.choose(t);
      });
      document.addEventListener('click', function (e) { if (!wrap.contains(e.target)) api.close(); });
      select._cmb = api;
      return api;
    }

    // ── 人工匹配安装包 + 匹配后自动下发 ──
    let manualMatchTaskId = null;
    let manualMatchTaskAsset = '';
    const manualModalEl = $('#manualMatchModal');
    const manualSel = $('#manualMatchPackageSelect');
    const manualAssetSel = $('#manualMatchAssetSelect');
    const manualConfirm = $('#manualMatchConfirm');
    const manualPkgCmb = makeCombobox(manualSel);
    const manualAssetCmb = makeCombobox(manualAssetSel);

    function refreshManualConfirm() {
      manualConfirm.disabled = !(manualSel.value && manualAssetSel.value);
    }

    // 用户选择安装包 / 目标计算机时实时刷新「确认匹配」可用状态（否则只在列表加载时判断一次，选了也不生效）
    manualSel.addEventListener('change', refreshManualConfirm);
    manualAssetSel.addEventListener('change', refreshManualConfirm);

    if (manualModalEl && manualSel && manualAssetSel && manualConfirm) {
      $$('.task-manual-match').forEach(btn => {
        btn.addEventListener('click', function () {
          manualMatchTaskId = this.getAttribute('data-task-id');
          manualMatchTaskAsset = this.getAttribute('data-asset-id') || '';
          manualSel.innerHTML = '<option value="">加载中…</option>';
          manualAssetSel.innerHTML = '<option value="">加载中…</option>';
          manualConfirm.disabled = true;
          bootstrap.Modal.getOrCreateInstance(manualModalEl).show();
          // 并行加载安装包与客户端列表
          get({ action: 'packages' }).then(data => {
            if (!Array.isArray(data) || data.length === 0) {
              manualSel.innerHTML = '<option value="">软件部署库暂无安装包</option>';
              return;
            }
            manualSel.innerHTML = '<option value="">— 选择安装包 —</option>' +
              data.map(p => `<option value="${p.id}">${esc(p.name)} ${esc(p.version || '')} (#${p.id})</option>`).join('');
            manualPkgCmb.sync();
            refreshManualConfirm();
          }).catch(() => { manualSel.innerHTML = '<option value="">加载失败</option>'; });
          get({ action: 'clients' }).then(clients => {
            const list = Array.isArray(clients) ? clients : [];
            let opts = '';
            // 当前资产即便不在列表中也保留，避免已关联任务被清空
            if (manualMatchTaskAsset && !list.some(c => String(c.id) === String(manualMatchTaskAsset))) {
              opts += `<option value="${manualMatchTaskAsset}">当前计算机 (#${manualMatchTaskAsset})</option>`;
            }
            opts += list.map(c => `<option value="${c.id}">${esc(c.hostname || c.name || c.id)} (#${c.id})</option>`).join('');
            manualAssetSel.innerHTML = opts
              ? '<option value="">— 选择目标计算机 —</option>' + opts
              : '<option value="">暂无可用客户端</option>';
            manualAssetSel.value = manualMatchTaskAsset || '';
            manualAssetCmb.sync();
            refreshManualConfirm();
          }).catch(() => { manualAssetSel.innerHTML = '<option value="">加载失败</option>'; });
        });
      });
      manualConfirm.addEventListener('click', function () {
        const pkgId = manualSel.value;
        const assetId = manualAssetSel.value;
        if (!pkgId || !assetId || !manualMatchTaskId) {
          toast('请先选择安装包与目标计算机', false);
          return;
        }
        const taskId = manualMatchTaskId;
        this.disabled = true;
        post({ action: 'manual_match', task_id: taskId, package_id: pkgId, asset_id: assetId }).then(r => {
          if (!r || !r.ok) {
            this.disabled = false;
            toast((r && r.message) || '人工匹配失败', false);
            return;
          }
          const matchedId = (r.task && r.task.matched_package_id) || Number(pkgId);
          // 匹配成功 → 自动尝试下发（此时资产已写入，门禁应通过）
          post({ action: 'dispatch', task_id: taskId }).then(d => {
            const tr = document.querySelector(`tr[data-id="${taskId}"]`);
            bootstrap.Modal.getOrCreateInstance(manualModalEl).hide();
            if (d && d.ok) {
              // 清理残留的匹配类按钮，避免与「已下发」状态并存
              $$('.task-act', tr).forEach(b => b.remove());
              $('.task-manual-match', tr)?.remove();
              $('.rematch-warn', tr)?.remove();
              if (tr) rowAfterAction(tr, d.task || { status: 'dispatched' });
              toast('已匹配安装包并下发执行');
            } else {
              const reason = ((d && d.task && d.task.dispatch_block_reason) || (d && d.message) || '未自动下发，请手动确认下发');
              if (tr) renderApprovedActions(tr, { fix_type: 'software_upgrade', matched_package_id: matchedId, asset_id: Number(assetId) }, reason);
              toast('已匹配安装包，但未自动下发：' + reason, false);
            }
          }).catch(() => {
            this.disabled = false;
            bootstrap.Modal.getOrCreateInstance(manualModalEl).hide();
            toast('下发请求失败', false);
          });
        }).catch(() => { this.disabled = false; toast('人工匹配请求失败', false); });
      });
    }

    // 详情
    $$('.btn-detail').forEach(btn => {
      btn.addEventListener('click', () => {
        const id = btn.dataset.id;
        const body = $('#task-detail-body');
        body.innerHTML = '加载中…';
        new bootstrap.Modal($('#taskModal')).show();
        get({ action: 'task_detail', id }).then(d => {
          if (!d || d.error) { body.innerHTML = '<span class="text-danger">加载失败</span>'; return; }
          const aj = d.action_json || {};
          let html = `<dl class="row small mb-0">
            <dt class="col-4">任务ID</dt><dd class="col-8">${esc(d.id)}</dd>
            <dt class="col-4">QID</dt><dd class="col-8">${esc(d.qid)}</dd>
            <dt class="col-4">标题</dt><dd class="col-8">${esc(d.title)}</dd>
            <dt class="col-4">资产</dt><dd class="col-8">${esc(d.asset_hostname || (d.ip || d.dns_name) || '未匹配')}</dd>
            <dt class="col-4">修复类型</dt><dd class="col-8">${fixLabel(d.fix_type)}</dd>
            <dt class="col-4">风险</dt><dd class="col-8">${riskBadge(d.risk_level)}</dd>
            <dt class="col-4">状态</dt><dd class="col-8">${statusBadge(d.status)}</dd>
            ${(d.verify_max_attempts != null) ? `<dt class="col-4">验证进度</dt><dd class="col-8">${esc(d.verify_attempts||0)} / ${esc(d.verify_max_attempts)}（未达标自动重下发，达上限转人工）</dd>` : ''}
            ${(d.matched_package_name) ? `<dt class="col-4">匹配安装包</dt><dd class="col-8">${esc(d.matched_package_name)}（包ID ${esc(d.matched_package_id)}）</dd>` : ''}
            ${(d.needs_reboot) ? `<dt class="col-4">重启状态</dt><dd class="col-8"><span class="badge bg-warning text-dark">已完成（待重启生效）</span></dd>` : ''}
            <dt class="col-4">建议摘要</dt><dd class="col-8">${esc(d.action_summary || '')}</dd>
            <dt class="col-4">溯源</dt><dd class="col-8"><code>${esc((aj._source) || '')}</code></dd>
            ${d.result_log ? `<dt class="col-4">执行结果</dt><dd class="col-8"><pre class="small bg-light p-2 mb-0">${esc(d.result_log)}</pre></dd>` : ''}
          </dl>
          <div class="mt-2"><strong>原始 Results：</strong><pre class="small bg-light p-2">${esc(d.results_raw || '')}</pre></div>
          <div><strong>Solution：</strong><pre class="small bg-light p-2">${esc(d.solution_raw || '')}</pre></div>`;
          body.innerHTML = html;
        }).catch(() => { body.innerHTML = '<span class="text-danger">加载失败</span>'; });
      });
    });
  }

  // ── 声明式验证判定条件 builder（规则编辑器用）──
  function renderVerifyParams(sel, data) {
    data = data || {};
    const type = sel.value;
    const row = sel.closest('.verify-row');
    const box = row.querySelector('.verify-params');
    let html = '';
    if (type === 'file_not_exists' || type === 'file_exists') {
      html = `<input name="v_path" class="form-control form-control-sm" placeholder="文件路径（如 %systemdrive%\\Users\\x\\file.exe）" value="${esc(data.path || '')}">`;
    } else if (type === 'registry_equals') {
      html = `<input name="v_hive" class="form-control form-control-sm" style="width:90px" placeholder="HKLM" value="${esc(data.hive || 'HKLM')}">`
           + `<input name="v_path" class="form-control form-control-sm" placeholder="注册表路径" value="${esc(data.path || '')}">`
           + `<input name="v_name" class="form-control form-control-sm" placeholder="值名称" value="${esc(data.name || '')}">`
           + `<input name="v_value" class="form-control form-control-sm" placeholder="期望值" value="${esc(data.value || '')}">`;
    } else if (type === 'registry_not_exists') {
      html = `<input name="v_hive" class="form-control form-control-sm" style="width:90px" placeholder="HKLM" value="${esc(data.hive || 'HKLM')}">`
           + `<input name="v_path" class="form-control form-control-sm" placeholder="注册表路径" value="${esc(data.path || '')}">`
           + `<input name="v_name" class="form-control form-control-sm" placeholder="值名称" value="${esc(data.name || '')}">`;
    } else if (type === 'service_stopped' || type === 'service_running') {
      html = `<input name="v_name" class="form-control form-control-sm" placeholder="服务名（如 Spooler）" value="${esc(data.name || '')}">`;
    } else if (type === 'command') {
      html = `<input name="v_cmd" class="form-control form-control-sm" placeholder="返回 0=通过，非0=未通过" value="${esc(data.cmd || '')}">`;
    }
    box.innerHTML = html;
  }

  function addVerifyRow(prefix, data) {
    data = data || {};
    const container = document.getElementById('verify-rows-' + prefix);
    if (!container) return;
    const row = document.createElement('div');
    row.className = 'verify-row border rounded p-2 mb-2 small';
    row.innerHTML = `
      <div class="d-flex gap-2 flex-wrap align-items-end">
        <select name="v_type" class="form-select form-select-sm" style="width:auto" onchange="renderVerifyParams(this)">
          <option value="file_not_exists">文件不存在</option>
          <option value="file_exists">文件存在</option>
          <option value="registry_equals">注册表值等于</option>
          <option value="registry_not_exists">注册表值不存在</option>
          <option value="service_stopped">服务已停止</option>
          <option value="service_running">服务运行中</option>
          <option value="command">自定义命令</option>
        </select>
        <div class="verify-params flex-grow-1 d-flex gap-1 flex-wrap"></div>
        <button type="button" class="btn btn-xs btn-outline-danger" title="删除该判定" onclick="this.closest('.verify-row').remove()"><i class="ti ti-trash"></i></button>
      </div>`;
    container.appendChild(row);
    const sel = row.querySelector('[name="v_type"]');
    if (data.type) sel.value = data.type;
    renderVerifyParams(sel, data);
  }

  function collectVerify(prefix) {
    const container = document.getElementById('verify-rows-' + prefix);
    if (!container) return [];
    const out = [];
    $$('.verify-row', container).forEach(row => {
      const type = row.querySelector('[name="v_type"]').value;
      const chk = { type };
      let ok = true;
      if (type === 'file_not_exists' || type === 'file_exists') {
        const p = (row.querySelector('[name="v_path"]').value || '').trim();
        if (!p) ok = false; else chk.path = p;
      } else if (type === 'registry_equals' || type === 'registry_not_exists') {
        const hive = (row.querySelector('[name="v_hive"]').value || '').trim() || 'HKLM';
        const path = (row.querySelector('[name="v_path"]').value || '').trim();
        const name = (row.querySelector('[name="v_name"]').value || '').trim();
        if (!path || !name) ok = false;
        else { chk.hive = hive; chk.path = path; chk.name = name; if (type === 'registry_equals') chk.value = row.querySelector('[name="v_value"]').value; }
      } else if (type === 'service_stopped' || type === 'service_running') {
        const n = (row.querySelector('[name="v_name"]').value || '').trim();
        if (!n) ok = false; else chk.name = n;
      } else if (type === 'command') {
        const c = (row.querySelector('[name="v_cmd"]').value || '').trim();
        if (!c) ok = false; else chk.cmd = c;
      } else ok = false;
      if (ok) out.push(chk);
    });
    return out;
  }

  // 把验证判定条件合并进 action_template（verify / verify_max_attempts）。
  // 原 action_template 为空且无验证条件时返回 ''（避免写入空 {}）。
  function mergeVerifyIntoAction(prefix, actionTemplateStr) {
    const hadInput = !!(actionTemplateStr && actionTemplateStr.trim());
    let atObj = null;
    if (hadInput) { try { atObj = JSON.parse(actionTemplateStr); } catch (e) { atObj = null; } }
    if (!atObj || typeof atObj !== 'object' || Array.isArray(atObj)) atObj = {};
    const verify = collectVerify(prefix);
    const maxEl = document.getElementById('verify_max_' + prefix);
    const maxA = maxEl ? parseInt(maxEl.value, 10) : 0;
    let changed = false;
    if (verify.length) { atObj.verify = verify; changed = true; }
    if (maxA >= 1) { atObj.verify_max_attempts = maxA; changed = true; }
    if (!hadInput && !changed) return '';
    return JSON.stringify(atObj);
  }

  // ── QID 规则库页 ──
  function initRulesPage() {
    if (!$('#rules-table')) return;

    // 创建
    const createForm = $('#rule-create-form');
    if (createForm) {
      createForm.addEventListener('submit', (e) => {
        e.preventDefault();
        const fd = new FormData(createForm);

        // registry_fix: 若未手动填写 action_template，从注册表字段拼接
        if (fd.get('fix_type') === 'registry_fix' && !fd.get('action_template')?.trim()) {
          const root   = createForm.querySelector('[name="reg_root"]')?.value?.trim() || 'HKLM';
          const subkey = createForm.querySelector('[name="reg_subkey"]')?.value?.trim() || '';
          const name   = createForm.querySelector('[name="reg_name"]')?.value?.trim() || '';
          const action = createForm.querySelector('[name="reg_action"]')?.value || 'set';
          const value  = createForm.querySelector('[name="reg_value"]')?.value || '';
          const type   = createForm.querySelector('[name="reg_type"]')?.value || 'string';
          if (subkey) {
            fd.set('action_template', JSON.stringify({
              changes: [{ action, hive: root, path: subkey, value: name, data: value, type }]
            }));
          }
        }

        // 合并验证判定条件到 action_template（verify / verify_max_attempts）
        fd.set('action_template', mergeVerifyIntoAction('create', fd.get('action_template') || ''));
        const params = { action: 'create_rule' };
        fd.forEach((v, k) => { if (k === '_glpi_csrf_token' || k.indexOf('v_') === 0) return; params[k] = v; });
        // base64 编码 JSON 字段，规避 GLPI Sanitizer 对引号的转义（后端 base64_decode 还原）
        ['action_template', 'rollback_plan'].forEach(k => {
          if (params[k] && String(params[k]).trim() !== '') {
            try { params[k] = btoa(unescape(encodeURIComponent(String(params[k])))); } catch (e) {}
          }
        });
        post(params).then(r => {
          if (r && r.ok) { toast(r.message); location.reload(); }
          else toast((r && r.message) || '创建失败', false);
        }).catch(() => toast('网络错误', false));
      });
    }

    // ── 创建表单 fix_type 切换 → show/hide 注册表字段 ──
    const createFixType = $('[name="fix_type"]', createForm);
    if (createFixType) {
      createFixType.addEventListener('change', () => {
        const show = createFixType.value === 'registry_fix';
        const el = $('#registry-fields-create');
        if (el) el.style.display = show ? '' : 'none';
      });
    }
    // 编辑弹窗 fix_type 切换
    const editFixType = $('#edit_fix_type');
    if (editFixType) {
      editFixType.addEventListener('change', () => {
        const show = editFixType.value === 'registry_fix';
        const el = $('#registry-fields-edit');
        if (el) el.style.display = show ? '' : 'none';
      });
    }

    // 编辑
    $$('.btn-edit-rule').forEach(btn => {
      btn.addEventListener('click', () => {
        const tr = btn.closest('tr');
        $('#edit_rule_id').value = tr.dataset.id;
        $('#edit_qid').value = tr.dataset.qid;
        $('#edit_fix_type').value = tr.dataset.fix;
        $('#edit_default_risk').value = tr.dataset.risk;
        $('#edit_status').value = tr.dataset.status;
        $('#edit_notes').value = tr.dataset.notes || '';
        $('#edit_rollback').value = tr.dataset.rollback || '';
        $('#edit_action_template').value = tr.dataset.action || '';
        // 预填验证判定条件（从 action_template 的 verify / verify_max_attempts 还原）
        const atRaw = tr.dataset.action || '';
        let atObj = null;
        if (atRaw.trim()) { try { atObj = JSON.parse(atRaw); } catch (e) { atObj = null; } }
        const vrows = document.getElementById('verify-rows-edit');
        if (vrows) {
          vrows.innerHTML = '';
          if (atObj && Array.isArray(atObj.verify)) atObj.verify.forEach(v => addVerifyRow('edit', v));
        }
        const maxEdit = document.getElementById('verify_max_edit');
        if (maxEdit) maxEdit.value = (atObj && atObj.verify_max_attempts) ? atObj.verify_max_attempts : 3;
        new bootstrap.Modal($('#editRuleModal')).show();
      });
    });
    const editForm = $('#rule-edit-form');
    if (editForm) {
      editForm.addEventListener('submit', (e) => {
        e.preventDefault();
        const fd = new FormData(editForm);

        // registry_fix: 若未手动填写 rollback_plan 但有注册表字段，自动拼接
        if (fd.get('fix_type') === 'registry_fix' && !fd.get('action_template')?.trim()) {
          const root   = editForm.querySelector('[name="reg_root"]')?.value?.trim() || 'HKLM';
          const subkey = editForm.querySelector('[name="reg_subkey"]')?.value?.trim() || '';
          const name   = editForm.querySelector('[name="reg_name"]')?.value?.trim() || '';
          const action = editForm.querySelector('[name="reg_action"]')?.value || 'set';
          const value  = editForm.querySelector('[name="reg_value"]')?.value || '';
          const type   = editForm.querySelector('[name="reg_type"]')?.value || 'string';
          if (subkey) {
            fd.set('action_template', JSON.stringify({
              changes: [{ action, hive: root, path: subkey, value: name, data: value, type }]
            }));
          }
        }

        // 合并验证判定条件到 action_template（verify / verify_max_attempts）
        fd.set('action_template', mergeVerifyIntoAction('edit', fd.get('action_template') || ''));
        const params = { action: 'update_rule' };
        fd.forEach((v, k) => { if (k === '_glpi_csrf_token' || k.indexOf('v_') === 0) return; params[k] = v; });
        // base64 编码 JSON 字段，规避 GLPI Sanitizer 对引号的转义
        ['action_template', 'rollback_plan'].forEach(k => {
          if (params[k] && String(params[k]).trim() !== '') {
            try { params[k] = btoa(unescape(encodeURIComponent(String(params[k])))); } catch (e) {}
          }
        });
        post(params).then(r => {
          if (r && r.ok) { toast(r.message); location.reload(); }
          else toast((r && r.message) || '更新失败', false);
        }).catch(() => toast('网络错误', false));
      });
    }

    // 删除
    $$('.btn-del-rule').forEach(btn => {
      btn.addEventListener('click', () => {
        const id = btn.dataset.id;
        if (!confirm('确认删除该规则？（QID ' + id + '）')) return;
        post({ action: 'delete_rule', rule_id: id }).then(r => {
          if (r && r.ok) { toast(r.message); location.reload(); }
          else toast((r && r.message) || '删除失败', false);
        }).catch(() => toast('网络错误', false));
      });
    });

    // ── 复制规则到指定 QID ──
    initCopyRule();
  }

  // 复制规则：选择源规则 + 勾选/填写目标 QID → UPSERT 应用
  function initCopyRule() {
    const btn = $('#btn-copy-rule');
    if (!btn) return;
    const modal = $('#copyRuleModal');
    const srcSel = $('#copy-source-rule');
    const srcInfo = $('#copy-source-info');
    const qidList = $('#copy-qid-list');
    const qidFilter = $('#copy-qid-filter');
    const qidExtra = $('#copy-qid-extra');
    const confirmBtn = $('#btn-copy-confirm');
    const rows = $$('#rules-table tbody tr').filter(tr => tr.dataset && tr.dataset.id);

    function cellText(tr, n) {
      const td = tr.querySelector('td:nth-child(' + n + ')');
      return td ? td.textContent.trim() : '';
    }
    function refreshSourceInfo() {
      const tr = rows.find(r => r.dataset.id === srcSel.value);
      srcInfo.textContent = tr
        ? ('类型：' + cellText(tr, 2) + '；动作：' + cellText(tr, 3))
        : '';
    }
    function buildSourceOptions() {
      srcSel.innerHTML = '';
      rows.forEach(tr => {
        const opt = document.createElement('option');
        opt.value = tr.dataset.id;
        opt.textContent = 'QID ' + tr.dataset.qid + ' · ' + cellText(tr, 2);
        srcSel.appendChild(opt);
      });
      refreshSourceInfo();
    }
    function renderQidList(filter) {
      const seen = new Set();
      let html = '';
      rows.forEach(tr => {
        const qid = tr.dataset.qid;
        if (filter && !qid.includes(filter)) return;
        if (seen.has(qid)) return;
        seen.add(qid);
        html += '<div class="form-check"><input class="form-check-input copy-qid-cb" type="checkbox" value="'
              + qid + '" id="cq_' + qid + '">'
              + '<label class="form-check-label small" for="cq_' + qid + '">' + qid + '</label></div>';
      });
      qidList.innerHTML = html || '<div class="text-muted small">无匹配 QID</div>';
    }
    btn.addEventListener('click', () => {
      buildSourceOptions();
      renderQidList('');
      qidExtra.value = '';
      qidFilter.value = '';
      new bootstrap.Modal(modal).show();
    });
    srcSel.addEventListener('change', refreshSourceInfo);
    qidFilter.addEventListener('input', () => renderQidList(qidFilter.value.trim()));

    confirmBtn.addEventListener('click', () => {
      const src = srcSel.value;
      if (!src) { toast('请选择源规则', false); return; }
      const qids = new Set();
      $$('.copy-qid-cb:checked', qidList).forEach(cb => qids.add(cb.value));
      qidExtra.value.split(/[\s,]+/).forEach(s => { s = s.trim(); if (s) qids.add(s); });
      if (qids.size === 0) { toast('请至少勾选或填写一个目标 QID', false); return; }
      confirmBtn.disabled = true;
      post({ action: 'copy_rule', source_rule_id: src, target_qids: Array.from(qids) })
        .then(r => {
          confirmBtn.disabled = false;
          if (r && r.ok) { toast(r.message); location.reload(); }
          else toast((r && r.message) || '复制失败', false);
        })
        .catch(() => { confirmBtn.disabled = false; toast('网络错误', false); });
    });
  }

  // ── 全局熔断开关 ──
  function initKillSwitch() {
    const card = $('#kill-switch-card');
    const statusEl = $('#kill-switch-status');
    const toggleBtn = $('#kill-switch-toggle');
    if (!card || !statusEl || !toggleBtn) return;

    function refresh() {
      get({ action: 'kill_switch_status' }).then(d => {
        const on = d && d.kill_switch;
        if (on) {
          statusEl.className = 'badge bg-danger';
          statusEl.textContent = '熔断中';
          toggleBtn.className = 'btn btn-sm btn-success';
          toggleBtn.innerHTML = '<i class="ti ti-shield-check me-1"></i>关闭熔断';
          card.classList.add('border-danger');
          var hdr = card.querySelector('.card-header'); if(hdr) hdr.style.background = 'rgba(226,75,74,0.1)';
        } else {
          statusEl.className = 'badge bg-success';
          statusEl.textContent = '正常';
          toggleBtn.className = 'btn btn-sm btn-outline-danger';
          toggleBtn.innerHTML = '<i class="ti ti-shield-off me-1"></i>开启熔断';
          card.classList.remove('border-danger');
          const h = card.querySelector('.card-header');
          if (h) { h.style.background = ''; }
        }
        toggleBtn.disabled = false;
      }).catch(() => {
        statusEl.textContent = '查询失败';
        toggleBtn.disabled = true;
      });
    }
    refresh();

    toggleBtn.addEventListener('click', () => {
      const cur = statusEl.textContent;
      const label = cur === '熔断中' ? '关闭熔断，恢复自动下发？' : '开启熔断，暂停所有自动下发？（已下发的任务不受影响，人工确认下发也不受影响）';
      if (!confirm(label)) return;
      toggleBtn.disabled = true;
      post({ action: 'kill_switch_toggle' }).then(r => {
        if (r && r.ok) {
          toast(r.message || '已切换');
          refresh();
        } else {
          toast((r && r.message) || '切换失败', false);
          toggleBtn.disabled = false;
        }
      }).catch(() => {
        toast('网络错误', false);
        toggleBtn.disabled = false;
      });
    });
  }

  document.addEventListener('DOMContentLoaded', () => {
    initModalA11y();
    initImportPage();
    initTasksPage();
    initRulesPage();
    initKillSwitch();
    // 客户端表格搜索（QID规则库 / 待处理任务 / 导入记录）
    initTableSearch('#import-search', '#imports-table');
    initTableSearch('#rules-search', '#rules-table');
    initTableSearch('#tasks-search', '#tasks-table');
  });
})();