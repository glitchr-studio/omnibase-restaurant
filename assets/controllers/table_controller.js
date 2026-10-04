import { Controller } from '@hotwired/stimulus';

/*
 * restaurant-table: a table's phone. The round is put together dish by dish
 * (kept on the phone until sent: a reload loses nothing), sent to the pass
 * as JSON; the waiter called, the bill asked for; the rounds already sent
 * redrawn when the state changes (poll:change, glitchr/omnibase's poll).
 */
export default class extends Controller {
    static targets = ['dish', 'count', 'cart', 'cartList', 'cartCount', 'cartTotal', 'note', 'sendButton', 'rounds', 'roundCount', 'total', 'toast', 'callButton', 'billButton'];
    static values = { roundUrl: String, callUrl: String, billUrl: String, stateUrl: String, key: String, currency: String, locale: String, statuses: Object, texts: Object };

    connect() {
        try { this.cart = JSON.parse(localStorage.getItem(this.keyValue) || '{}'); } catch (e) { this.cart = {}; }
        this.money = new Intl.NumberFormat((this.localeValue || 'fr').replace('_', '-'), { style: 'currency', currency: this.currencyValue || 'EUR' });
        this.render();
    }

    dishOf(element) {
        return element.closest('[data-dish]');
    }

    more(event) { this.change(this.dishOf(event.currentTarget), 1); }
    less(event) { this.change(this.dishOf(event.currentTarget), -1); }

    change(dish, by) {
        if (!dish) return;
        const id = dish.dataset.dish;
        const line = this.cart[id] || { quantity: 0, name: dish.dataset.name, price: parseInt(dish.dataset.price, 10) || 0 };
        line.quantity = Math.max(0, Math.min(20, line.quantity + by));
        if (line.quantity) this.cart[id] = line; else delete this.cart[id];
        this.save();
        this.render();
    }

    save() {
        try { localStorage.setItem(this.keyValue, JSON.stringify(this.cart)); } catch (e) { /* private mode */ }
    }

    render() {
        let count = 0;
        let total = 0;
        this.countTargets.forEach((output) => { output.value = this.cart[output.dataset.dish]?.quantity || 0; output.textContent = output.value; });
        this.dishTargets.forEach((dish) => dish.classList.toggle('is-picked', !!this.cart[dish.dataset.dish]));
        if (this.hasCartListTarget) this.cartListTarget.textContent = '';
        Object.entries(this.cart).forEach(([, line]) => {
            count += line.quantity;
            total += line.quantity * line.price;
            if (this.hasCartListTarget) {
                const li = document.createElement('li');
                const name = document.createElement('span');
                name.textContent = `${line.quantity} × ${line.name}`;
                const price = document.createElement('span');
                price.textContent = this.money.format(line.quantity * line.price / 100);
                li.append(name, price);
                this.cartListTarget.appendChild(li);
            }
        });
        if (this.hasCartTarget) this.cartTarget.hidden = count === 0;
        if (this.hasCartCountTarget) this.cartCountTarget.textContent = String(count);
        if (this.hasCartTotalTarget) this.cartTotalTarget.textContent = this.money.format(total / 100);
    }

    send() {
        const lines = Object.entries(this.cart).map(([dish, line]) => ({ dish: parseInt(dish, 10), quantity: line.quantity }));
        if (!lines.length) return;
        this.sendButtonTarget.disabled = true;
        this.post(this.roundUrlValue, { lines, note: this.hasNoteTarget ? this.noteTarget.value : null })
            .then((state) => {
                this.cart = {};
                this.save();
                if (this.hasNoteTarget) this.noteTarget.value = '';
                this.render();
                this.show({ detail: state });
                this.toast(this.textsValue.sent);
                this.tab({ currentTarget: this.element.querySelector('[data-tab="rounds"]') });
            })
            .catch((message) => this.toast(message || this.textsValue.error))
            .finally(() => { this.sendButtonTarget.disabled = false; });
    }

    call() {
        this.post(this.callUrlValue, {}).then((state) => { this.show({ detail: state }); this.toast(this.textsValue.called); }).catch((m) => this.toast(m || this.textsValue.error));
    }

    bill() {
        this.post(this.billUrlValue, {}).then((state) => { this.show({ detail: state }); this.toast(this.textsValue.bill); }).catch((m) => this.toast(m || this.textsValue.error));
    }

    post(url, body) {
        return fetch(url, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(body),
        }).then((r) => r.json().catch(() => ({})).then((data) => {
            if (!r.ok) throw (data.error || data.detail || data.title || null);
            return data;
        }));
    }

    /** The table's state, from a poll or an answer: the rounds and where each stands. */
    show(event) {
        const state = event.detail;
        if (state && state.session === null && this.hadSession) {
            // The bill is settled: the table starts afresh for the next guests.
            window.location.reload();
            return;
        }
        if (!state || !state.tickets) return;
        this.hadSession = true;
        const box = this.roundsTarget;
        box.textContent = '';
        if (!state.tickets.length) {
            const p = document.createElement('p');
            p.className = 'restaurant-muted';
            p.textContent = this.textsValue.empty;
            box.appendChild(p);
        }
        state.tickets.slice().reverse().forEach((ticket) => {
            const div = document.createElement('div');
            div.className = `restaurant-round is-${ticket.status}`;
            const p = document.createElement('p');
            const strong = document.createElement('strong');
            strong.textContent = `${this.textsValue.round} ${ticket.round}`;
            const pill = document.createElement('span');
            pill.className = 'restaurant-pill';
            pill.textContent = this.statusesValue[ticket.status] || ticket.status;
            p.append(strong, ' ', pill);
            const ul = document.createElement('ul');
            ticket.lines.forEach((line) => { const li = document.createElement('li'); li.textContent = `${line.quantity} × ${line.name}`; ul.appendChild(li); });
            div.append(p, ul);
            box.appendChild(div);
        });
        if (this.hasRoundCountTarget) this.roundCountTarget.textContent = state.tickets.length || '';
        if (this.hasTotalTarget) this.totalTarget.textContent = this.money.format((state.total || 0) / 100);
        if (this.hasBillButtonTarget) this.billButtonTarget.disabled = state.status !== 'open';
        if (state.status && state.status !== 'open' && this.hasCartTarget) this.cartTarget.hidden = true;
    }

    tab(event) {
        const button = event.currentTarget;
        if (!button) return;
        const name = button.dataset.tab;
        this.element.querySelectorAll('[data-tab]').forEach((b) => { b.classList.toggle('is-on', b === button); b.setAttribute('aria-selected', b === button ? 'true' : 'false'); });
        this.element.querySelectorAll('[data-pane]').forEach((pane) => { pane.hidden = pane.dataset.pane !== name; });
    }

    toast(text) {
        if (!this.hasToastTarget || !text) return;
        this.toastTarget.textContent = text;
        this.toastTarget.hidden = false;
        clearTimeout(this.toastTimer);
        this.toastTimer = setTimeout(() => { this.toastTarget.hidden = true; }, 3000);
    }
}
