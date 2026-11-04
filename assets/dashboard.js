// Activity & audit dashboard. One AG Grid, four live views:
// agent actions, wallet ledger, PayPal orders and matches.
(function () {
  'use strict';

  var body = document.body;
  var BASE = body.dataset.base || '';
  var NONCE = body.dataset.nonce || '';
  var $ = function (s) { return document.querySelector(s); };
  var $$ = function (s) { return Array.prototype.slice.call(document.querySelectorAll(s)); };

  if (agGrid.ModuleRegistry && agGrid.AllCommunityModule) {
    agGrid.ModuleRegistry.registerModules([agGrid.AllCommunityModule]);
  }

  var theme = agGrid.themeQuartz.withParams({
    browserColorScheme: 'dark',
    backgroundColor: '#0b1430',
    foregroundColor: '#f4f7ff',
    chromeBackgroundColor: '#0d1730',
    headerBackgroundColor: '#13234a',
    headerTextColor: '#F5C518',
    accentColor: '#F5C518',
    borderColor: 'rgba(255, 255, 255, 0.10)',
    rowHoverColor: 'rgba(245, 197, 24, 0.08)',
    oddRowBackgroundColor: 'rgba(255, 255, 255, 0.025)',
    selectedRowBackgroundColor: 'rgba(20, 184, 166, 0.18)',
    fontFamily: 'Nunito, system-ui, sans-serif',
    fontSize: 14,
    headerFontWeight: 800,
    wrapperBorderRadius: 18,
    spacing: 7
  });

  var state = { scope: 'me', tab: 'agent', data: null, seen: {}, timer: 0 };
  var grid;

  /* ------------------------------------------------------------ helpers */

  function fmtNum(n) { return n === null || n === undefined ? '' : Number(n).toLocaleString('en-US'); }
  function toDate(v) { return v ? new Date(String(v).replace(' ', 'T')) : null; }
  function fmtTime(p) { var d = toDate(p.value); return d ? d.toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' }) : ''; }
  function badge(text, cls) {
    var s = document.createElement('span');
    s.className = 'cell-badge ' + cls;
    s.textContent = text;
    return s;
  }
  function badgeFrom(map) {
    return function (p) {
      if (p.value === null || p.value === undefined) return '';
      if (p.node.rowPinned) return String(p.value);
      var m = map[p.value] || [String(p.value), 'muted'];
      return badge(m[0], m[1]);
    };
  }
  function signed(p) {
    if (p.value === null || p.value === undefined) return '';
    return (p.value > 0 ? '+' : p.value < 0 ? '−' : '') + fmtNum(Math.abs(p.value));
  }
  var signClass = { 'num-plus': function (p) { return p.value > 0; }, 'num-minus': function (p) { return p.value < 0; } };
  var dateCol = function (extra) {
    return Object.assign({
      field: 'at', headerName: 'Time', sort: 'desc', width: 175, valueFormatter: fmtTime,
      filter: 'agDateColumnFilter', filterValueGetter: function (p) { return toDate(p.data.at); },
      filterParams: { comparator: function (day, cell) {
        if (!cell) return -1;
        var c = new Date(cell.getFullYear(), cell.getMonth(), cell.getDate());
        return c < day ? -1 : c > day ? 1 : 0;
      } }
    }, extra || {});
  };
  var userCol = { field: 'user', headerName: 'Player', width: 130 };

  var KIND = { message: ['Chat', 'blue'], tool: ['Tool call', 'gold'], confirm: ['Player approved', 'teal'] };
  var RESULT = { true: ['✓ OK', 'green'], false: ['✕ Refused', 'red'] };
  var LEDGER = {
    topup: ['PayPal top-up', 'teal'], topup_fee: ['Top-up fee', 'gold'], bet_stake: ['Contest entry', 'blue'],
    payout: ['Payout', 'green'], platform_fee: ['Platform fee', 'gold'], refund: ['Refund', 'muted']
  };
  var ACCOUNT = { user: ['Player', 'blue'], escrow: ['Escrow', 'muted'], platform: ['Platform', 'gold'] };
  var SOURCE = { checkout: ['Checkout', 'blue'], mandate: ['🤖 Autopilot', 'teal'] };
  var STATUS = { completed: ['Completed', 'green'], created: ['Not paid', 'muted'], pending: ['Pending', 'gold'], failed: ['Failed', 'red'], cancelled: ['Cancelled', 'muted'] };
  var OUTCOME = { won: ['Won', 'green'], lost: ['Lost', 'red'], draw: ['Draw', 'muted'] };
  var HOW = { win: 'Three in a row', draw: 'Board full', timeout: 'Clock ran out', resign: 'Resigned' };

  function columns(tab, platform) {
    var cols;
    if (tab === 'agent') {
      cols = [
        dateCol(),
        platform ? userCol : null,
        { field: 'kind', headerName: 'Type', width: 150, cellRenderer: badgeFrom(KIND), filterValueGetter: function (p) { return (KIND[p.data.kind] || [p.data.kind])[0]; } },
        { field: 'action', headerName: 'Action', width: 190 },
        { field: 'details', headerName: 'What happened', flex: 2, minWidth: 320, tooltipField: 'details' },
        { field: 'ok', headerName: 'Result', width: 125, cellRenderer: badgeFrom(RESULT), filterValueGetter: function (p) { return p.data.ok ? 'OK' : 'Refused'; } },
        { field: 'usd', headerName: 'USD', width: 95, type: 'numericColumn', filter: 'agNumberColumnFilter', valueFormatter: function (p) { return p.value === null ? '' : '$' + p.value; } }
      ];
    } else if (tab === 'ledger') {
      cols = [
        dateCol(),
        platform ? userCol : null,
        platform ? { field: 'account', headerName: 'Account', width: 125, cellRenderer: badgeFrom(ACCOUNT) } : null,
        { field: 'type', headerName: 'Type', width: 160, cellRenderer: badgeFrom(LEDGER), filterValueGetter: function (p) { return (LEDGER[p.data.type] || [p.data.type])[0]; } },
        { field: 'amount', headerName: 'Coins', width: 120, type: 'numericColumn', filter: 'agNumberColumnFilter', valueFormatter: signed, cellClassRules: signClass },
        { field: 'balance', headerName: 'Balance after', width: 135, type: 'numericColumn', filter: 'agNumberColumnFilter', valueFormatter: function (p) { return fmtNum(p.value); } },
        { field: 'match', headerName: 'Match', width: 100, filter: 'agNumberColumnFilter', valueFormatter: function (p) { return p.value ? '#' + p.value : ''; } },
        { field: 'order', headerName: 'PayPal order', width: 125, filter: 'agNumberColumnFilter', valueFormatter: function (p) { return p.value ? '#' + p.value : ''; } },
        { field: 'note', headerName: 'Note', flex: 1, minWidth: 180 }
      ];
    } else if (tab === 'orders') {
      cols = [
        dateCol(),
        platform ? userCol : null,
        { field: 'source', headerName: 'How', width: 145, cellRenderer: badgeFrom(SOURCE), filterValueGetter: function (p) { return (SOURCE[p.data.source] || [p.data.source])[0]; } },
        { field: 'usd', headerName: 'USD', width: 100, type: 'numericColumn', filter: 'agNumberColumnFilter', valueFormatter: function (p) { return p.value === null || p.value === undefined ? '' : '$' + Number(p.value).toFixed(2); } },
        { field: 'coins', headerName: 'Coins credited', width: 145, type: 'numericColumn', filter: 'agNumberColumnFilter', valueFormatter: function (p) { return fmtNum(p.value); } },
        { field: 'fee', headerName: 'Fee (5%)', width: 110, type: 'numericColumn', filter: 'agNumberColumnFilter', valueFormatter: function (p) { return fmtNum(p.value); } },
        { field: 'status', headerName: 'Status', width: 130, cellRenderer: badgeFrom(STATUS) },
        { field: 'capture', headerName: 'PayPal capture', flex: 1, minWidth: 140 }
      ];
    } else {
      cols = [
        dateCol(),
        platform ? { field: 'players', headerName: 'Players', flex: 1, minWidth: 200 } : { field: 'opponent', headerName: 'Opponent', width: 150 },
        platform ? { field: 'winner', headerName: 'Winner', width: 140 } : { field: 'outcome', headerName: 'Outcome', width: 120, cellRenderer: badgeFrom(OUTCOME) },
        { field: 'entry', headerName: 'Entry', width: 100, type: 'numericColumn', filter: 'agNumberColumnFilter', valueFormatter: function (p) { return fmtNum(p.value); } },
        { field: 'how', headerName: 'How it ended', width: 150, valueFormatter: function (p) { return HOW[p.value] || p.value || ''; } },
        platform
          ? { field: 'fee', headerName: 'Platform fee', width: 135, type: 'numericColumn', filter: 'agNumberColumnFilter', valueFormatter: function (p) { return fmtNum(p.value); } }
          : { field: 'net', headerName: 'Net coins', width: 120, type: 'numericColumn', filter: 'agNumberColumnFilter', valueFormatter: signed, cellClassRules: signClass }
      ];
    }
    return cols.filter(Boolean);
  }

  var HINTS = {
    agent: 'Every Tato chat, tool call, refusal and player approval, from the agent_log audit table.',
    ledger: 'Double-entry wallet ledger: every coin movement, with the balance after it.',
    orders: 'PayPal Orders v2: manual checkouts and 🤖 Autopilot charges made with a saved PayPal account (Vault v3).',
    matches: 'Finished 1v1 contests with their result and coins won or lost.'
  };

  /* ------------------------------------------------------------ totals row */

  function updateTotals() {
    if (!grid) return;
    var sum = {};
    var keys = { ledger: ['amount'], orders: ['usd', 'coins', 'fee'], agent: ['usd'], matches: state.scope === 'platform' ? ['fee'] : ['net', 'entry'] }[state.tab];
    var n = 0;
    grid.forEachNodeAfterFilter(function (node) {
      if (!node.data) return;
      n++;
      keys.forEach(function (k) { sum[k] = (sum[k] || 0) + (Number(node.data[k]) || 0); });
    });
    var row = { id: 'total', at: null };
    keys.forEach(function (k) { row[k] = Math.round(sum[k] * 100) / 100; });
    var firstText = { agent: 'action', ledger: 'note', orders: 'capture', matches: state.scope === 'platform' ? 'players' : 'opponent' }[state.tab];
    row[firstText] = 'Total · ' + n + ' rows';
    grid.setGridOption('pinnedBottomRowData', [row]);
  }

  /* ------------------------------------------------------------ data */

  function render(resetCols) {
    if (!state.data) return;
    var rows = state.data[state.tab] || [];
    if (resetCols) {
      grid.setGridOption('columnDefs', columns(state.tab, state.scope === 'platform'));
      grid.setGridOption('quickFilterText', $('#dashSearch').value);
    }
    var key = state.scope + ':' + state.tab;
    var known = state.seen[key];
    grid.setGridOption('rowData', rows);
    if (known) {
      var fresh = [];
      rows.forEach(function (r) { if (!known[r.id]) { var node = grid.getRowNode(String(r.id)); if (node) fresh.push(node); } });
      if (fresh.length) grid.flashCells({ rowNodes: fresh, flashDuration: 1600 });
    }
    state.seen[key] = {};
    rows.forEach(function (r) { state.seen[key][r.id] = 1; });
    $('#dashHint').textContent = HINTS[state.tab];
    updateTotals();
  }

  function renderKpis(kpis) {
    var host = $('#kpis');
    host.textContent = '';
    kpis.forEach(function (k) {
      var d = document.createElement('div');
      d.className = 'kpi';
      var l = document.createElement('small');
      l.textContent = k.label;
      var v = document.createElement('b');
      v.textContent = k.value;
      d.appendChild(l);
      d.appendChild(v);
      host.appendChild(d);
    });
  }

  function load(resetCols) {
    clearTimeout(state.timer);
    var scope = state.scope;
    fetch(BASE + '/api.php?a=dashboard&scope=' + scope, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) {
        if (r.status === 401) { location.href = BASE + '/login.php'; throw new Error('logged out'); }
        return r.json();
      })
      .then(function (d) {
        if (!d.ok || scope !== state.scope) return;
        state.data = d;
        renderKpis(d.kpis);
        ['agent', 'ledger', 'orders', 'matches'].forEach(function (t) {
          var b = $('[data-count="' + t + '"]');
          if (b) b.textContent = (d[t] || []).length;
        });
        render(resetCols);
      })
      .catch(function () {})
      .then(function () {
        state.timer = setTimeout(function () { load(false); }, document.hidden ? 20000 : 6000);
      });
  }

  /* ------------------------------------------------------------ boot */

  grid = agGrid.createGrid($('#grid'), {
    theme: theme,
    styleNonce: NONCE,
    columnDefs: columns('agent', false),
    rowData: [],
    getRowId: function (p) { return String(p.data.id); },
    defaultColDef: { sortable: true, resizable: true, filter: true, floatingFilter: true, minWidth: 90 },
    pagination: true,
    paginationPageSize: 25,
    paginationPageSizeSelector: [25, 50, 100, 500],
    enableCellChangeFlash: true,
    tooltipShowDelay: 250,
    rowClassRules: {
      'row-refused': function (p) { return p.data && p.data.ok === false; },
      'row-autopilot': function (p) { return p.data && (p.data.source === 'mandate' || p.data.action === 'Autopilot charge'); }
    },
    overlayNoRowsTemplate: '<span class="dash-empty">Nothing here yet. Play, buy coins or ask Tato.</span>',
    onFilterChanged: updateTotals,
    onRowDataUpdated: updateTotals
  });

  $$('#dashTabs [data-tab]').forEach(function (b) {
    b.addEventListener('click', function () {
      state.tab = b.dataset.tab;
      $$('#dashTabs [data-tab]').forEach(function (x) { x.classList.toggle('on', x === b); x.setAttribute('aria-selected', x === b ? 'true' : 'false'); });
      render(true);
    });
  });
  $$('#scopeSeg [data-scope]').forEach(function (b) {
    b.addEventListener('click', function () {
      state.scope = b.dataset.scope;
      $$('#scopeSeg [data-scope]').forEach(function (x) { x.classList.toggle('on', x === b); });
      state.data = null;
      grid.setGridOption('rowData', []);
      load(true);
    });
  });
  $('#dashSearch').addEventListener('input', function () { grid.setGridOption('quickFilterText', this.value); });
  $('#dashCsv').addEventListener('click', function () {
    grid.exportDataAsCsv({ fileName: 'tiktato-' + state.tab + '-' + state.scope + '.csv', skipPinnedBottom: true });
  });
  document.addEventListener('visibilitychange', function () { if (!document.hidden) load(false); });

  load(true);
})();
