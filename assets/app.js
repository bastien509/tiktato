// TikTato game client. The server owns coins, moves and results;
// this file only renders state, sends intents and plays the animations.
(function () {
  'use strict';

  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var body = document.body;
  var BASE = body.dataset.base || '';
  var CSRF = $('meta[name="csrf-token"]').content;
  var FEE = +body.dataset.fee;
  var STAKE = { min: +body.dataset.stakeMin, max: +body.dataset.stakeMax, step: +body.dataset.stakeStep };
  var REDUCED = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  var LINES = [[0, 1, 2], [3, 4, 5], [6, 7, 8], [0, 3, 6], [1, 4, 7], [2, 5, 8], [0, 4, 8], [2, 4, 6]];
  var BET_CHIPS = [10, 25, 50, 100, 250, 500, 1000];
  var BOT = { username: 'AI Bot', avatar: '🤖', color: 3 };

  var S = {
    me: null, coins: +($('#coinCount').textContent.replace(/\D/g, '')) || 0, coinAnimating: false,
    view: 'arena', firstLobby: true, seen: {}, notifMax: 0, myChallenge: null, challenges: [],
    matchId: null, match: null, prevBoard: '.........', celebrated: {}, busy: false, deadline: 0, turnSec: 30,
    stake: 100, ai: null, aiLevel: 'easy', aiFirst: 'me', lobbyTimer: 0, matchTimer: 0, waitClock: 0
  };

  /* ------------------------------------------------------------ helpers */

  function h(tag, cls, text) {
    var el = document.createElement(tag);
    if (cls) el.className = cls;
    if (text !== undefined && text !== null) el.textContent = text;
    return el;
  }
  function fmt(n) { return Number(n).toLocaleString('en-US'); }
  function pick(a) { return a[Math.floor(Math.random() * a.length)]; }
  function payoutFor(stake) { var pot = stake * 2; var fee = Math.floor(pot * FEE / 100); return { pot: pot, fee: fee, win: pot - fee }; }
  function avatar(p, size) {
    var el = h('div', 'avatar ' + (size || '') + ' av-c' + (p.color || 0));
    el.textContent = p.avatar;
    return el;
  }

  function api(action, params, method) {
    method = method || (params ? 'POST' : 'GET');
    var url = BASE + '/api.php?a=' + encodeURIComponent(action);
    var opt = { method: method, credentials: 'same-origin', headers: { Accept: 'application/json' } };
    if (method === 'GET' && params) url += '&' + new URLSearchParams(params).toString();
    if (method === 'POST') {
      opt.headers['Content-Type'] = 'application/json';
      opt.headers['X-CSRF-Token'] = CSRF;
      opt.body = JSON.stringify(params || {});
    }
    return fetch(url, opt).then(function (res) {
      if (res.status === 401) { location.href = BASE + '/login.php'; throw new Error('Logged out'); }
      return res.json().catch(function () { throw new Error('Connection problem. Try again.'); });
    }, function () { throw new Error('Connection lost. Retrying…'); }).then(function (d) {
      if (!d.ok) throw new Error(d.error || 'Something went wrong.');
      return d;
    });
  }

  /* ------------------------------------------------------------ toasts */

  function toast(o) {
    var t = h('div', 'toast');
    t.appendChild(h('span', 'toast-icon', o.icon || '🔔'));
    var main = h('div', 'toast-main');
    main.appendChild(h('b', '', o.title));
    if (o.text) main.appendChild(h('p', '', o.text));
    t.appendChild(main);
    if (o.action) {
      var b = h('button', 'btn btn-cta', o.action.label);
      b.type = 'button';
      b.addEventListener('click', function () { close(); o.action.fn(); });
      t.appendChild(b);
    }
    $('#toasts').appendChild(t);
    var timer = setTimeout(close, o.ms || 5000);
    function close() {
      clearTimeout(timer);
      t.classList.add('out');
      setTimeout(function () { t.remove(); }, 300);
    }
    while ($('#toasts').children.length > 3) $('#toasts').firstChild.remove();
  }
  function errorToast(e) { toast({ icon: '⚠️', title: e.message || String(e) }); }

  /* ------------------------------------------------------------ sheets */

  function openSheet(id) { $('#' + id).hidden = false; }
  function closeSheet(id) { $('#' + id).hidden = true; }
  $$('.sheet').forEach(function (sh) {
    sh.addEventListener('click', function (ev) {
      if (ev.target.closest('[data-close]')) sh.hidden = true;
    });
  });

  var confirmFn = null;
  function confirmBox(title, bodyEl, okLabel, fn) {
    $('#confirmTitle').textContent = title;
    var holder = $('#confirmBody');
    holder.textContent = '';
    holder.appendChild(bodyEl);
    $('#confirmOk').textContent = okLabel;
    $('#confirmOk').disabled = false;
    confirmFn = fn;
    openSheet('confirmSheet');
  }
  $('#confirmOk').addEventListener('click', function () {
    if (!confirmFn) return;
    var btn = this;
    btn.disabled = true;
    Promise.resolve(confirmFn()).then(function () { closeSheet('confirmSheet'); }, function (e) { errorToast(e); })
      .then(function () { btn.disabled = false; });
  });
  function moneyLines(rows) {
    var ul = h('ul', 'confirm-lines');
    rows.forEach(function (r) {
      var li = h('li', '');
      li.appendChild(h('span', '', r[0]));
      li.appendChild(h('b', r[2] || '', r[1]));
      ul.appendChild(li);
    });
    return ul;
  }

  /* ------------------------------------------------------------ router */

  function show(view) {
    S.view = view;
    var screen = view === 'ai' ? 'game' : view;
    if (screen !== 'game') hideCelebration();
    $$('.screen').forEach(function (s) { s.hidden = s.dataset.screen !== screen; });
    $$('.tab').forEach(function (t) { t.classList.toggle('on', t.dataset.go === view); });
    body.classList.toggle('in-game', screen === 'game' || view === 'wait');
    if (view === 'wallet') loadWallet();
    if (view === 'bet') renderBet();
    if (view === 'wait') renderWait();
    window.scrollTo(0, 0);
  }

  function go(view) {
    if (location.hash !== '#' + view) location.hash = view;
    else route();
  }

  function route() {
    var hsh = location.hash.slice(1) || 'arena';
    var m = /^game\/(\d+)$/.exec(hsh);
    if (m) { openMatch(+m[1]); return; }
    if (hsh === 'ai') { if (S.ai) show('ai'); else go('arena'); return; }
    if (['arena', 'bet', 'wait', 'wallet', 'profile'].indexOf(hsh) < 0) { go('arena'); return; }
    if (S.match && S.match.status === 'active') { location.hash = 'game/' + S.matchId; return; }
    if (hsh !== 'ai') S.ai = null;
    show(hsh);
  }
  window.addEventListener('hashchange', route);

  document.addEventListener('click', function (ev) {
    var g = ev.target.closest('[data-go]');
    if (g) { ev.preventDefault(); go(g.dataset.go); }
  });

  /* ------------------------------------------------------------ me / coins */

  function setCoins(n) {
    S.coins = n;
    if (!S.coinAnimating) $('#coinCount').textContent = fmt(n);
    var w = $('#walletCoins');
    if (w) { w.textContent = fmt(n); $('#walletUsd').textContent = '≈ $' + (n / 100).toFixed(2); }
  }
  function setMe(me) {
    if (!me) return;
    S.me = me;
    setCoins(me.coins);
    $('#statW').textContent = me.wins; $('#statL').textContent = me.losses; $('#statD').textContent = me.draws;
    $('#statW2').textContent = me.wins; $('#statL2').textContent = me.losses; $('#statD2').textContent = me.draws;
  }

  /* ------------------------------------------------------------ lobby */

  function pollLobby() {
    clearTimeout(S.lobbyTimer);
    api('lobby', null, 'GET').then(onLobby, function () {}).then(function () {
      var delay = document.hidden ? 8000 : (S.view === 'game' ? 5000 : 2000);
      S.lobbyTimer = setTimeout(pollLobby, delay);
    });
  }
  document.addEventListener('visibilitychange', function () { if (!document.hidden) { pollLobby(); if (S.view === 'game') pollMatch(); } });

  function onLobby(d) {
    setMe(d.me);
    $('#onlineCount').textContent = d.online;
    S.myChallenge = d.my_challenge;
    renderChallenges(d.challenges);

    if (d.active_match && d.active_match !== S.matchId) {
      if (navigator.vibrate) navigator.vibrate([60, 40, 60]);
      toast({ icon: '⚔️', title: 'Opponent found!', text: 'Your match is starting.' });
      location.hash = 'game/' + d.active_match;
    } else if (S.view === 'wait') {
      if (d.my_challenge) renderWait(); else if (!d.active_match) go('arena');
    }
    handleNotifications(d.notifications);
    S.firstLobby = false;
  }

  function renderChallenges(list) {
    S.challenges = list;
    var ul = $('#challengeList');
    var fresh = [];
    var nextSeen = {};
    ul.textContent = '';
    list.forEach(function (c) {
      nextSeen[c.id] = 1;
      var isNew = !S.seen[c.id];
      if (isNew && !S.firstLobby) fresh.push(c);
      var li = h('li', 'challenge' + (isNew && !S.firstLobby ? ' fresh' : ''));
      li.appendChild(avatar(c.creator, 'sm'));
      var info = h('div', 'ch-info');
      info.appendChild(h('p', 'ch-name', c.creator.username));
      info.appendChild(h('p', 'ch-meta', c.creator.wins + ' wins · ' + Math.ceil(c.secs_left / 60) + ' min left'));
      li.appendChild(info);
      var st = h('span', 'ch-stake');
      st.appendChild(h('span', 'coin sm'));
      st.appendChild(document.createTextNode(fmt(c.stake)));
      li.appendChild(st);
      var b = h('button', 'btn btn-cta ch-accept', 'Accept');
      b.type = 'button';
      b.addEventListener('click', function () { confirmAccept(c); });
      li.appendChild(b);
      ul.appendChild(li);
    });
    S.seen = nextSeen;
    $('#challengeEmpty').hidden = list.length > 0;
    var badge = $('#bellBadge');
    badge.hidden = list.length === 0;
    badge.textContent = list.length;
    if (fresh.length) {
      $('#bellBtn').classList.remove('ring');
      void $('#bellBtn').offsetWidth;
      $('#bellBtn').classList.add('ring');
      if (S.view !== 'game') {
        var c = fresh[0];
        toast({
          icon: c.creator.avatar,
          title: c.creator.username + ' wants to play!',
          text: fmt(c.stake) + '-coin contest · winner gets ' + fmt(payoutFor(c.stake).win),
          action: { label: 'Accept', fn: function () { confirmAccept(c); } },
          ms: 8000
        });
      }
    }
  }

  $('#bellBtn').addEventListener('click', function () {
    go('arena');
    setTimeout(function () { $('.section-head').scrollIntoView({ behavior: 'smooth' }); }, 60);
  });

  function confirmAccept(c) {
    var p = payoutFor(c.stake);
    var wrap = h('div', '');
    var who = h('div', 'confirm-who');
    who.appendChild(avatar(c.creator, 'sm'));
    who.appendChild(h('span', '', c.creator.username));
    wrap.appendChild(who);
    wrap.appendChild(moneyLines([
      ['Your entry', '−' + fmt(c.stake)],
      ['If you win', '+' + fmt(p.win), 'win'],
      ['If you lose', '−' + fmt(c.stake), 'lose'],
      ['Draw', 'full refund']
    ]));
    if (S.coins < c.stake) {
      wrap.appendChild(h('p', 'note', 'You need ' + fmt(c.stake - S.coins) + ' more coins.'));
      confirmBox('Not enough coins', wrap, 'Top up', function () { go('wallet'); });
      return;
    }
    confirmBox('Accept ' + fmt(c.stake) + '-coin contest?', wrap, 'Accept & play', function () {
      return api('challenge_accept', { id: c.id }).then(function (d) {
        setMe(d.me);
        location.hash = 'game/' + d.match_id;
      });
    });
  }

  var NOTE_ICONS = { match_won: '🏆', match_lost: '💔', match_draw: '🤝', topup: '💰', challenge_expired: '⏳', challenge_accepted: '⚔️' };
  function handleNotifications(list) {
    if (!list || !list.length) return;
    var max = S.notifMax;
    list.slice().reverse().forEach(function (n) {
      if (n.id <= S.notifMax) return;
      max = Math.max(max, n.id);
      if (n.type === 'challenge_accepted') return;
      if (n.match_id && n.match_id === S.matchId && /^match_/.test(n.type)) return;
      if (n.type === 'topup' && +body.dataset.coinRain > 0) return;
      toast({ icon: NOTE_ICONS[n.type] || '🔔', title: n.title, text: n.body });
    });
    if (max > S.notifMax) {
      S.notifMax = max;
      api('notifications_read', { up_to: max }).catch(function () {});
    }
  }

  /* ------------------------------------------------------------ play sheet */

  function sheetStep(step) { $$('#playSheet [data-step]').forEach(function (s) { s.hidden = s.dataset.step !== step; }); }
  $('#playBtn').addEventListener('click', function () { sheetStep('mode'); openSheet('playSheet'); });
  $$('#playSheet [data-mode]').forEach(function (b) {
    b.addEventListener('click', function () {
      if (b.dataset.mode === 'ai') { sheetStep('ai'); return; }
      closeSheet('playSheet');
      go(S.myChallenge ? 'wait' : 'bet');
    });
  });
  function segment(id, key) {
    $$('#' + id + ' button').forEach(function (b) {
      b.addEventListener('click', function () {
        $$('#' + id + ' button').forEach(function (x) { x.classList.toggle('on', x === b); });
        S[key] = b.dataset.level || b.dataset.first;
      });
    });
  }
  segment('aiLevel', 'aiLevel');
  segment('aiFirst', 'aiFirst');
  $('#aiStart').addEventListener('click', function () { closeSheet('playSheet'); startAi(); });

  /* ------------------------------------------------------------ bet */

  function renderBet() {
    var wrap = $('#betChips');
    wrap.textContent = '';
    BET_CHIPS.forEach(function (v) {
      var b = h('button', 'bet-chip' + (v === S.stake ? ' on' : ''));
      b.type = 'button';
      b.appendChild(h('span', 'coin sm'));
      b.appendChild(document.createTextNode(fmt(v)));
      b.addEventListener('click', function () { S.stake = v; $('#betInput').value = v; renderBet(); });
      wrap.appendChild(b);
    });
    var input = $('#betInput');
    input.min = STAKE.min; input.max = STAKE.max; input.step = STAKE.step;
    if (!input.value) input.value = S.stake;
    renderPayout();
  }
  $('#betInput').addEventListener('input', function () {
    S.stake = parseInt(this.value, 10) || 0;
    $$('.bet-chip').forEach(function (c) { c.classList.toggle('on', +c.textContent.replace(/\D/g, '') === S.stake); });
    renderPayout();
  });
  function stakeError(v) {
    if (!v || v < STAKE.min || v > STAKE.max || v % STAKE.step) return 'Entries go from ' + STAKE.min + ' to ' + fmt(STAKE.max) + ' coins, in steps of ' + STAKE.step + '.';
    return '';
  }
  function renderPayout() {
    var v = S.stake;
    var p = payoutFor(v || 0);
    var card = $('#payoutCard');
    card.textContent = '';
    [['Your entry', fmt(v || 0), ''], ['Prize pool', fmt(p.pot), ''], ['If you win', '+' + fmt(p.win), 'win'], ['If you lose', '−' + fmt(v || 0), 'lose']].forEach(function (r) {
      var d = h('div', r[2]);
      d.appendChild(h('small', '', r[0]));
      d.appendChild(h('b', '', r[1]));
      card.appendChild(d);
    });
    var err = stakeError(v);
    var btn = $('#betGo');
    var short = !err && S.coins < v;
    btn.textContent = short ? 'Top up to enter' : 'Find an opponent';
    btn.disabled = !!err;
    $('#betNote').textContent = err || (short
      ? 'You have ' + fmt(S.coins) + ' coins. Add ' + fmt(v - S.coins) + ' more to post this contest.'
      : 'Draw = full refund. Platform fee: ' + FEE + '% of the prize pool, only on a win (' + fmt(p.fee) + ' coins).');
  }
  $('#betGo').addEventListener('click', function () {
    var btn = this;
    if (S.coins < S.stake) { go('wallet'); return; }
    btn.disabled = true;
    api('challenge_create', { stake: S.stake }).then(function (d) {
      setMe(d.me);
      S.myChallenge = { id: d.challenge.id, stake: d.challenge.stake, secs_left: 600 };
      go('wait');
    }, errorToast).then(function () { btn.disabled = false; });
  });

  /* ------------------------------------------------------------ waiting */

  function renderWait() {
    var c = S.myChallenge;
    if (!c) return;
    $('#waitStake').textContent = fmt(c.stake);
    var end = Date.now() + c.secs_left * 1000;
    clearInterval(S.waitClock);
    function tick() {
      var s = Math.max(0, Math.round((end - Date.now()) / 1000));
      $('#waitClock').textContent = Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2);
      if (S.view !== 'wait') clearInterval(S.waitClock);
    }
    tick();
    S.waitClock = setInterval(tick, 1000);
  }
  $('#waitCancel').addEventListener('click', function () {
    var c = S.myChallenge;
    if (!c) { go('arena'); return; }
    var btn = this;
    btn.disabled = true;
    api('challenge_cancel', { id: c.id }).then(function (d) {
      S.myChallenge = null;
      setMe(d.me);
      toast({ icon: '↩️', title: fmt(c.stake) + ' coins refunded', text: 'Your contest was cancelled.' });
      go('arena');
    }, errorToast).then(function () { btn.disabled = false; });
  });

  /* ------------------------------------------------------------ board */

  var cells = $$('#board .cell');
  var SVGNS = 'http://www.w3.org/2000/svg';
  function markSvg(sym) {
    var svg = document.createElementNS(SVGNS, 'svg');
    svg.setAttribute('viewBox', '0 0 100 100');
    if (sym === 'X') {
      svg.setAttribute('class', 'mx');
      [[14, 14, 86, 86], [86, 14, 14, 86]].forEach(function (c) {
        var l = document.createElementNS(SVGNS, 'line');
        l.setAttribute('x1', c[0]); l.setAttribute('y1', c[1]); l.setAttribute('x2', c[2]); l.setAttribute('y2', c[3]);
        svg.appendChild(l);
      });
    } else {
      svg.setAttribute('class', 'mo');
      var ci = document.createElementNS(SVGNS, 'circle');
      ci.setAttribute('cx', 50); ci.setAttribute('cy', 50); ci.setAttribute('r', 36);
      svg.appendChild(ci);
    }
    return svg;
  }
  function paintBoard(board, prev, winLine) {
    cells.forEach(function (cell, i) {
      var ch = board[i];
      cell.classList.remove('win');
      if (ch === '.') { cell.textContent = ''; cell.classList.remove('new'); return; }
      if (!cell.firstChild || cell.dataset.sym !== ch) {
        cell.textContent = '';
        cell.appendChild(markSvg(ch));
        cell.dataset.sym = ch;
        cell.classList.toggle('new', prev[i] === '.');
      }
    });
    var strike = $('#strike');
    strike.classList.remove('on');
    if (winLine) {
      winLine.forEach(function (i) { cells[i].classList.add('win'); });
      var c = function (i) { return [(i % 3) * 100 + 50, Math.floor(i / 3) * 100 + 50]; };
      var a = c(winLine[0]); var b = c(winLine[2]);
      var dx = (b[0] - a[0]) * 0.18; var dy = (b[1] - a[1]) * 0.18;
      var line = $('line', strike);
      line.setAttribute('x1', a[0] - dx); line.setAttribute('y1', a[1] - dy);
      line.setAttribute('x2', b[0] + dx); line.setAttribute('y2', b[1] + dy);
      void strike.getBoundingClientRect();
      strike.classList.add('on');
    }
  }
  function resetBoard() {
    cells.forEach(function (c) { c.textContent = ''; c.className = 'cell'; delete c.dataset.sym; });
    $('#strike').classList.remove('on');
  }
  function fillPlayer(el, p, isYou) {
    var av = $('.avatar', el);
    av.className = 'avatar md ring av-c' + (p.color || 0);
    $('.av-face', av).textContent = p.avatar;
    var name = $('.pname', el);
    name.textContent = p.username;
    if (isYou) name.appendChild(h('span', 'you', 'YOU'));
  }

  cells.forEach(function (cell) {
    cell.addEventListener('click', function () {
      var i = +cell.dataset.cell;
      if (S.view === 'ai') aiHumanMove(i);
      else humanMove(i);
    });
  });

  /* ------------------------------------------------------------ human match */

  function openMatch(id) {
    if (S.matchId !== id) {
      S.matchId = id; S.match = null; S.prevBoard = '.........';
      resetBoard();
    }
    S.ai = null;
    show('game');
    $('#resignBtn').textContent = 'Resign';
    pollMatch();
  }

  function pollMatch() {
    clearTimeout(S.matchTimer);
    if (S.view !== 'game' || !S.matchId) return;
    var id = S.matchId;
    api('match', { id: id }, 'GET').then(function (d) {
      if (id !== S.matchId) return;
      setMe(d.me);
      renderMatch(d.match);
    }, function (e) {
      if (/not found/i.test(e.message)) { S.matchId = null; go('arena'); }
    }).then(function () {
      if (S.view === 'game' && S.match && S.match.status === 'active' && id === S.matchId) {
        S.matchTimer = setTimeout(pollMatch, document.hidden ? 3000 : 1000);
      }
    });
  }

  function renderMatch(m) {
    var you = m.you;
    var opp = you === 'X' ? 'O' : 'X';
    fillPlayer($('#pX'), m.players.X, you === 'X');
    fillPlayer($('#pO'), m.players.O, you === 'O');
    $('#potVal').textContent = fmt(m.pot);
    $('#potSub').textContent = 'Winner gets ' + fmt(m.pot - Math.floor(m.pot * m.fee_pct / 100));
    paintBoard(m.board, S.prevBoard, m.status === 'finished' ? m.win_line : null);
    S.prevBoard = m.board;
    S.match = m;
    var active = m.status === 'active';
    var mine = active && m.turn === you;
    $('#board').classList.toggle('can-play', mine && !S.busy);
    $('#pX').classList.toggle('active', active && m.turn === 'X');
    $('#pO').classList.toggle('active', active && m.turn === 'O');
    S.deadline = Date.now() + m.secs_left * 1000;
    S.turnSec = Math.max(m.turn_sec, m.secs_left);
    var line = $('#turnLine');
    line.classList.toggle('mine', mine);
    if (active) {
      line.textContent = mine ? 'Your turn!' : m.players[opp].username + ' is thinking…';
    } else {
      line.textContent = m.result === 'draw' ? 'Draw — stakes refunded'
        : (m.winner === you ? 'You win!' : m.players[m.winner].username + ' wins')
          + (m.result === 'timeout' ? ' (time out)' : m.result === 'resign' ? ' (resigned)' : '');
    }
    $('#resignBtn').hidden = !active;
    $('#leaveBtn').hidden = active;
    $('#againBtn').hidden = active;
    $('#gameNote').textContent = active
      ? 'Entry ' + fmt(m.stake) + ' each · ' + m.turn_sec + 's per move · a draw refunds both'
      : '';
    if (!active && !S.celebrated[m.id]) {
      S.celebrated[m.id] = 1;
      setTimeout(function () { celebrateMatch(m); }, m.win_line ? 1100 : 400);
    }
  }

  function humanMove(i) {
    var m = S.match;
    if (!m || m.status !== 'active' || m.turn !== m.you || S.busy || m.board[i] !== '.') return;
    S.busy = true;
    var optimistic = m.board.slice(0, i) + m.you + m.board.slice(i + 1);
    paintBoard(optimistic, S.prevBoard, null);
    S.prevBoard = optimistic;
    $('#board').classList.remove('can-play');
    api('move', { match_id: m.id, cell: i }).then(function (d) {
      S.busy = false;
      setMe(d.me);
      renderMatch(d.match);
    }, function (e) {
      S.busy = false;
      errorToast(e);
      S.prevBoard = m.board;
      pollMatch();
    });
  }

  $('#resignBtn').addEventListener('click', function () {
    if (S.view === 'ai') { S.ai = null; go('arena'); return; }
    var m = S.match;
    if (!m || m.status !== 'active') return;
    confirmBox('Resign this match?', moneyLines([['You lose your entry', '−' + fmt(m.stake), 'lose']]), 'Resign', function () {
      return api('resign', { match_id: m.id }).then(function (d) { setMe(d.me); renderMatch(d.match); });
    });
  });
  $('#leaveBtn').addEventListener('click', function () { S.ai = null; S.match = null; go('arena'); });
  $('#againBtn').addEventListener('click', playAgain);

  function playAgain() {
    hideCelebration();
    if (S.view === 'ai' || (S.ai && S.ai.over)) { startAi(); return; }
    S.match = null;
    if (S.stake > S.coins) S.stake = BET_CHIPS.filter(function (v) { return v <= S.coins; }).pop() || STAKE.min;
    $('#betInput').value = S.stake;
    go('bet');
  }

  // Count down the turn clock locally between polls.
  setInterval(function () {
    var m = S.match;
    if (S.view !== 'game' || !m || m.status !== 'active') return;
    var left = Math.max(0, (S.deadline - Date.now()) / 1000);
    var el = m.turn === 'X' ? $('#pX') : $('#pO');
    $('.timer', el).style.setProperty('--p', Math.min(100, left / S.turnSec * 100).toFixed(1));
    el.classList.toggle('low', left < 8);
    $(m.turn === 'X' ? '#pO' : '#pX').classList.remove('low');
    if (m.turn === m.you && left > 0) $('#turnLine').textContent = 'Your turn! ' + Math.ceil(left) + 's';
    if (left <= 0 && !S.busy) { S.deadline = Date.now() + 1500; pollMatch(); }
  }, 250);

  /* ------------------------------------------------------------ AI practice */

  function winnerOf(b) {
    for (var k = 0; k < LINES.length; k++) {
      var l = LINES[k];
      if (b[l[0]] !== '.' && b[l[0]] === b[l[1]] && b[l[0]] === b[l[2]]) return { s: b[l[0]], line: l };
    }
    return null;
  }
  function empties(b) { var out = []; for (var i = 0; i < 9; i++) if (b[i] === '.') out.push(i); return out; }
  function findWin(b, s) {
    var em = empties(b);
    for (var k = 0; k < em.length; k++) {
      b[em[k]] = s;
      var w = winnerOf(b);
      b[em[k]] = '.';
      if (w) return em[k];
    }
    return -1;
  }
  function minimax(b, turn, ai, depth) {
    var w = winnerOf(b);
    if (w) return w.s === ai ? 10 - depth : depth - 10;
    var em = empties(b);
    if (!em.length) return 0;
    var best = turn === ai ? -99 : 99;
    for (var k = 0; k < em.length; k++) {
      b[em[k]] = turn;
      var sc = minimax(b, turn === 'X' ? 'O' : 'X', ai, depth + 1);
      b[em[k]] = '.';
      best = turn === ai ? Math.max(best, sc) : Math.min(best, sc);
    }
    return best;
  }
  function bestMove(b, ai) {
    var best = -99; var moves = [];
    empties(b).forEach(function (i) {
      b[i] = ai;
      var sc = minimax(b, ai === 'X' ? 'O' : 'X', ai, 1);
      b[i] = '.';
      if (sc > best) { best = sc; moves = [i]; } else if (sc === best) moves.push(i);
    });
    return pick(moves);
  }
  function aiChoose(b, ai, level) {
    var human = ai === 'X' ? 'O' : 'X';
    if (level === 'hard') return bestMove(b, ai);
    var win = findWin(b, ai);
    if (level === 'medium') {
      if (win >= 0) return win;
      var block = findWin(b, human);
      if (block >= 0 && Math.random() < 0.85) return block;
      return Math.random() < 0.5 ? bestMove(b, ai) : pick(empties(b));
    }
    // easy: grabs an open win only half the time and never blocks
    if (win >= 0 && Math.random() < 0.5) return win;
    return pick(empties(b));
  }

  function startAi() {
    hideCelebration();
    var human = S.aiFirst === 'me' ? 'X' : 'O';
    S.ai = { board: '.........'.split(''), human: human, bot: human === 'X' ? 'O' : 'X', turn: 'X', level: S.aiLevel, over: false };
    S.match = null;
    S.prevBoard = '.........';
    resetBoard();
    if (location.hash !== '#ai') location.hash = 'ai'; else show('ai');
    renderAi();
    if (S.ai.turn === S.ai.bot) setTimeout(aiTurn, 600);
  }
  function botProfile() {
    var lvl = S.ai.level;
    return { username: 'AI · ' + lvl.charAt(0).toUpperCase() + lvl.slice(1), avatar: BOT.avatar, color: lvl === 'hard' ? 1 : lvl === 'medium' ? 5 : 3 };
  }
  function renderAi(winLine) {
    var g = S.ai;
    var me = S.me || { username: 'You', avatar: $('#meAvatar').textContent, color: 0 };
    fillPlayer(g.human === 'X' ? $('#pX') : $('#pO'), me, true);
    fillPlayer(g.bot === 'X' ? $('#pX') : $('#pO'), botProfile(), false);
    $('#potVal').textContent = 'Free';
    $('#potSub').textContent = 'Practice · no coins';
    var board = g.board.join('');
    paintBoard(board, S.prevBoard, winLine || null);
    S.prevBoard = board;
    var mine = !g.over && g.turn === g.human;
    $('#board').classList.toggle('can-play', mine);
    $('#pX').classList.toggle('active', !g.over && g.turn === 'X');
    $('#pO').classList.toggle('active', !g.over && g.turn === 'O');
    $$('.pcard .timer').forEach(function (t) { t.style.setProperty('--p', 100); });
    $('#turnLine').classList.toggle('mine', mine);
    if (!g.over) $('#turnLine').textContent = mine ? 'Your turn!' : 'AI is thinking…';
    $('#resignBtn').hidden = g.over;
    $('#resignBtn').textContent = 'Quit';
    $('#leaveBtn').hidden = !g.over;
    $('#againBtn').hidden = !g.over;
    $('#gameNote').textContent = g.over ? '' : 'Practice mode · ' + (g.level === 'hard' ? 'this AI never loses' : 'this AI makes mistakes');
  }
  function aiCheckEnd() {
    var g = S.ai;
    var w = winnerOf(g.board);
    var full = empties(g.board).length === 0;
    if (!w && !full) return false;
    g.over = true;
    $('#turnLine').textContent = w ? (w.s === g.human ? 'You win!' : 'AI wins') : 'Draw!';
    renderAi(w ? w.line : null);
    $('#turnLine').classList.remove('mine');
    var me = S.me || { username: 'You', avatar: $('#meAvatar').textContent, color: 0 };
    setTimeout(function () {
      if (!S.ai || !S.ai.over) return;
      if (!w) celebrate({ kind: 'draw', players: [me, botProfile()], practice: true });
      else celebrate({ kind: w.s === g.human ? 'win' : 'lose', winner: w.s === g.human ? me : botProfile(), practice: true });
    }, w ? 1000 : 400);
    return true;
  }
  function aiHumanMove(i) {
    var g = S.ai;
    if (!g || g.over || g.turn !== g.human || g.board[i] !== '.') return;
    g.board[i] = g.human;
    g.turn = g.bot;
    renderAi();
    if (!aiCheckEnd()) setTimeout(aiTurn, 450 + Math.random() * 350);
  }
  function aiTurn() {
    var g = S.ai;
    if (!g || g.over || g.turn !== g.bot) return;
    g.board[aiChoose(g.board, g.bot, g.level)] = g.bot;
    g.turn = g.human;
    renderAi();
    aiCheckEnd();
  }

  /* ------------------------------------------------------------ celebration */

  function celebrateMatch(m) {
    if (S.matchId !== m.id || S.view !== 'game') return;
    var meP = m.players[m.you];
    if (m.result === 'draw') {
      celebrate({ kind: 'draw', players: [m.players.X, m.players.O], stake: m.stake });
      return;
    }
    var winner = m.players[m.winner];
    var won = m.winner === m.you;
    celebrate({
      kind: won ? 'win' : 'lose', winner: winner, you: meP, stake: m.stake, pot: m.pot, fee: m.fee,
      payout: m.payout, net: m.net, result: m.result
    });
  }

  function celebrate(o) {
    var ov = $('#winOverlay');
    ov.classList.toggle('draw', o.kind === 'draw');
    $('#winKicker').textContent = o.kind === 'draw' ? "It's a" : 'We have a';
    $('#winTitle').textContent = o.kind === 'draw' ? 'Draw' : 'Winner';
    var avs = $('#winAvatars');
    avs.textContent = '';
    var people = o.kind === 'draw' ? o.players : [o.winner];
    people.forEach(function (p) {
      var wrap = h('div', 'win-av');
      if (o.kind !== 'draw') wrap.appendChild(h('span', 'crown', '👑'));
      wrap.appendChild(avatar(p, 'xl'));
      avs.appendChild(wrap);
    });
    $('#winName').textContent = o.kind === 'draw'
      ? o.players[0].username + ' & ' + o.players[1].username
      : o.winner.username + (o.kind === 'win' ? ' (you!)' : '');

    var box = $('#winAmountBox');
    var amt = $('#winAmount');
    box.classList.remove('loss');
    box.hidden = !!o.practice;
    var sub = '';
    if (o.practice) {
      sub = o.kind === 'win' ? 'Nice! Ready to play a human for coins?' : o.kind === 'draw' ? 'Nobody wins this one. Practice is free.' : 'The AI got you this time. Try again!';
    } else if (o.kind === 'draw') {
      amt.textContent = '+' + fmt(o.stake);
      sub = 'Draws refund both entries in full. No fee.';
    } else if (o.kind === 'win') {
      amt.textContent = '0';
      sub = 'Prize pool ' + fmt(o.pot) + ' − ' + FEE + '% fee (' + fmt(o.fee) + ') · net +' + fmt(o.net)
        + (o.result === 'timeout' ? ' · opponent ran out of time' : o.result === 'resign' ? ' · opponent resigned' : '');
    } else {
      amt.textContent = '−' + fmt(o.stake);
      box.classList.add('loss');
      sub = 'You lost your ' + fmt(o.stake) + '-coin entry' + (o.result === 'timeout' ? ' (your clock ran out).' : '.');
    }
    $('#winSub').textContent = sub;
    ov.hidden = false;
    if (o.kind === 'win' || (o.kind === 'lose' && !o.practice)) confetti(o.kind === 'win' ? 220 : 90);
    if (o.kind === 'win' && !o.practice) {
      setTimeout(function () {
        countUp(amt, 0, o.payout, 900, '+');
        coinRain(o.payout, box);
      }, 1100);
    }
  }
  function hideCelebration() { $('#winOverlay').hidden = true; stopConfetti(); }
  $('#winAgain').addEventListener('click', playAgain);
  $('#winArena').addEventListener('click', function () { hideCelebration(); S.ai = null; S.match = null; go('arena'); });

  function countUp(el, from, to, ms, prefix) {
    var t0 = performance.now();
    (function step(t) {
      var k = Math.min(1, (t - t0) / ms);
      var e = 1 - Math.pow(1 - k, 3);
      el.textContent = (prefix || '') + fmt(Math.round(from + (to - from) * e));
      if (k < 1) requestAnimationFrame(step);
    })(t0);
  }

  // "Money received": coins fly into the wallet chip and the balance counts up.
  function coinRain(amount, fromEl) {
    var chip = $('#coinChip');
    var fx = $('#fx');
    var to = chip.getBoundingClientRect();
    var tx = to.left + 24; var ty = to.top + to.height / 2;
    var fr = fromEl && !fromEl.hidden ? fromEl.getBoundingClientRect() : { left: innerWidth / 2 - 30, top: innerHeight / 2 - 30, width: 60, height: 60 };
    var fx0 = fr.left + fr.width / 2; var fy0 = fr.top + fr.height / 2;
    var target = S.coins;
    var start = Math.max(0, target - amount);
    var n = REDUCED ? 3 : Math.min(22, 8 + Math.round(Math.log10(amount + 1) * 4));
    var landed = 0;
    S.coinAnimating = true;
    $('#coinCount').textContent = fmt(start);

    var pop = h('div', 'money-pop', '+' + fmt(amount));
    pop.appendChild(h('small', '', 'MONEY RECEIVED'));
    fx.appendChild(pop);
    setTimeout(function () { pop.remove(); }, 1900);

    for (var i = 0; i < n; i++) {
      (function (i) {
        var c = h('span', 'coin fly-coin');
        fx.appendChild(c);
        var sx = fx0 + (Math.random() - 0.5) * 120;
        var sy = fy0 + (Math.random() - 0.5) * 60;
        var mx = (sx + tx) / 2 + (Math.random() - 0.5) * 160;
        var my = Math.min(sy, ty) - 60 - Math.random() * 120;
        var anim = c.animate([
          { transform: 'translate(' + fx0 + 'px,' + fy0 + 'px) scale(.2) rotate(0deg)', opacity: 0 },
          { transform: 'translate(' + sx + 'px,' + sy + 'px) scale(1.25) rotate(90deg)', opacity: 1, offset: 0.25 },
          { transform: 'translate(' + mx + 'px,' + my + 'px) scale(1) rotate(260deg)', opacity: 1, offset: 0.6 },
          { transform: 'translate(' + tx + 'px,' + ty + 'px) scale(.55) rotate(420deg)', opacity: 0.9 }
        ], { duration: 1100 + Math.random() * 300, delay: i * 55, easing: 'cubic-bezier(.45,.05,.55,.95)', fill: 'forwards' });
        anim.onfinish = function () {
          c.remove();
          landed++;
          chip.classList.remove('bump');
          void chip.offsetWidth;
          chip.classList.add('bump');
          $('#coinCount').textContent = fmt(Math.round(start + (target - start) * landed / n));
          if (landed === n) {
            S.coinAnimating = false;
            $('#coinCount').textContent = fmt(S.coins);
          }
        };
      })(i);
    }
  }

  var confettiRaf = 0;
  function stopConfetti() {
    cancelAnimationFrame(confettiRaf);
    var cv = $('#confetti');
    cv.getContext('2d').clearRect(0, 0, cv.width, cv.height);
  }
  function confetti(count) {
    if (REDUCED) return;
    var cv = $('#confetti');
    var dpr = Math.min(2, window.devicePixelRatio || 1);
    cv.width = innerWidth * dpr; cv.height = innerHeight * dpr;
    var ctx = cv.getContext('2d');
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    var colors = ['#F5C518', '#ffe066', '#D21034', '#1E6BFF', '#14B8A6', '#22d3ee', '#ffffff', '#FF7A1A'];
    var parts = [];
    for (var i = 0; i < count; i++) {
      var side = i % 3;
      parts.push({
        x: side === 0 ? innerWidth / 2 : side === 1 ? 0 : innerWidth,
        y: side === 0 ? innerHeight * 0.35 : innerHeight * 0.7,
        vx: side === 0 ? (Math.random() - 0.5) * 14 : (side === 1 ? 1 : -1) * (4 + Math.random() * 8),
        vy: -(6 + Math.random() * 10),
        w: 6 + Math.random() * 6, h: 8 + Math.random() * 10,
        r: Math.random() * 6, vr: (Math.random() - 0.5) * 0.4,
        c: colors[i % colors.length], round: Math.random() < 0.3
      });
    }
    var t0 = performance.now();
    cancelAnimationFrame(confettiRaf);
    (function frame(t) {
      var age = t - t0;
      ctx.clearRect(0, 0, innerWidth, innerHeight);
      parts.forEach(function (p) {
        p.vy += 0.22; p.vx *= 0.99; p.vy *= 0.99;
        p.x += p.vx; p.y += p.vy; p.r += p.vr;
        ctx.save();
        ctx.globalAlpha = Math.max(0, 1 - Math.max(0, age - 3500) / 1000);
        ctx.translate(p.x, p.y);
        ctx.rotate(p.r);
        ctx.fillStyle = p.c;
        if (p.round) { ctx.beginPath(); ctx.arc(0, 0, p.w / 2, 0, 6.283); ctx.fill(); }
        else ctx.fillRect(-p.w / 2, -p.h / 2, p.w, p.h * Math.abs(Math.cos(p.r * 2)));
        ctx.restore();
      });
      if (age < 4500) confettiRaf = requestAnimationFrame(frame);
      else ctx.clearRect(0, 0, innerWidth, innerHeight);
    })(t0);
  }

  /* ------------------------------------------------------------ wallet */

  var LEDGER_LABELS = {
    topup: ['💳', 'PayPal top-up'], bet_stake: ['🎯', 'Contest entry'], payout: ['🏆', 'Match won'],
    refund: ['↩️', 'Refund'], topup_fee: ['🧾', 'Top-up fee'], platform_fee: ['🧾', 'Platform fee']
  };
  function loadWallet() {
    api('wallet', null, 'GET').then(function (d) {
      setMe(d.me);
      var packs = $('#packs');
      packs.textContent = '';
      d.packs.forEach(function (p) {
        var b = h('button', 'pack' + (p.usd === 20 ? ' best' : ''));
        b.type = 'button';
        b.disabled = !d.paypal.ready;
        b.appendChild(h('span', 'pack-price', '$' + p.usd));
        var coins = h('span', 'pack-coins');
        coins.appendChild(h('span', 'coin sm'));
        coins.appendChild(document.createTextNode(fmt(p.credit) + ' coins'));
        b.appendChild(coins);
        b.appendChild(h('span', 'pack-fee', fmt(p.gross) + ' − ' + fmt(p.fee) + ' fee (' + d.fees.topup_pct + '%)'));
        b.appendChild(h('span', 'pack-pay', d.paypal.ready ? 'Pay with PayPal' : 'Coming soon'));
        b.addEventListener('click', function () { startTopup(p, b); });
        packs.appendChild(b);
      });
      $('#ppMode').hidden = !(d.paypal.ready && d.paypal.mode === 'sandbox');
      $('#ppNote').textContent = !d.paypal.ready
        ? 'PayPal checkout is being connected. Top-ups open soon.'
        : d.paypal.mode === 'sandbox' ? 'Test mode: pay with a PayPal sandbox buyer account. No real money moves.' : 'You will be sent to PayPal to pay securely.';
      renderLimit(d.limits);
      var ul = $('#ledger');
      ul.textContent = '';
      if (!d.ledger.length) ul.appendChild(h('li', 'note', 'No coin activity yet.'));
      d.ledger.forEach(function (r) {
        var lab = LEDGER_LABELS[r.type] || ['•', r.type];
        var title = r.type === 'refund' ? (r.note === 'draw' ? 'Draw refund' : 'Contest refund') : lab[1];
        var icon = r.type === 'refund' && r.note === 'draw' ? '🤝' : lab[0];
        var li = h('li', '');
        li.appendChild(h('span', 'lg-icon', icon));
        var main = h('div', 'lg-main');
        main.appendChild(h('p', '', title + (r.match_id ? ' · match #' + r.match_id : '')));
        var when = new Date(r.at.replace(' ', 'T'));
        main.appendChild(h('small', '', isNaN(when) ? '' : when.toLocaleString()));
        li.appendChild(main);
        var amt = h('div', 'lg-amt ' + (r.amount >= 0 ? 'plus' : 'minus'), (r.amount >= 0 ? '+' : '−') + fmt(Math.abs(r.amount)));
        amt.appendChild(h('small', '', 'bal ' + fmt(r.balance)));
        li.appendChild(amt);
        ul.appendChild(li);
      });
    }, errorToast);
  }
  function startTopup(p, btn) {
    var wrap = moneyLines([
      ['You pay', '$' + p.usd.toFixed(2)],
      ['Coins', fmt(p.gross)],
      ['Service fee (5%)', '−' + fmt(p.fee), 'lose'],
      ['Added to wallet', '+' + fmt(p.credit), 'win']
    ]);
    confirmBox('Buy ' + fmt(p.credit) + ' coins', wrap, 'Go to PayPal', function () {
      btn.disabled = true;
      return api('topup', { usd: p.usd }).then(function (d) {
        location.href = d.approve_url;
      }, function (e) { btn.disabled = false; throw e; });
    });
  }

  function renderLimit(l) {
    var card = $('#limitCard');
    if (!card || !l) return;
    card.textContent = '';
    card.appendChild(h('b', '', 'Weekly PayPal limit'));
    card.appendChild(h('p', '', l.weekly_limit_usd === null
      ? 'No limit set. Spent $' + l.spent_7d_usd.toFixed(2) + ' in the last 7 days.'
      : '$' + l.spent_7d_usd.toFixed(2) + ' of $' + l.weekly_limit_usd + ' used this week · $' + l.remaining_usd.toFixed(2) + ' left'));
    if (l.weekly_limit_usd !== null) {
      var bar = h('div', 'limit-bar');
      var fill = h('i', '');
      fill.style.width = Math.min(100, l.spent_7d_usd / Math.max(1, l.weekly_limit_usd) * 100) + '%';
      bar.appendChild(fill);
      card.appendChild(bar);
    }
    if (l.pending_limit) {
      card.appendChild(h('small', '', 'Change scheduled: ' + (l.pending_limit.weekly_usd === null ? 'no limit' : '$' + l.pending_limit.weekly_usd + '/week') + ' from ' + l.pending_limit.effective_at));
    }
    if ($('#tatoFab')) {
      var ask = h('button', 'btn btn-ghost', l.weekly_limit_usd === null ? 'Set a limit with Tato' : 'Change limit with Tato');
      ask.type = 'button';
      ask.addEventListener('click', function () { openTato('Help me set a weekly spending limit'); });
      card.appendChild(ask);
    }
  }

  /* ------------------------------------------------------------ Tato (AI agent) */

  var tatoBusy = false;
  function tatoBubble(role, text) {
    var b = h('div', 'tato-msg ' + role);
    b.textContent = text;
    $('#tatoLog').appendChild(b);
    $('#tatoLog').scrollTop = $('#tatoLog').scrollHeight;
    return b;
  }
  function tatoCard(c) {
    var card = h('div', 'tato-card');
    if (c.type === 'checkout') {
      card.appendChild(h('b', '', 'PayPal checkout · $' + c.usd));
      card.appendChild(moneyLines([['Coins added', '+' + fmt(c.credit), 'win'], ['Service fee (5%)', fmt(c.fee) + ' coins']]));
      var pay = h('a', 'pack-pay tato-pay', 'Pay with PayPal');
      pay.href = c.url;
      card.appendChild(pay);
      card.appendChild(h('small', '', 'Nothing is charged until you approve on PayPal.'));
    } else if (c.type === 'confirm') {
      card.appendChild(h('b', '', c.title));
      card.appendChild(moneyLines(c.lines.map(function (l) { return [l[0], l[1], /^\+/.test(l[1]) ? 'win' : /^−/.test(l[1]) ? 'lose' : '']; })));
      var row = h('div', 'sheet-actions');
      var no = h('button', 'btn btn-ghost', 'Not now');
      var yes = h('button', 'btn btn-cta', 'Confirm');
      no.type = 'button';
      yes.type = 'button';
      no.addEventListener('click', function () { card.classList.add('done'); row.remove(); card.appendChild(h('small', '', 'Skipped.')); });
      yes.addEventListener('click', function () {
        yes.disabled = no.disabled = true;
        api('agent_confirm', { proposal_id: c.proposal_id }).then(function (d) {
          setMe(d.me);
          row.remove();
          card.classList.add('done');
          card.appendChild(h('small', '', '✓ ' + d.result.message));
          closeSheet('tatoSheet');
          if (d.result.go === 'wait') {
            S.myChallenge = { id: d.result.challenge.id, stake: d.result.challenge.stake, secs_left: 600 };
            go('wait');
          } else if (d.result.go === 'game') {
            location.hash = 'game/' + d.result.match_id;
          } else {
            go('arena');
          }
        }, function (e) { yes.disabled = no.disabled = false; errorToast(e); });
      });
      row.appendChild(no);
      row.appendChild(yes);
      card.appendChild(row);
    } else if (c.type === 'limit') {
      var r = c.result;
      card.appendChild(h('b', '', 'Weekly limit'));
      card.appendChild(h('p', '', r.status === 'applied_now'
        ? 'Now $' + r.weekly_limit_usd + ' per week, effective immediately.'
        : (r.new_limit_usd === null ? 'Removing your limit' : 'A new limit of $' + r.new_limit_usd + '/week') + ' takes effect in ' + r.takes_effect_in_hours + ' hours.'));
    }
    $('#tatoLog').appendChild(card);
    $('#tatoLog').scrollTop = $('#tatoLog').scrollHeight;
  }
  function tatoSend(text) {
    text = (text || '').trim();
    if (!text || tatoBusy) return;
    tatoBusy = true;
    $('#tatoSend').disabled = true;
    $('#tatoChips').hidden = true;
    tatoBubble('me', text);
    var typing = tatoBubble('bot typing', '');
    typing.appendChild(h('span', 'dots-anim'));
    api('agent', { message: text }).then(function (d) {
      typing.remove();
      setMe(d.me);
      tatoBubble('bot', d.reply);
      d.cards.forEach(tatoCard);
      if (S.view === 'wallet') loadWallet();
    }, function (e) {
      typing.remove();
      tatoBubble('bot error', e.message);
    }).then(function () {
      tatoBusy = false;
      $('#tatoSend').disabled = false;
      $('#tatoInput').focus();
    });
  }
  function openTato(prefill) {
    if (!$('#tatoSheet')) return;
    openSheet('tatoSheet');
    if (!$('#tatoLog').children.length) {
      tatoBubble('bot', 'Hi ' + (S.me ? S.me.username : 'there') + '! I’m Tato, your AI wallet agent. I can check your coins, open a PayPal checkout, set a weekly spending limit, or set up a contest. You approve every payment and contest.');
    }
    if (prefill) tatoSend(prefill);
    else setTimeout(function () { $('#tatoInput').focus(); }, 300);
  }
  if ($('#tatoFab')) {
    $('#tatoFab').addEventListener('click', function () { openTato(); });
    $('#tatoForm').addEventListener('submit', function (ev) {
      ev.preventDefault();
      var t = $('#tatoInput').value;
      $('#tatoInput').value = '';
      tatoSend(t);
    });
    $$('#tatoChips button').forEach(function (b) { b.addEventListener('click', function () { tatoSend(b.textContent); }); });
    $('#tatoReset').addEventListener('click', function () {
      api('agent_reset', {}).catch(function () {});
      $('#tatoLog').textContent = '';
      $('#tatoChips').hidden = false;
      openTato();
    });
  }

  /* ------------------------------------------------------------ boot */

  $$('.flash-host .flash').forEach(function (f) {
    setTimeout(function () { f.remove(); }, 7000);
  });
  route();
  pollLobby();
  var rain = +body.dataset.coinRain;
  if (rain > 0) setTimeout(function () { coinRain(rain, $('.flash-host')); }, 700);
})();
