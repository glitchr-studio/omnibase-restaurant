import { Controller } from '@hotwired/stimulus';

/*
 * restaurant-booking: the reservation form's times. When the day or the
 * party size changes, the free times are asked again (/reserver/creneaux)
 * and drawn as buttons; the one tapped fills the form's time. On the
 * management page, it also fills the "move" form beside it.
 */
export default class extends Controller {
    static targets = ['day', 'covers', 'time', 'slots'];
    static values = { url: String, none: String, call: String };

    connect() {
        this.mark(this.hasTimeTarget ? this.timeTarget.value : '');
    }

    day(event) {
        if (!this.hasDayTarget) return;
        this.dayTarget.value = event.currentTarget.dataset.day;
        this.element.querySelectorAll('.restaurant-chip').forEach((chip) => chip.classList.toggle('is-selected', chip === event.currentTarget));
        this.refresh();
    }

    refresh() {
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.load(), 150);
    }

    load() {
        if (!this.hasDayTarget || !this.dayTarget.value) return;
        const covers = this.hasCoversTarget ? parseInt(this.coversTarget.value, 10) || 1 : 2;
        const url = `${this.urlValue}?jour=${encodeURIComponent(this.dayTarget.value)}&couverts=${covers}`;
        this.slotsTarget.classList.add('is-loading');
        fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((r) => r.json())
            .then((answer) => this.draw(answer))
            .catch(() => {})
            .finally(() => this.slotsTarget.classList.remove('is-loading'));
    }

    draw(answer) {
        const keep = this.hasTimeTarget ? this.timeTarget.value : '';
        const box = this.slotsTarget;
        box.textContent = '';
        if (answer.too_many) {
            box.appendChild(this.paragraph(this.callValue));
        } else if (!answer.services.length) {
            box.appendChild(this.paragraph(this.noneValue));
        }
        let found = false;
        answer.services.forEach((service) => {
            const block = document.createElement('div');
            block.className = 'restaurant-service';
            const title = document.createElement('h3');
            title.textContent = service.name;
            const times = document.createElement('div');
            times.className = 'restaurant-times';
            service.times.forEach((time) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'restaurant-time';
                button.dataset.time = time;
                button.dataset.action = 'restaurant-booking#pick';
                button.textContent = time;
                if (time === keep) found = true;
                times.appendChild(button);
            });
            block.append(title, times);
            box.appendChild(block);
        });
        this.set(found ? keep : '');
    }

    paragraph(text) {
        const p = document.createElement('p');
        p.className = 'restaurant-none';
        p.textContent = text;
        return p;
    }

    pick(event) {
        this.set(event.currentTarget.dataset.time);
    }

    set(time) {
        if (this.hasTimeTarget) this.timeTarget.value = time;
        this.mark(time);
        // The management page: its "move" form takes the day, the time, the size.
        const move = document.querySelector('[data-restaurant-booking-confirm]');
        if (move) {
            move.querySelector('[data-manage-time]').value = time;
            if (this.hasDayTarget) move.querySelector('[data-manage-day]').value = this.dayTarget.value;
            if (this.hasCoversTarget) move.querySelector('[data-manage-covers]').value = this.coversTarget.value;
            move.querySelector('[data-manage-submit]').disabled = !time;
        }
    }

    mark(time) {
        this.element.querySelectorAll('.restaurant-time').forEach((button) => {
            const on = button.dataset.time === time;
            button.classList.toggle('is-selected', on);
            button.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
    }
}
