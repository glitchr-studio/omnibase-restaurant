import { Controller } from '@hotwired/stimulus';

/*
 * restaurant-pass: the pass's board. Redrawn (its HTML asked again) when
 * the state's revision grows - glitchr/omnibase's poll controller says so
 * (poll:change) - or right after a tap. Each tap is a POST with the pass's
 * token; the table tapped on the floor opens its card, kept open across
 * redraws. The waiting tickets' clocks tick here, between two redraws.
 */
export default class extends Controller {
    static targets = ['board', 'toast'];
    static values = { boardUrl: String, token: String, revision: Number };

    connect() {
        this.ticker = setInterval(() => this.tick(), 15000);
        this.tick();
    }

    disconnect() {
        clearInterval(this.ticker);
    }

    refresh() {
        if (this.loading) { this.again = true; return; }
        this.loading = true;
        fetch(this.boardUrlValue, { credentials: 'same-origin', headers: { Accept: 'text/html' } })
            .then((r) => (r.ok ? r.text() : null))
            .then((html) => {
                if (!html) return;
                const open = this.open;
                const template = document.createElement('template');
                template.innerHTML = html.trim();
                const board = template.content.firstElementChild;
                if (board) this.boardTarget.replaceWith(board);
                if (open) this.showCard(open);
                this.tick();
            })
            .catch(() => {})
            .finally(() => {
                this.loading = false;
                if (this.again) { this.again = false; this.refresh(); }
            });
    }

    post(event) {
        const button = event.currentTarget;
        if (button.dataset.confirm && !window.confirm(button.dataset.confirm)) return;
        const body = new FormData();
        body.append('_token', this.tokenValue);
        if (button.dataset.with) body.append('with', button.dataset.with);
        if (button.dataset.minutes) body.append('minutes', button.dataset.minutes);
        if (button.dataset.to) body.append('to', button.dataset.to);
        button.disabled = true;
        this.send(button.dataset.url, body).finally(() => { button.disabled = false; });
    }

    transfer(event) {
        const select = event.currentTarget;
        if (!select.value) return;
        const body = new FormData();
        body.append('_token', this.tokenValue);
        body.append('to', select.value);
        this.send(select.dataset.url, body);
    }

    submit(event) {
        event.preventDefault();
        const form = event.currentTarget;
        this.send(form.action, new FormData(form)).then((ok) => { if (ok) { form.reset(); form.closest('details').open = false; } });
    }

    send(url, body) {
        return fetch(url, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then((r) => r.json().catch(() => ({})).then((answer) => {
                if (!r.ok || answer.ok === false) this.toast(answer.error || `${r.status}`);
                this.refresh();
                return r.ok && answer.ok !== false;
            }))
            .catch(() => { this.toast('…'); return false; });
    }

    table(event) {
        const id = event.currentTarget.dataset.table;
        if (this.open === id) { this.closeCard(); return; }
        this.showCard(id);
    }

    showCard(id) {
        this.open = id;
        this.element.querySelectorAll('[data-card]').forEach((card) => { card.hidden = card.dataset.card !== id; });
        this.element.querySelectorAll('.restaurant-floor-table').forEach((t) => t.classList.toggle('is-selected', t.dataset.table === id));
    }

    closeCard() {
        this.open = null;
        this.element.querySelectorAll('[data-card]').forEach((card) => { card.hidden = true; });
        this.element.querySelectorAll('.restaurant-floor-table.is-selected').forEach((t) => t.classList.remove('is-selected'));
    }

    /** The platforms' deadlines counted down, the clock moved on. */
    tick() {
        const now = Date.now() / 1000;
        this.element.querySelectorAll('[data-deadline]').forEach((node) => {
            const left = Math.max(0, Math.round((parseInt(node.dataset.deadline, 10) - now) / 60));
            node.textContent = node.textContent.replace(/\d+/, String(left));
        });
        const clock = this.element.querySelector('.restaurant-pass-clock');
        if (clock) clock.textContent = new Date().toTimeString().slice(0, 5);
    }

    toast(text) {
        if (!this.hasToastTarget) return;
        this.toastTarget.textContent = text;
        this.toastTarget.hidden = false;
        clearTimeout(this.toastTimer);
        this.toastTimer = setTimeout(() => { this.toastTarget.hidden = true; }, 4000);
    }
}
