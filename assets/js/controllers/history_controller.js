import {Controller} from '@hotwired/stimulus';

/*
 * Conversation of the history, to register as "ai-sql-assistant-history": its rename button swaps its title for a field, saved by Enter or by leaving it and cancelled by Escape. A blank or
 * unchanged title is not sent, the conversation keeping the one it had.
 */
export default class extends Controller {
    static targets = ['display', 'form', 'title'];

    submitted = false;

    edit() {
        this.displayTarget.hidden = true;
        this.formTarget.hidden = false;
        this.titleTarget.focus();
        this.titleTarget.select();
    }

    cancel() {
        this.titleTarget.value = this.titleTarget.defaultValue;
        this.formTarget.hidden = true;
        this.displayTarget.hidden = false;
    }

    save() {
        // Escape hides the field before it loses the focus, which must not save it
        if (!this.formTarget.hidden) {
            this.formTarget.requestSubmit();
        }
    }

    submit(event) {
        const title = this.titleTarget.value.trim();

        // Leaving the field while its title is being sent would send it twice
        if (this.submitted) {
            event.preventDefault();

            return;
        }

        if ('' === title || title === this.titleTarget.defaultValue) {
            event.preventDefault();
            this.cancel();

            return;
        }

        this.submitted = true;
    }
}
