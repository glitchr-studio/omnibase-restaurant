/*
 * The floor editor of omnibase/restaurant (/admin/restaurant/salle): a room
 * drawn to scale in SVG - centimetres -, its tables and its decor dragged
 * on a snapping grid, turned, duplicated, removed; in a layout, tables
 * joined to seat a larger party. Saved as JSON (FloorController::save).
 *
 * A plain script: the back office has no Stimulus of the site. It starts
 * on every [data-restaurant-floor] not started yet, at load and after each
 * of the back office's page swaps (transparent.js dispatches `load` again).
 *
 *   click            select (shift: add to the selection, to join)
 *   drag             move, on the grid
 *   arrows           move by one step (shift: ten)
 *   R / D / Delete   turn by 45°, duplicate, remove
 */
(function () {
    'use strict';

    var NS = 'http://www.w3.org/2000/svg';
    var DEFAULTS = {
        square: { w: 80, h: 80, min: 1, max: 2 },
        round: { w: 90, h: 90, min: 2, max: 4 },
        rectangle: { w: 160, h: 80, min: 2, max: 6 },
        counter: { w: 240, h: 50, min: 1, max: 4 },
    };
    var DECOR = {
        wall: { w: 300, h: 15 }, counter: { w: 300, h: 60 }, door: { w: 90, h: 15 }, window: { w: 120, h: 10 },
        kitchen: { w: 300, h: 200 }, plant: { w: 50, h: 50 }, label: { w: 160, h: 40, label: 'Entrée' },
    };

    function el(name, attrs, parent) {
        var node = document.createElementNS(NS, name);
        Object.keys(attrs || {}).forEach(function (key) { node.setAttribute(key, attrs[key]); });
        if (parent) parent.appendChild(node);
        return node;
    }

    function Editor(root) {
        this.root = root;
        this.data = JSON.parse(root.querySelector('[data-floor-data]').textContent);
        this.svg = root.querySelector('[data-svg]');
        this.props = root.querySelector('[data-props]');
        this.status = root.querySelector('[data-status]');
        this.texts = this.data.texts || {};
        this.snap = 10;
        this.seq = 0;
        this.removed = [];
        this.selected = [];
        this.dirty = false;
        var self = this;
        this.tables = this.data.tables.map(function (t) { return Object.assign({ kind: 'table', key: String(t.id) }, t); });
        this.decor = (this.data.room.decor || []).map(function (d) { self.seq++; return Object.assign({ kind: 'decor', key: 'd' + self.seq, rotation: 0 }, d); });
        this.joins = (this.data.joins || []).map(function (g) { return g.map(String); });
        this.bind();
        this.draw();
        this.showProps();
    }

    Editor.prototype.items = function () { return this.tables.concat(this.decor); };
    Editor.prototype.find = function (key) { return this.items().filter(function (i) { return i.key === key; })[0]; };

    Editor.prototype.bind = function () {
        var self = this;
        this.root.querySelectorAll('[data-add="table"]').forEach(function (b) {
            b.addEventListener('click', function () { self.addTable(b.dataset.shape); });
        });
        var decor = this.root.querySelector('[data-add-decor]');
        if (decor) decor.addEventListener('change', function () { if (decor.value) self.addDecor(decor.value); decor.value = ''; });
        this.root.querySelectorAll('[data-do]').forEach(function (b) {
            b.addEventListener('click', function () { self[b.dataset.do](); });
        });
        var snap = this.root.querySelector('[data-snap]');
        if (snap) snap.addEventListener('change', function () { self.snap = parseInt(snap.value, 10) || 1; });
        this.root.querySelectorAll('[data-room]').forEach(function (input) {
            input.addEventListener('change', function () {
                self.data.room[input.dataset.room] = Math.max(300, parseInt(input.value, 10) || 300);
                self.touch();
                self.draw();
            });
        });
        this.svg.addEventListener('pointerdown', function (e) { self.down(e); });
        this.svg.addEventListener('pointermove', function (e) { self.move(e); });
        this.svg.addEventListener('pointerup', function (e) { self.up(e); });
        this.svg.addEventListener('pointercancel', function (e) { self.up(e); });
        this.svg.setAttribute('tabindex', '0');
        this.svg.addEventListener('keydown', function (e) { self.key(e); });
        window.addEventListener('beforeunload', function (e) { if (self.dirty) { e.preventDefault(); e.returnValue = ''; } });
    };

    Editor.prototype.touch = function () {
        this.dirty = true;
        if (this.status) { this.status.textContent = this.texts.unsaved || '…'; this.status.className = 'restaurant-editor-status is-dirty'; }
    };

    Editor.prototype.point = function (e) {
        var p = this.svg.createSVGPoint();
        p.x = e.clientX; p.y = e.clientY;
        return p.matrixTransform(this.svg.getScreenCTM().inverse());
    };

    Editor.prototype.round = function (v) { return Math.round(v / this.snap) * this.snap; };

    Editor.prototype.nextLabel = function () {
        var max = 0;
        this.tables.forEach(function (t) { var n = parseInt(t.label, 10); if (!isNaN(n) && n > max) max = n; });
        return String(max + 1);
    };

    Editor.prototype.center = function () {
        return { x: this.round(this.data.room.width / 2), y: this.round(this.data.room.height / 2) };
    };

    Editor.prototype.addTable = function (shape) {
        var d = DEFAULTS[shape] || DEFAULTS.square;
        var c = this.center();
        this.seq++;
        var t = { kind: 'table', key: 'new-' + this.seq, id: null, label: this.nextLabel(), min: d.min, max: d.max, shape: shape, x: c.x, y: c.y, w: d.w, h: d.h, rotation: 0, active: true };
        this.tables.push(t);
        this.select([t.key]);
        this.touch();
        this.draw();
    };

    Editor.prototype.addDecor = function (type) {
        var d = DECOR[type] || DECOR.wall;
        var c = this.center();
        this.seq++;
        var item = { kind: 'decor', key: 'd' + this.seq, type: type, x: c.x, y: c.y, w: d.w, h: d.h, rotation: 0 };
        if (d.label) item.label = d.label;
        this.decor.push(item);
        this.select([item.key]);
        this.touch();
        this.draw();
    };

    Editor.prototype.duplicate = function () {
        var self = this;
        var copies = [];
        this.selected.map(function (k) { return self.find(k); }).filter(Boolean).forEach(function (item) {
            self.seq++;
            var copy = Object.assign({}, item, { x: item.x + 30, y: item.y + 30 });
            if (item.kind === 'table') {
                copy.key = 'new-' + self.seq; copy.id = null; copy.label = self.nextLabel(); delete copy.token;
                self.tables.push(copy);
            } else {
                copy.key = 'd' + self.seq;
                self.decor.push(copy);
            }
            copies.push(copy.key);
        });
        if (copies.length) { this.select(copies); this.touch(); this.draw(); }
    };

    Editor.prototype.rotate = function () {
        var self = this;
        this.selected.forEach(function (k) { var i = self.find(k); if (i) i.rotation = ((i.rotation || 0) + 45) % 360; });
        if (this.selected.length) { this.touch(); this.draw(); this.showProps(); }
    };

    Editor.prototype.remove = function () {
        var self = this;
        if (!this.selected.length) return;
        this.tables = this.tables.filter(function (t) {
            var gone = self.selected.indexOf(t.key) >= 0;
            if (gone && t.id) self.removed.push(t.id);
            return !gone;
        });
        this.decor = this.decor.filter(function (d) { return self.selected.indexOf(d.key) < 0; });
        this.joins = this.joins.map(function (g) { return g.filter(function (k) { return self.selected.indexOf(k) < 0; }); }).filter(function (g) { return g.length > 1; });
        this.selected = [];
        this.touch();
        this.draw();
        this.showProps();
    };

    Editor.prototype.join = function () {
        var self = this;
        var keys = this.selected.filter(function (k) { var i = self.find(k); return i && i.kind === 'table'; });
        if (keys.length < 2) { this.flash(this.texts.nothing || ''); return; }
        this.joins = this.joins.map(function (g) { return g.filter(function (k) { return keys.indexOf(k) < 0; }); }).filter(function (g) { return g.length > 1; });
        this.joins.push(keys);
        this.touch();
        this.draw();
    };

    Editor.prototype.unjoin = function () {
        var self = this;
        this.joins = this.joins.filter(function (g) { return !g.some(function (k) { return self.selected.indexOf(k) >= 0; }); });
        this.touch();
        this.draw();
    };

    Editor.prototype.select = function (keys, add) {
        if (add) {
            var self = this;
            keys.forEach(function (k) {
                var at = self.selected.indexOf(k);
                if (at >= 0) self.selected.splice(at, 1); else self.selected.push(k);
            });
        } else {
            this.selected = keys.slice();
        }
        this.showProps();
    };

    Editor.prototype.down = function (e) {
        var target = e.target.closest('[data-key]');
        if (!target) { this.select([]); this.draw(); return; }
        var key = target.getAttribute('data-key');
        if (e.shiftKey) { this.select([key], true); this.draw(); return; }
        if (this.selected.indexOf(key) < 0) this.select([key]);
        var p = this.point(e);
        var self = this;
        this.drag = { x: p.x, y: p.y, moved: false, start: this.selected.map(function (k) { var i = self.find(k); return i ? { item: i, x: i.x, y: i.y } : null; }).filter(Boolean) };
        try { this.svg.setPointerCapture(e.pointerId); } catch (x) { /* a synthetic pointer */ }
        this.draw();
        e.preventDefault();
    };

    Editor.prototype.move = function (e) {
        if (!this.drag) return;
        var p = this.point(e);
        var dx = p.x - this.drag.x, dy = p.y - this.drag.y;
        if (!this.drag.moved && Math.abs(dx) + Math.abs(dy) < 3) return;
        this.drag.moved = true;
        var self = this;
        var room = this.data.room;
        this.drag.start.forEach(function (s) {
            s.item.x = Math.max(0, Math.min(room.width, self.round(s.x + dx)));
            s.item.y = Math.max(0, Math.min(room.height, self.round(s.y + dy)));
        });
        this.draw();
    };

    Editor.prototype.up = function (e) {
        if (!this.drag) return;
        if (this.drag.moved) { this.touch(); this.showProps(); }
        this.drag = null;
        try { this.svg.releasePointerCapture(e.pointerId); } catch (x) { /* already released */ }
    };

    Editor.prototype.key = function (e) {
        if (e.target !== this.svg) return;
        var step = (e.shiftKey ? 10 : 1) * Math.max(this.snap, 1);
        var self = this;
        var moves = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] };
        if (moves[e.key] && this.selected.length) {
            this.selected.forEach(function (k) { var i = self.find(k); if (i) { i.x += moves[e.key][0]; i.y += moves[e.key][1]; } });
            this.touch(); this.draw(); this.showProps(); e.preventDefault();
        } else if (e.key === 'r' || e.key === 'R') { this.rotate(); e.preventDefault(); }
        else if (e.key === 'd' || e.key === 'D') { this.duplicate(); e.preventDefault(); }
        else if (e.key === 'Delete' || e.key === 'Backspace') { this.remove(); e.preventDefault(); }
    };

    Editor.prototype.draw = function () {
        var room = this.data.room;
        var svg = this.svg;
        var self = this;
        svg.setAttribute('viewBox', '-20 -20 ' + (room.width + 40) + ' ' + (room.height + 40));
        while (svg.firstChild) svg.removeChild(svg.firstChild);

        var defs = el('defs', {}, svg);
        var pattern = el('pattern', { id: 'restaurant-grid', width: 50, height: 50, patternUnits: 'userSpaceOnUse' }, defs);
        el('path', { d: 'M 50 0 L 0 0 0 50', fill: 'none', class: 'restaurant-editor-gridline' }, pattern);
        el('rect', { x: 0, y: 0, width: room.width, height: room.height, class: 'restaurant-editor-room', rx: 12 }, svg);
        el('rect', { x: 0, y: 0, width: room.width, height: room.height, fill: 'url(#restaurant-grid)', 'pointer-events': 'none' }, svg);
        // A metre, to read the scale.
        var scale = el('g', { class: 'restaurant-editor-scale', transform: 'translate(10 ' + (room.height - 14) + ')' }, svg);
        el('line', { x1: 0, y1: 0, x2: 100, y2: 0 }, scale);
        el('text', { x: 50, y: -6, 'text-anchor': 'middle' }, scale).textContent = '1 m';

        this.decor.forEach(function (d) {
            var g = el('g', { 'data-key': d.key, class: 'restaurant-editor-decor is-' + d.type + (self.selected.indexOf(d.key) >= 0 ? ' is-selected' : ''), transform: 'translate(' + d.x + ' ' + d.y + ') rotate(' + (d.rotation || 0) + ')' }, svg);
            el('rect', { x: -d.w / 2, y: -d.h / 2, width: d.w, height: d.h, rx: d.type === 'plant' ? d.w / 2 : 4 }, g);
            if (d.label) el('text', { y: 6, 'text-anchor': 'middle' }, g).textContent = d.label;
        });

        this.joins.forEach(function (group) {
            var points = group.map(function (k) { return self.find(k); }).filter(Boolean);
            for (var i = 1; i < points.length; i++) {
                el('line', { x1: points[i - 1].x, y1: points[i - 1].y, x2: points[i].x, y2: points[i].y, class: 'restaurant-editor-join' }, svg);
            }
        });

        this.tables.forEach(function (t) {
            var g = el('g', { 'data-key': t.key, class: 'restaurant-editor-table is-' + t.shape + (t.active ? '' : ' is-off') + (self.selected.indexOf(t.key) >= 0 ? ' is-selected' : ''), transform: 'translate(' + t.x + ' ' + t.y + ') rotate(' + (t.rotation || 0) + ')' }, svg);
            if (t.shape === 'round') el('ellipse', { rx: t.w / 2, ry: t.h / 2 }, g);
            else el('rect', { x: -t.w / 2, y: -t.h / 2, width: t.w, height: t.h, rx: t.shape === 'counter' ? 4 : 10 }, g);
            // The seats around it, as many as it takes at most.
            seats(t).forEach(function (s) { el('circle', { cx: s[0], cy: s[1], r: 9, class: 'restaurant-editor-seat' }, g); });
            var label = el('text', { y: 6, 'text-anchor': 'middle', transform: 'rotate(' + -(t.rotation || 0) + ')' }, g);
            label.textContent = t.label;
            var sub = el('text', { y: 24, 'text-anchor': 'middle', class: 'restaurant-editor-sub', transform: 'rotate(' + -(t.rotation || 0) + ')' }, g);
            sub.textContent = t.min + '–' + t.max;
        });
    };

    /** Seats drawn along a table's sides, evenly: a hint of how many it takes. */
    function seats(t) {
        var n = Math.max(1, t.max), list = [], i;
        if (t.shape === 'round') {
            for (i = 0; i < n; i++) {
                var a = (i / n) * Math.PI * 2 - Math.PI / 2;
                list.push([Math.cos(a) * (t.w / 2 + 14), Math.sin(a) * (t.h / 2 + 14)]);
            }
            return list;
        }
        if (t.shape === 'counter') {
            for (i = 0; i < n; i++) list.push([-t.w / 2 + (i + .5) * (t.w / n), t.h / 2 + 14]);
            return list;
        }
        var top = Math.ceil(n / 2), bottom = n - top;
        for (i = 0; i < top; i++) list.push([-t.w / 2 + (i + .5) * (t.w / top), -t.h / 2 - 14]);
        for (i = 0; i < bottom; i++) list.push([-t.w / 2 + (i + .5) * (t.w / bottom), t.h / 2 + 14]);
        return list;
    }

    Editor.prototype.showProps = function () {
        var form = this.props;
        var self = this;
        while (form.firstChild) form.removeChild(form.firstChild);
        var item = this.selected.length === 1 ? this.find(this.selected[0]) : null;
        if (!item) {
            var p = document.createElement('p');
            p.className = 'muted';
            p.textContent = this.selected.length > 1 ? this.selected.length + ' ×' : (this.texts.nothing || '');
            form.appendChild(p);
            return;
        }
        var t = this.texts;
        var field = function (label, name, type, value, extra) {
            var wrap = document.createElement('label');
            wrap.textContent = label;
            var input;
            if (type === 'select') {
                input = document.createElement('select');
                input.className = 'form-select';
                Object.keys(extra).forEach(function (k) { var o = document.createElement('option'); o.value = k; o.textContent = extra[k]; if (k === value) o.selected = true; input.appendChild(o); });
            } else {
                input = document.createElement('input');
                input.className = type === 'checkbox' ? 'form-check-input' : 'form-control';
                input.type = type;
                if (type === 'checkbox') input.checked = !!value; else input.value = value;
                if (type === 'number') input.step = name === 'rotation' ? 15 : 1;
            }
            input.addEventListener('change', function () {
                var v = type === 'checkbox' ? input.checked : (type === 'number' ? (parseInt(input.value, 10) || 0) : input.value);
                item[name] = v;
                if (name === 'min' && item.max < v) item.max = v;
                if (name === 'max' && item.min > v) item.min = v;
                if (name === 'shape' && DEFAULTS[v]) { item.w = DEFAULTS[v].w; item.h = DEFAULTS[v].h; }
                self.touch();
                self.draw();
                if (name === 'shape' || name === 'min' || name === 'max') self.showProps();
            });
            wrap.appendChild(input);
            form.appendChild(wrap);
        };
        if (item.kind === 'table') {
            field(t.label || 'Label', 'label', 'text', item.label);
            field(t.min || 'Min', 'min', 'number', item.min);
            field(t.max || 'Max', 'max', 'number', item.max);
            field(t.shape || 'Shape', 'shape', 'select', item.shape, t.shapes || { round: 'round', square: 'square', rectangle: 'rectangle', counter: 'counter' });
        } else {
            field(t.text || 'Text', 'label', 'text', item.label || '');
        }
        field(t.width || 'W', 'w', 'number', item.w);
        field(t.height || 'H', 'h', 'number', item.h);
        field(t.rotation || '°', 'rotation', 'number', item.rotation || 0);
        if (item.kind === 'table') {
            field(t.active || 'On', 'active', 'checkbox', item.active);
            if (item.token) {
                var renew = document.createElement('button');
                renew.type = 'button';
                renew.className = 'btn btn-secondary';
                renew.textContent = t.renew || 'QR';
                renew.addEventListener('click', function () {
                    if (!window.confirm(t.renew_confirm || '?')) return;
                    var body = new FormData();
                    body.append('_token', self.data.token);
                    fetch(item.token, { method: 'POST', body: body, credentials: 'same-origin' }).then(function () { self.flash(t.saved || 'OK'); });
                });
                form.appendChild(renew);
            }
        }
    };

    Editor.prototype.flash = function (text, error) {
        if (!this.status) return;
        this.status.textContent = text;
        this.status.className = 'restaurant-editor-status' + (error ? ' is-error' : ' is-ok');
    };

    Editor.prototype.save = function () {
        var self = this;
        var payload = {
            layout: this.data.layout,
            room: { width: this.data.room.width, height: this.data.room.height, decor: this.decor.map(function (d) { var c = { type: d.type, x: d.x, y: d.y, w: d.w, h: d.h, rotation: d.rotation || 0 }; if (d.label) c.label = d.label; return c; }) },
            tables: this.tables.map(function (t) { return { key: t.key, id: t.id, label: t.label, min: t.min, max: t.max, shape: t.shape, x: t.x, y: t.y, w: t.w, h: t.h, rotation: t.rotation || 0, active: t.active }; }),
            removed: this.removed,
            joins: this.joins,
        };
        fetch(this.data.save, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': this.data.token },
            body: JSON.stringify(payload),
        }).then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); }).then(function (answer) {
            // The new tables have their ids now: their keys become them.
            var ids = {};
            (answer.tables || []).forEach(function (m) { ids[m.key] = m.id; });
            self.tables.forEach(function (t) { if (ids[t.key]) { var old = t.key; t.id = ids[old]; t.key = String(t.id); self.joins = self.joins.map(function (g) { return g.map(function (k) { return k === old ? t.key : k; }); }); self.selected = self.selected.map(function (k) { return k === old ? t.key : k; }); } });
            self.removed = [];
            self.dirty = false;
            self.flash(self.texts.saved || 'OK');
            self.draw();
        }).catch(function () { self.flash(self.texts.error || 'Error', true); });
    };

    function start() {
        document.querySelectorAll('[data-restaurant-floor]').forEach(function (root) {
            if (root.restaurantFloor) return;
            root.restaurantFloor = new Editor(root);
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
    window.addEventListener('load', start);
    window.addEventListener('transparent:load', start);
})();
