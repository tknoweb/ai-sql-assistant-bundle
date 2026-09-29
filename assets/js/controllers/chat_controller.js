import {Controller} from '@hotwired/stimulus';

/*
 * Chat of the assistant, to register as "ai-sql-assistant": the page opens on the last message, and an option of a question of the assistant is sent as the answer, through the same form as a typed answer.
 */
export default class extends Controller {
    static targets = ['message', 'submit', 'end'];

    connect() {
        if (this.hasEndTarget) {
            this.endTarget.scrollIntoView({block: 'end'});
        }
    }

    answer(event) {
        this.messageTarget.value = event.params.option;
        // Submitting through the button keeps the behavior Turbo gives it: disabled, with its waiting label, while the turn runs
        this.messageTarget.form.requestSubmit(this.submitTarget);
    }
}
