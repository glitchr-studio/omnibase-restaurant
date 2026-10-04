import { Controller } from '@hotwired/stimulus';

/*
 * restaurant-table: a table's phone. The round is put together dish by dish
 * (kept on the phone until sent: a reload loses nothing), sent to the pass
 * as JSON; the waiter called, the bill asked for; the rounds already sent
 * redrawn when the state changes (poll:change, glitchr/omnibase's poll).
 */
export default class extends Controller {
    static targets = ['dish', 'count', 'cart', 'cartList', 'cartCount', 'cartTotal', 'note', 'sendButton', 'rounds', 'roundCount', 'total', 'toast', 'callButton', 'billButton', 'pay', 'payEmail', 'payButton'];
    static values = { roundUrl: String, callUrl: String, billUrl: String, payUrl: String, stateUrl: String, key: String, currency: String, locale: String, statuses: Object, texts: Object };

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

    /** The options ticked on a dish (omnibase/marketplace's Product\Option): ids, labels, what they add. */
    optionsOf(dish) {
        const inputs = Array.from(dish.querySelectorAll('.restaurant-dish-options input:checked'));
        return {
            ids: inputs.map((input) => parseInt(input.value, 10)).sort((a, b) => a - b),
            labels: inputs.map((input) => input.dataset.label),
            price: inputs.reduce((sum, input) => sum + (parseInt(input.dataset.price, 10) || 0), 0),
        };
    }

    /** A group that asks for a choice and has none: its name. */
    missingOf(dish) {
        const group = Array.from(dish.querySelectorAll('.restaurant-dish-options fieldset')).find((fieldset) => (parseInt(fieldset.dataset.minimum, 10) || 0) > fieldset.querySelectorAll('input:checked').length);
        return group ? group.dataset.label : null;
    }

    /** A line of the round: a dish and its options - the same dish cooked another way is another line. */
    keysOf(id) {
        return Object.keys(this.cart).filter((key) => String(this.cart[key].dish ?? key) === String(id));
    }

    change(dish, by) {
        if (!dish) return;
        const id = dish.dataset.dish;
        const options = this.optionsOf(dish);
        let key = options.ids.length ? `${id}:${options.ids.join('-')}` : id;
        if (by > 0) {
            const missing = this.missingOf(dish);
            if (missing) {
                const details = dish.querySelector('.restaurant-dish-options');
                if (details) details.open = true;
                this.toast(`${missing} : ${this.textsValue.choose || ''}`);
                return;
            }
        } else if (!this.cart[key]) {
            // One less of a dish whose options changed since: its last line.
            key = this.keysOf(id).pop() || key;
        }
        const line = this.cart[key] || { dish: id, quantity: 0, name: dish.dataset.name, price: (parseInt(dish.dataset.price, 10) || 0) + options.price, options: options.ids, labels: options.labels };
        line.quantity = Math.max(0, Math.min(20, line.quantity + by));
        if (line.quantity) this.cart[key] = line; else delete this.cart[key];
        this.save();
        this.render();
    }

    save() {
        try { localStorage.setItem(this.keyValue, JSON.stringify(this.cart)); } catch (e) { /* private mode */ }
    }

    render() {
        let count = 0;
        let total = 0;
        const taken = (id) => this.keysOf(id).reduce((sum, key) => sum + this.cart[key].quantity, 0);
        this.countTargets.forEach((output) => { output.value = taken(output.dataset.dish); output.textContent = output.value; });
        this.dishTargets.forEach((dish) => dish.classList.toggle('is-picked', taken(dish.dataset.dish) > 0));
        if (this.hasCartListTarget) this.cartListTarget.textContent = '';
        Object.entries(this.cart).forEach(([, line]) => {
            count += line.quantity;
            total += line.quantity * line.price;
            if (this.hasCartListTarget) {
                const li = document.createElement('li');
                const name = document.createElement('span');
                name.textContent = `${line.quantity} × ${line.name}${line.labels && line.labels.length ? ` (${line.labels.join(', ')})` : ''}`;
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
        const lines = Object.entries(this.cart).map(([key, line]) => ({ dish: parseInt(line.dish ?? key, 10), quantity: line.quantity, options: line.options || [] }));
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

    /** The bill paid from this phone: an address for the receipt, then the shop's payment page - or done at once. */
    pay(event) {
        if (event) event.preventDefault();
        if (!this.hasPayEmailTarget || !this.payEmailTarget.reportValidity()) return;
        if (this.hasPayButtonTarget) this.payButtonTarget.disabled = true;
        this.post(this.payUrlValue, { email: this.payEmailTarget.value })
            .then((answer) => {
                if (answer.redirect) { window.location.assign(answer.redirect); return; }
                this.toast(this.textsValue.paid);
                window.setTimeout(() => window.location.reload(), 1200);
            })
            .catch((message) => {
                this.toast(message || this.textsValue.error);
                if (this.hasPayButtonTarget) this.payButtonTarget.disabled = false;
            });
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
            ticket.lines.forEach((line) => { const li = document.createElement('li'); li.textContent = `${line.quantity} × ${line.name}${line.options && line.options.length ? ` (${line.options.join(', ')})` : ''}`; ul.appendChild(li); });
            div.append(p, ul);
            box.appendChild(div);
        });
        if (this.hasRoundCountTarget) this.roundCountTarget.textContent = state.tickets.length || '';
        if (this.hasTotalTarget) this.totalTarget.textContent = this.money.format((state.total || 0) / 100);
        if (this.hasBillButtonTarget) this.billButtonTarget.disabled = state.status !== 'open';
        if (this.hasPayTarget) this.payTarget.hidden = !(state.total > 0);
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
