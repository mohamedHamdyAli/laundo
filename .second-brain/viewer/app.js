/*
 * Second Brain — interactive graph explorer.
 *
 * READ-ONLY. Every community, module, layer and relationship shown here was
 * decided by the PHP indexer and arrives pre-computed in `data/graph.js`.
 * Nothing in this file ranks, scores, detects a feature or infers a
 * relationship — if the picture and `brain.php search` ever disagree, the
 * picture is wrong.
 *
 * Two implementation notes that matter at this size (5,580 nodes / 20,052
 * edges):
 *
 *  - **Canvas, not DOM.** One element. Nodes are arcs and edges are strokes,
 *    batched into as few paths as possible so the per-frame cost is geometry
 *    rather than layout.
 *  - **Grid-bucketed repulsion.** A naive force-directed layout compares every
 *    pair, which here is 31 million comparisons per tick and janks the tab to a
 *    stop. Nodes are bucketed into a uniform spatial grid and only compared
 *    with neighbours in adjacent cells, which is O(n·k) and holds 60fps.
 */

(function () {
  'use strict';

  const DATA = window.__SECOND_BRAIN__;
  if (!DATA) {
    document.getElementById('loading').innerHTML =
      '<p>No graph data.</p><small>Run <code>php .second-brain/bin/brain.php visualize</code></small>';
    return;
  }

  // ------------------------------------------------------------------ colour

  /* One hue per community, spread around the wheel and kept away from the
   * accent blue so a selection never reads as a community. */
  const COMMUNITY_COLOURS = [
    '#6ee7b7', '#fbbf24', '#f472b6', '#a78bfa', '#34d399', '#fb923c',
    '#60a5fa', '#f87171', '#c084fc', '#4ade80', '#facc15', '#fca5a5',
    '#22d3ee', '#e879f9', '#a3e635', '#fdba74', '#93c5fd',
  ];

  /* Shape encodes the layer, colour encodes the community, so the two
   * questions — "what kind of thing is this" and "where does it belong" —
   * never fight over the same channel. */
  const LAYER_SHAPE = {
    controller: 'square', 'api-controller': 'square',
    service: 'diamond',
    model: 'hex',
    repository: 'triangle',
    route: 'pill',
    migration: 'cross',
    view: 'square-r',
    test: 'ring', 'browser-test': 'ring',
    config: 'cross', enum: 'triangle', request: 'triangle',
    command: 'diamond', middleware: 'cross', permission: 'ring',
    database: 'hex', feature: 'star',
  };

  const T = DATA.tables;
  const typeName = (i) => T.type[i] || '?';
  const layerName = (i) => T.layer[i] || '';
  const moduleName = (i) => T.module[i] || '';
  const communitySlug = (i) => T.community[i] || '';
  const edgeName = (i) => T.edge[i] || '';

  const communityColour = (i) => COMMUNITY_COLOURS[i % COMMUNITY_COLOURS.length];

  // -------------------------------------------------------------- the model

  const N = DATA.nodes.length;
  const nodes = DATA.nodes;
  const edges = DATA.edges;

  // Positions/velocities in flat arrays: one allocation, no per-node objects
  // to chase through memory on every tick.
  const px = new Float32Array(N);
  const py = new Float32Array(N);
  const vx = new Float32Array(N);
  const vy = new Float32Array(N);
  const radius = new Float32Array(N);
  const visible = new Uint8Array(N);
  const pinned = new Uint8Array(N);

  // Adjacency, built once. `neighbourStart` indexes into `neighbour`.
  const degree = new Int32Array(N);
  for (let e = 0; e < edges.length; e++) {
    degree[edges[e][0]]++;
    degree[edges[e][1]]++;
  }
  const neighbourStart = new Int32Array(N + 1);
  for (let i = 0; i < N; i++) neighbourStart[i + 1] = neighbourStart[i] + degree[i];
  const neighbour = new Int32Array(neighbourStart[N]);
  const neighbourEdge = new Int32Array(neighbourStart[N]);
  {
    const cursor = neighbourStart.slice(0, N);
    for (let e = 0; e < edges.length; e++) {
      const a = edges[e][0], b = edges[e][1];
      neighbour[cursor[a]] = b; neighbourEdge[cursor[a]++] = e;
      neighbour[cursor[b]] = a; neighbourEdge[cursor[b]++] = e;
    }
  }

  // Radius from degree — the graph's own notion of importance, compressed so
  // one very connected node cannot swallow the canvas.
  let maxDegree = 1;
  for (let i = 0; i < N; i++) if (nodes[i].d > maxDegree) maxDegree = nodes[i].d;
  for (let i = 0; i < N; i++) {
    radius[i] = 2.6 + 7.4 * Math.sqrt(nodes[i].d / maxDegree);
  }

  /* Each community gets a fixed anchor on a wide circle, sized so a big
   * community gets more room than a small one, and every node is pulled gently
   * toward its own anchor.
   *
   * Without this the layout is a uniform cloud: `contains` edges make the graph
   * mostly a forest, repulsion spreads it evenly, and nothing expresses the
   * grouping the indexer already worked out. The anchors are the *only* thing
   * placing communities — which community a node belongs to is read straight
   * from the data and never computed here.
   */
  const communityCount = T.community.length;
  const anchorX = new Float32Array(communityCount);
  const anchorY = new Float32Array(communityCount);
  {
    const sized = T.community
      .map((slug, i) => ({ i, count: DATA.counts.community[i] || 0 }))
      .sort((a, b) => b.count - a.count);

    const total = sized.reduce((s, c) => s + Math.sqrt(c.count || 1), 0);
    let walked = 0;
    for (const { i, count } of sized) {
      const share = Math.sqrt(count || 1) / total;
      const angle = (walked + share / 2) * Math.PI * 2;
      walked += share;
      const ring = 1700 + 900 * Math.sqrt(count / Math.max(1, N));
      anchorX[i] = Math.cos(angle) * ring;
      anchorY[i] = Math.sin(angle) * ring;
    }
  }

  for (let i = 0; i < N; i++) {
    const c = nodes[i].c;
    const a = i * 2.399963;                       // golden angle
    const spread = 60 + Math.sqrt(nodes[i].d) * 12;
    px[i] = anchorX[c] + Math.cos(a) * spread * Math.random();
    py[i] = anchorY[c] + Math.sin(a) * spread * Math.random();
  }

  // ------------------------------------------------------------- filtering

  const activeCommunities = new Set(T.community.map((_, i) => i));
  const activeTypes = new Set(T.type.map((_, i) => i));

  function applyFilters() {
    for (let i = 0; i < N; i++) {
      visible[i] = activeCommunities.has(nodes[i].c) && activeTypes.has(nodes[i].t) ? 1 : 0;
    }
    if (selected !== -1 && !visible[selected]) select(-1);
    buildVisibleEdges();
    reheat(0.45);
  }

  let visibleEdges = [];
  function buildVisibleEdges() {
    visibleEdges = [];
    for (let e = 0; e < edges.length; e++) {
      if (visible[edges[e][0]] && visible[edges[e][1]]) visibleEdges.push(e);
    }
  }

  // ------------------------------------------------------------- simulation

  const CELL = 60;                 // spatial-hash cell, ~ the largest radius × 6
  const REPULSION = 1400;
  const SPRING = 0.0022;
  const SPRING_LENGTH = 40;
  const COMMUNITY_PULL = 0.016;    // what makes the clusters read as clusters
  const CENTRE_PULL = 0.0002;      // only enough to stop islands escaping
  const DAMPING = 0.88;
  const DECAY = 0.994;             // slower: the layout needs time to separate

  let alpha = 1;
  const grid = new Map();

  function cellKey(x, y) {
    return ((x / CELL) | 0) * 73856093 ^ ((y / CELL) | 0) * 19349663;
  }

  function tick() {
    if (alpha < 0.005) return false;

    grid.clear();
    for (let i = 0; i < N; i++) {
      if (!visible[i]) continue;
      const k = cellKey(px[i], py[i]);
      let bucket = grid.get(k);
      if (!bucket) { bucket = []; grid.set(k, bucket); }
      bucket.push(i);
    }

    // Repulsion — only against nodes in the same and neighbouring cells.
    for (const bucket of grid.values()) {
      for (let bi = 0; bi < bucket.length; bi++) {
        const i = bucket[bi];
        const xi = px[i], yi = py[i];

        for (let dx = -1; dx <= 1; dx++) {
          for (let dy = -1; dy <= 1; dy++) {
            const other = grid.get(cellKey(xi + dx * CELL, yi + dy * CELL));
            if (!other) continue;

            for (let bj = 0; bj < other.length; bj++) {
              const j = other[bj];
              if (j <= i) continue;

              let ddx = xi - px[j], ddy = yi - py[j];
              let d2 = ddx * ddx + ddy * ddy;
              if (d2 > CELL * CELL * 4 || d2 === 0) {
                if (d2 === 0) { ddx = (Math.random() - 0.5) * 0.1; ddy = (Math.random() - 0.5) * 0.1; d2 = 0.01; }
                else continue;
              }

              const force = (REPULSION * alpha) / d2;
              const d = Math.sqrt(d2);
              const fx = (ddx / d) * force, fy = (ddy / d) * force;
              vx[i] += fx; vy[i] += fy;
              vx[j] -= fx; vy[j] -= fy;
            }
          }
        }
      }
    }

    // Springs along visible edges.
    for (let k = 0; k < visibleEdges.length; k++) {
      const e = edges[visibleEdges[k]];
      const a = e[0], b = e[1];
      const ddx = px[b] - px[a], ddy = py[b] - py[a];
      const d = Math.sqrt(ddx * ddx + ddy * ddy) || 0.01;
      const force = (d - SPRING_LENGTH) * SPRING * alpha;
      const fx = (ddx / d) * force, fy = (ddy / d) * force;
      vx[a] += fx; vy[a] += fy;
      vx[b] -= fx; vy[b] -= fy;
    }

    // Community gravity, plus a much weaker pull to the origin so nothing
    // drifts away entirely.
    for (let i = 0; i < N; i++) {
      if (!visible[i] || pinned[i]) continue;

      const c = nodes[i].c;
      vx[i] += (anchorX[c] - px[i]) * COMMUNITY_PULL * alpha;
      vy[i] += (anchorY[c] - py[i]) * COMMUNITY_PULL * alpha;

      vx[i] -= px[i] * CENTRE_PULL * alpha;
      vy[i] -= py[i] * CENTRE_PULL * alpha;

      vx[i] *= DAMPING; vy[i] *= DAMPING;
      px[i] += vx[i]; py[i] += vy[i];
    }

    alpha *= DECAY;
    return true;
  }

  function reheat(to) { alpha = Math.max(alpha, to); requestFrame(); }

  // ---------------------------------------------------------------- canvas

  const canvas = document.getElementById('graph');
  const ctx = canvas.getContext('2d', { alpha: false });
  let width = 0, height = 0, dpr = 1;

  let scale = 0.17, offsetX = 0, offsetY = 0;

  function resize() {
    dpr = Math.min(window.devicePixelRatio || 1, 2);
    width = canvas.clientWidth; height = canvas.clientHeight;
    canvas.width = Math.round(width * dpr);
    canvas.height = Math.round(height * dpr);
    requestFrame();
  }
  window.addEventListener('resize', resize);

  const toScreenX = (x) => (x * scale) + offsetX + width / 2;
  const toScreenY = (y) => (y * scale) + offsetY + height / 2;
  const toWorldX = (sx) => (sx - offsetX - width / 2) / scale;
  const toWorldY = (sy) => (sy - offsetY - height / 2) / scale;

  let selected = -1;
  let hovered = -1;
  const related = new Set();

  function draw() {
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.fillStyle = '#0b0f14';
    ctx.fillRect(0, 0, width, height);

    const focusing = selected !== -1;

    // ---- edges, in two batches: background then emphasised -------------
    ctx.lineWidth = Math.max(0.4, 0.6 * scale);
    ctx.strokeStyle = focusing ? 'rgba(120,140,165,0.04)' : 'rgba(120,140,165,0.075)';
    // The transform is inlined rather than called through toScreenX/Y: this
    // loop runs 20,000 times a frame and four function calls per edge was
    // 80,000 per frame on its own.
    const ox = offsetX + width / 2, oy = offsetY + height / 2, sc = scale;

    ctx.beginPath();
    for (let k = 0; k < visibleEdges.length; k++) {
      const e = edges[visibleEdges[k]];
      if (focusing && (e[0] === selected || e[1] === selected)) continue;
      const ax = px[e[0]] * sc + ox, ay = py[e[0]] * sc + oy;
      const bx = px[e[1]] * sc + ox, by = py[e[1]] * sc + oy;
      if ((ax < -50 && bx < -50) || (ax > width + 50 && bx > width + 50)) continue;
      if ((ay < -50 && by < -50) || (ay > height + 50 && by > height + 50)) continue;
      ctx.moveTo(ax, ay); ctx.lineTo(bx, by);
    }
    ctx.stroke();

    if (focusing) {
      ctx.lineWidth = Math.max(1, 1.4 * scale);
      ctx.strokeStyle = 'rgba(92,200,255,0.55)';
      ctx.beginPath();
      for (let k = 0; k < visibleEdges.length; k++) {
        const e = edges[visibleEdges[k]];
        if (e[0] !== selected && e[1] !== selected) continue;
        ctx.moveTo(px[e[0]] * sc + ox, py[e[0]] * sc + oy);
        ctx.lineTo(px[e[1]] * sc + ox, py[e[1]] * sc + oy);
      }
      ctx.stroke();
    }

    // ---- nodes ----------------------------------------------------------
    for (let i = 0; i < N; i++) {
      if (!visible[i]) continue;
      const x = px[i] * sc + ox, y = py[i] * sc + oy;
      const r = radius[i] * sc;
      if (x < -r - 10 || x > width + r + 10 || y < -r - 10 || y > height + r + 10) continue;

      const isSelected = i === selected;
      const isRelated = focusing && related.has(i);
      const dim = focusing && !isSelected && !isRelated;

      ctx.globalAlpha = dim ? 0.12 : 1;
      ctx.fillStyle = communityColour(nodes[i].c);
      drawShape(x, y, Math.max(1.4, r), LAYER_SHAPE[layerName(nodes[i].l)] || 'circle');

      if (isSelected || i === hovered) {
        ctx.globalAlpha = 1;
        ctx.strokeStyle = isSelected ? '#ffffff' : '#5cc8ff';
        ctx.lineWidth = isSelected ? 2.2 : 1.4;
        ctx.beginPath();
        ctx.arc(x, y, Math.max(3, r) + 3.5, 0, 6.283185);
        ctx.stroke();
      }
    }
    ctx.globalAlpha = 1;

    // ---- labels, only when there is room --------------------------------
    const labelThreshold = scale > 1.5 ? 0 : scale > 0.9 ? 6 : scale > 0.55 ? 14 : 1e9;
    if (labelThreshold < 1e9 || focusing) {
      ctx.font = '11px ui-sans-serif, system-ui, sans-serif';
      ctx.textAlign = 'center';
      ctx.textBaseline = 'top';
      for (let i = 0; i < N; i++) {
        if (!visible[i]) continue;
        const important = i === selected || (focusing && related.has(i)) || i === hovered;
        if (!important && nodes[i].d < labelThreshold) continue;
        if (focusing && !important) continue;

        const x = toScreenX(px[i]), y = toScreenY(py[i]);
        if (x < -80 || x > width + 80 || y < -20 || y > height + 20) continue;

        const label = nodes[i].n;
        ctx.fillStyle = 'rgba(8,12,17,0.82)';
        const w = ctx.measureText(label).width;
        ctx.fillRect(x - w / 2 - 3, y + radius[i] * scale + 3, w + 6, 14);
        ctx.fillStyle = important ? '#ffffff' : 'rgba(223,231,239,0.72)';
        ctx.fillText(label, x, y + radius[i] * scale + 4);
      }
    }

    document.getElementById('scale').textContent =
      `${Math.round(scale * 100)}%  ·  ${visibleEdges.length.toLocaleString()} edges shown`;
  }

  function drawShape(x, y, r, shape) {
    ctx.beginPath();
    switch (shape) {
      case 'square': ctx.rect(x - r, y - r, r * 2, r * 2); break;
      case 'square-r': ctx.rect(x - r * 0.9, y - r * 0.9, r * 1.8, r * 1.8); break;
      case 'diamond':
        ctx.moveTo(x, y - r * 1.25); ctx.lineTo(x + r * 1.25, y);
        ctx.lineTo(x, y + r * 1.25); ctx.lineTo(x - r * 1.25, y); ctx.closePath();
        break;
      case 'triangle':
        ctx.moveTo(x, y - r * 1.3); ctx.lineTo(x + r * 1.15, y + r * 0.9);
        ctx.lineTo(x - r * 1.15, y + r * 0.9); ctx.closePath();
        break;
      case 'hex':
        for (let k = 0; k < 6; k++) {
          const a = Math.PI / 6 + k * Math.PI / 3;
          const hx = x + Math.cos(a) * r * 1.15, hy = y + Math.sin(a) * r * 1.15;
          k === 0 ? ctx.moveTo(hx, hy) : ctx.lineTo(hx, hy);
        }
        ctx.closePath();
        break;
      case 'pill': ctx.roundRect ? ctx.roundRect(x - r * 1.5, y - r * 0.75, r * 3, r * 1.5, r) : ctx.rect(x - r * 1.5, y - r * 0.75, r * 3, r * 1.5); break;
      case 'cross':
        ctx.rect(x - r * 1.25, y - r * 0.42, r * 2.5, r * 0.84);
        ctx.rect(x - r * 0.42, y - r * 1.25, r * 0.84, r * 2.5);
        break;
      case 'star':
        for (let k = 0; k < 10; k++) {
          const a = -Math.PI / 2 + k * Math.PI / 5;
          const rr = k % 2 ? r * 0.55 : r * 1.4;
          const sx = x + Math.cos(a) * rr, sy = y + Math.sin(a) * rr;
          k === 0 ? ctx.moveTo(sx, sy) : ctx.lineTo(sx, sy);
        }
        ctx.closePath();
        break;
      case 'ring':
        ctx.arc(x, y, r, 0, 6.283185);
        ctx.fill();
        ctx.beginPath();
        ctx.arc(x, y, r * 0.45, 0, 6.283185);
        ctx.fillStyle = '#0b0f14';
        break;
      default: ctx.arc(x, y, r, 0, 6.283185);
    }
    ctx.fill();
  }

  // ------------------------------------------------------------ frame loop

  let frameQueued = false;
  function requestFrame() {
    if (frameQueued) return;
    frameQueued = true;
    requestAnimationFrame(() => {
      frameQueued = false;
      const moving = tick();
      draw();
      if (moving) requestFrame();
    });
  }

  // ------------------------------------------------------------- hit-test

  function nodeAt(sx, sy) {
    const wx = toWorldX(sx), wy = toWorldY(sy);
    let best = -1, bestD = Infinity;
    const reach = 16 / scale;
    for (let i = 0; i < N; i++) {
      if (!visible[i]) continue;
      const dx = px[i] - wx, dy = py[i] - wy;
      const d = Math.sqrt(dx * dx + dy * dy);
      if (d < Math.max(radius[i], reach) && d < bestD) { bestD = d; best = i; }
    }
    return best;
  }

  // ------------------------------------------------------- pointer control

  let dragging = false, draggingNode = -1, lastX = 0, lastY = 0, moved = false;

  canvas.addEventListener('pointerdown', (ev) => {
    canvas.setPointerCapture(ev.pointerId);
    lastX = ev.offsetX; lastY = ev.offsetY; moved = false;
    draggingNode = nodeAt(ev.offsetX, ev.offsetY);
    dragging = true;
    canvas.classList.add('dragging');
    if (draggingNode !== -1) { pinned[draggingNode] = 1; reheat(0.3); }
  });

  canvas.addEventListener('pointermove', (ev) => {
    if (dragging) {
      const dx = ev.offsetX - lastX, dy = ev.offsetY - lastY;
      if (Math.abs(dx) + Math.abs(dy) > 2) moved = true;
      if (draggingNode !== -1) {
        px[draggingNode] = toWorldX(ev.offsetX);
        py[draggingNode] = toWorldY(ev.offsetY);
        vx[draggingNode] = vy[draggingNode] = 0;
        reheat(0.25);
      } else {
        offsetX += dx; offsetY += dy;
        requestFrame();
      }
      lastX = ev.offsetX; lastY = ev.offsetY;
      return;
    }

    const hit = nodeAt(ev.offsetX, ev.offsetY);
    canvas.classList.toggle('on-node', hit !== -1);
    if (hit !== hovered) { hovered = hit; requestFrame(); }
    showTooltip(hit, ev.offsetX, ev.offsetY);
  });

  canvas.addEventListener('pointerup', (ev) => {
    canvas.classList.remove('dragging');
    if (!moved) {
      const hit = nodeAt(ev.offsetX, ev.offsetY);
      select(hit);
    }
    if (draggingNode !== -1) pinned[draggingNode] = 0;
    dragging = false; draggingNode = -1;
  });

  canvas.addEventListener('pointerleave', () => {
    hovered = -1;
    document.getElementById('tooltip').hidden = true;
    requestFrame();
  });

  canvas.addEventListener('dblclick', (ev) => {
    const hit = nodeAt(ev.offsetX, ev.offsetY);
    if (hit !== -1) focusNode(hit);
  });

  canvas.addEventListener('wheel', (ev) => {
    ev.preventDefault();
    const factor = Math.exp(-ev.deltaY * 0.0014);
    const next = Math.min(6, Math.max(0.08, scale * factor));
    // Zoom about the cursor rather than the centre.
    const wx = toWorldX(ev.offsetX), wy = toWorldY(ev.offsetY);
    scale = next;
    offsetX = ev.offsetX - width / 2 - wx * scale;
    offsetY = ev.offsetY - height / 2 - wy * scale;
    requestFrame();
  }, { passive: false });

  // ------------------------------------------------------------ selection

  function select(i) {
    selected = i;
    related.clear();
    if (i !== -1) {
      for (let k = neighbourStart[i]; k < neighbourStart[i + 1]; k++) {
        if (visible[neighbour[k]]) related.add(neighbour[k]);
      }
    }
    renderInfo(i);
    requestFrame();
  }

  function focusNode(i) {
    select(i);
    scale = Math.max(scale, 1.4);
    offsetX = -px[i] * scale;
    offsetY = -py[i] * scale;
    requestFrame();
  }

  function showTooltip(i, x, y) {
    const tip = document.getElementById('tooltip');
    if (i === -1) { tip.hidden = true; return; }
    const node = nodes[i];
    tip.innerHTML =
      `<div class="t-name">${escapeHtml(node.n)}</div>` +
      `<div class="t-meta">${escapeHtml(typeName(node.t))}` +
      (layerName(node.l) ? ` · ${escapeHtml(layerName(node.l))}` : '') +
      (moduleName(node.m) ? ` · ${escapeHtml(moduleName(node.m))}` : '') +
      `</div>` +
      (node.p ? `<div class="t-meta">${escapeHtml(node.p)}</div>` : '');
    tip.hidden = false;
    const rect = tip.getBoundingClientRect();
    tip.style.left = Math.min(x + 14, width - rect.width - 8) + 'px';
    tip.style.top = Math.min(y + 14, height - rect.height - 8) + 'px';
  }

  // ------------------------------------------------------------ node info

  function renderInfo(i) {
    const box = document.getElementById('info');
    if (i === -1) {
      box.className = 'empty';
      box.textContent = 'Click a node to inspect it.';
      return;
    }

    const node = nodes[i];
    box.className = '';

    const outgoing = [], incoming = [];
    for (let k = neighbourStart[i]; k < neighbourStart[i + 1]; k++) {
      const e = edges[neighbourEdge[k]];
      const other = neighbour[k];
      if (!visible[other]) continue;
      (e[0] === i ? outgoing : incoming).push({ other, type: e[2] });
    }

    const colour = communityColour(node.c);
    const slug = communitySlug(node.c);
    const meta = DATA.communities[slug];

    let html =
      `<div class="i-name"><span class="dot" style="background:${colour};width:10px;height:10px;border-radius:50%;display:inline-block"></span>` +
      `${escapeHtml(node.n)}</div>`;

    if (node.s) html += `<p class="i-sum">${escapeHtml(node.s)}</p>`;

    html += '<dl class="i-grid">';
    html += row('Type', typeName(node.t));
    if (layerName(node.l)) html += row('Layer', layerName(node.l));
    if (moduleName(node.m)) html += row('Module', moduleName(node.m));
    html += row('Community', meta ? `${meta.title}` : (slug || '—'));
    html += row('Connections', String(node.d));
    html += '</dl>';

    if (node.p) {
      html +=
        `<div class="i-path" data-copy="${escapeAttr(node.p)}" title="Click to copy">` +
        `${escapeHtml(node.p)}<span class="hint">click to copy · repository path</span></div>`;
    }

    html += relationList('Outgoing', outgoing, true);
    html += relationList('Incoming', incoming, false);

    box.innerHTML = html;

    const path = box.querySelector('.i-path');
    if (path) {
      path.addEventListener('click', () => {
        navigator.clipboard?.writeText(path.dataset.copy);
        const hint = path.querySelector('.hint');
        hint.textContent = 'copied';
        setTimeout(() => { hint.textContent = 'click to copy · repository path'; }, 1400);
      });
    }

    box.querySelectorAll('li[data-node]').forEach((li) => {
      li.addEventListener('click', () => focusNode(Number(li.dataset.node)));
    });
  }

  function relationList(heading, list, outgoing) {
    if (!list.length) return '';
    const shown = list.slice(0, 40);
    let html = `<div class="i-rel"><h3>${heading} · ${list.length}</h3><ul>`;
    for (const item of shown) {
      const other = nodes[item.other];
      html +=
        `<li data-node="${item.other}" title="${escapeAttr(other.p || other.n)}">` +
        `<span class="l-edge">${escapeHtml(edgeName(item.type))}</span>` +
        `<span class="l-name">${escapeHtml(other.n)}</span></li>`;
    }
    html += '</ul>';
    if (list.length > shown.length) {
      html += `<div class="i-more">+ ${list.length - shown.length} more</div>`;
    }
    return html + '</div>';
  }

  const row = (k, v) => `<dt>${escapeHtml(k)}</dt><dd>${escapeHtml(v)}</dd>`;

  // --------------------------------------------------------------- search

  const searchBox = document.getElementById('search');
  const resultsBox = document.getElementById('results');
  let searchTimer = null;

  searchBox.addEventListener('input', () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(runSearch, 110);
  });

  searchBox.addEventListener('keydown', (ev) => {
    if (ev.key === 'Escape') { searchBox.value = ''; resultsBox.hidden = true; }
    if (ev.key === 'Enter') {
      const first = resultsBox.querySelector('.row');
      if (first) first.click();
    }
  });

  function runSearch() {
    const q = searchBox.value.trim().toLowerCase();
    if (q.length < 2) { resultsBox.hidden = true; return; }

    const hits = [];
    for (let i = 0; i < N; i++) {
      if (!visible[i]) continue;
      const node = nodes[i];
      const name = node.n.toLowerCase();

      // Name, then path, then the extra identifiers the adapter carried over
      // (fqcn, route uri, table, permission slug). Ranked by how early and how
      // completely the term matches — this orders a *list*, it is not the
      // brain's ranking and does not pretend to be.
      let score = -1;
      if (name === q) score = 0;
      else if (name.startsWith(q)) score = 1;
      else if (name.includes(q)) score = 2;
      else if ((node.p || '').toLowerCase().includes(q)) score = 3;
      else if ((node.k || '').toLowerCase().includes(q)) score = 4;
      else if (moduleName(node.m).toLowerCase().includes(q)) score = 5;
      if (score === -1) continue;

      hits.push({ i, score, degree: node.d });
      if (hits.length > 600) break;
    }

    hits.sort((a, b) => a.score - b.score || b.degree - a.degree);
    const shown = hits.slice(0, 40);

    if (!shown.length) {
      resultsBox.innerHTML = '<div class="none">No matching node.</div>';
      resultsBox.hidden = false;
      return;
    }

    resultsBox.innerHTML = shown.map(({ i }) => {
      const node = nodes[i];
      return `<div class="row" data-node="${i}">` +
        `<div class="r-name"><span style="width:8px;height:8px;border-radius:50%;background:${communityColour(node.c)};display:inline-block"></span>` +
        `${escapeHtml(node.n)} <span class="l-edge">${escapeHtml(typeName(node.t))}</span></div>` +
        (node.p ? `<div class="r-path">${escapeHtml(node.p)}</div>` : '') +
        `</div>`;
    }).join('');
    resultsBox.hidden = false;

    resultsBox.querySelectorAll('.row').forEach((row) => {
      row.addEventListener('click', () => {
        focusNode(Number(row.dataset.node));
        resultsBox.hidden = true;
      });
    });
  }

  document.addEventListener('click', (ev) => {
    if (!ev.target.closest('.search')) resultsBox.hidden = true;
  });

  // ------------------------------------------------------------- checkboxes

  function buildCommunities() {
    const box = document.getElementById('communities');
    const order = T.community
      .map((slug, i) => ({ slug, i, count: DATA.counts.community[i] || 0 }))
      .filter((c) => c.count > 0)
      .sort((a, b) => b.count - a.count);

    box.innerHTML = order.map(({ slug, i, count }) => {
      const meta = DATA.communities[slug];
      const label = meta ? meta.title : (slug || 'unassigned');
      return `<label class="check" data-community="${i}">` +
        `<input type="checkbox" checked>` +
        `<span class="dot" style="background:${communityColour(i)}"></span>` +
        `<span class="label" title="${escapeAttr(label)}">${escapeHtml(label)}</span>` +
        `<span class="count">${count.toLocaleString()}</span></label>`;
    }).join('');

    box.querySelectorAll('.check').forEach((el) => {
      const i = Number(el.dataset.community);
      el.querySelector('input').addEventListener('change', (ev) => {
        ev.target.checked ? activeCommunities.add(i) : activeCommunities.delete(i);
        el.classList.toggle('off', !ev.target.checked);
        applyFilters();
      });
    });
  }

  function buildTypes() {
    const box = document.getElementById('types');
    const order = T.type
      .map((name, i) => ({ name, i, count: DATA.counts.type[i] || 0 }))
      .sort((a, b) => b.count - a.count);

    box.innerHTML = order.map(({ name, i, count }) =>
      `<label class="check" data-type="${i}">` +
      `<input type="checkbox" checked>` +
      `<span class="label">${escapeHtml(name)}</span>` +
      `<span class="count">${count.toLocaleString()}</span></label>`
    ).join('');

    box.querySelectorAll('.check').forEach((el) => {
      const i = Number(el.dataset.type);
      el.querySelector('input').addEventListener('change', (ev) => {
        ev.target.checked ? activeTypes.add(i) : activeTypes.delete(i);
        el.classList.toggle('off', !ev.target.checked);
        applyFilters();
      });
    });
  }

  function setAll(selector, set, all, on) {
    document.querySelectorAll(selector + ' .check').forEach((el) => {
      el.querySelector('input').checked = on;
      el.classList.toggle('off', !on);
    });
    set.clear();
    if (on) all.forEach((_, i) => set.add(i));
    applyFilters();
  }

  document.getElementById('select-all').onclick = () => setAll('#communities', activeCommunities, T.community, true);
  document.getElementById('clear-all').onclick = () => setAll('#communities', activeCommunities, T.community, false);
  document.getElementById('types-all').onclick = () => setAll('#types', activeTypes, T.type, true);
  document.getElementById('types-none').onclick = () => setAll('#types', activeTypes, T.type, false);

  function buildLegend() {
    const shapes = [
      ['controller', 'square'], ['service', 'diamond'], ['model', 'hex'],
      ['repository', 'triangle'], ['route', 'pill'], ['view', 'square-r'],
      ['migration', 'cross'], ['test', 'ring'],
    ];
    document.getElementById('legend').innerHTML =
      '<span>shape = layer:</span>' +
      shapes.map(([name]) => `<span><i style="background:#8b9aab"></i>${name}</span>`).join('') +
      '<span>· colour = community</span>';
  }

  // ------------------------------------------------------------------ util

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, (c) =>
      ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }
  const escapeAttr = escapeHtml;

  // ------------------------------------------------------------------ boot

  document.getElementById('meta').textContent =
    `${N.toLocaleString()} nodes · ${edges.length.toLocaleString()} edges · indexed ${(DATA.built_at || '').slice(0, 10)}`;
  document.getElementById('title').textContent = DATA.repository || 'Second Brain';

  buildCommunities();
  buildTypes();
  buildLegend();
  applyFilters();
  resize();

  /* Spin the layout for a few hundred ticks before the first paint, so the
   * user meets an arranged graph rather than watching a hairball unfold. */
  const WARM_TICKS = 600;
  const detail = document.getElementById('loading-detail');
  let warm = 0;
  (function warmUp() {
    const until = performance.now() + 60;
    while (performance.now() < until && warm < WARM_TICKS) { tick(); warm++; }
    detail.textContent = `${Math.round((warm / WARM_TICKS) * 100)}%`;
    if (warm < WARM_TICKS && alpha > 0.005) {
      requestAnimationFrame(warmUp);
    } else {
      document.getElementById('loading').hidden = true;
      alpha = Math.max(alpha, 0.25);
      requestFrame();
    }
  })();
})();
