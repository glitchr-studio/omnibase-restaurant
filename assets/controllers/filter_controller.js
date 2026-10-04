import { Controller } from '@hotwired/stimulus';

/*
 * restaurant-filter: the menu read without what one cannot eat. The
 * allergens ticked hide the dishes that contain any; the diets ticked keep
 * only the dishes that suit all. Nothing leaves the page.
 */
export default class extends Controller {
    static targets = ['allergen', 'diet', 'count'];

    apply() {
        const without = this.allergenTargets.filter((i) => i.checked).map((i) => i.value);
        const only = this.dietTargets.filter((i) => i.checked).map((i) => i.value);
        let hidden = 0;
        this.element.querySelectorAll('.restaurant-dish').forEach((dish) => {
            const has = (dish.dataset.allergens || '').split(' ');
            const suits = (dish.dataset.diets || '').split(' ');
            const out = without.some((a) => has.includes(a)) || only.some((d) => !suits.includes(d));
            dish.classList.toggle('is-filtered', out);
            if (out) hidden += 1;
        });
        this.element.querySelectorAll('.restaurant-section').forEach((section) => {
            section.hidden = !section.querySelector('.restaurant-dish:not(.is-filtered)');
        });
        if (this.hasCountTarget) this.countTarget.textContent = hidden ? `(−${hidden})` : '';
    }
}
