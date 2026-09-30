import {Controller} from '@hotwired/stimulus';

// Delay between two reloads of the turn frame while a turn waits or runs
const PROGRESS_INTERVAL = 1000;

// Distance within which the end of the messages still counts as in view: beyond it, the user scrolled up to read an earlier message and the new steps or results no longer scroll to the end
const FOLLOW_MARGIN = 50;

/*
 * Chat of the assistant, to register as "ai-sql-assistant", on the whole page: its history frame and its main column frame, which shows the form starting a conversation or a conversation.
 * Each time the main column loads, the history is reloaded, to show a new conversation, the one being read and the costs. A conversation opens on its last message, and keeps to it while
 * the steps of a turn and the query results load, as long as the user stays there. An option of a question of the assistant is sent as the answer, through the same form as a typed answer.
 * In the text areas of the chat, the first question as the messages of a conversation, Enter sends the form and Shift+Enter goes to the line.
 * While a turn waits or runs, the controller asks for it to run when it waits, then reloads its turn frame until the turn is over and reloads the conversation. The turn runs on the server
 * whatever the page does: the user may leave it and come back.
 */
export default class extends Controller {
    static targets = ['history', 'main', 'conversation', 'turn', 'message', 'submit', 'end'];
    static values = {historyUrl: String};

    // Frames of the main column whose render started while the end of the messages was in view
    followingFrames = new WeakSet();

    connect() {
        // On the whole chat, whose frames replace the text areas
        this.element.addEventListener('keydown', this.sendOnEnter);
    }

    disconnect() {
        clearTimeout(this.progressTimeout);
        this.element.removeEventListener('keydown', this.sendOnEnter);
    }

    // A key composing a character (an input method) is left to it, and an empty text or a form already being sent is not sent
    sendOnEnter = (event) => {
        if (!(event.target instanceof HTMLTextAreaElement) || !event.target.form || 'Enter' !== event.key || event.shiftKey || event.isComposing) {
            return;
        }

        event.preventDefault();
        const submitter = event.target.form.querySelector('[type="submit"]');
        if ('' !== event.target.value.trim() && !submitter?.disabled) {
            // Submitting through the button keeps the behavior Turbo gives it: disabled while the form is sent
            event.target.form.requestSubmit(submitter);
        }
    };

    endTargetConnected() {
        this.scrollToEnd();
    }

    conversationTargetConnected(element) {
        const {turnState, runUrl, runToken} = element.dataset;
        if ('waiting' === turnState) {
            // The response only comes at the end of the turn, which its frame tells anyway: it is not awaited, and a second request for a turn already running does nothing
            fetch(runUrl, {method: 'POST', body: new URLSearchParams({_token: runToken})}).catch(() => {});
        }
        if (['waiting', 'running'].includes(turnState)) {
            this.scheduleProgress();
        }
    }

    conversationTargetDisconnected() {
        clearTimeout(this.progressTimeout);
    }

    // Before a frame of the main column renders, the new content of the turn or of a query result pushing the end of the messages down: whether the user reads that end
    frameRendering(event) {
        if (this.isEndInView()) {
            this.followingFrames.add(event.target);
        } else {
            this.followingFrames.delete(event.target);
        }
    }

    // The loads of the frames it holds (the turn, the query results) reach the main column as well
    mainLoaded(event) {
        if (event.target === this.mainTarget) {
            // Again once the frame is rendered: when its end target connected, the result frames Turbo keeps ("data-turbo-permanent") were still empty placeholders, which left it too high
            this.scrollToEnd();

            const url = new URL(this.historyUrlValue, window.location.origin);
            if (this.hasConversationTarget) {
                url.searchParams.set('current', this.conversationTarget.dataset.conversationId);
            }
            this.load(this.historyTarget, url.pathname + url.search);
        } else if (this.followingFrames.has(event.target)) {
            this.scrollToEnd();
        }
    }

    turnLoaded() {
        if (this.turnTarget.querySelector('[data-ai-sql-assistant-turn-over]')) {
            // The turn is over, ended or failed: the conversation shows it
            this.load(this.mainTarget, window.location.href);
        } else {
            this.scheduleProgress();
        }
    }

    // A reload that failed is tried again at the next one
    turnFailed() {
        this.scheduleProgress();
    }

    answer(event) {
        this.messageTarget.value = event.params.option;
        // Submitting through the button keeps the behavior Turbo gives it: disabled while the form is sent
        this.messageTarget.form.requestSubmit(this.submitTarget);
    }

    scheduleProgress() {
        clearTimeout(this.progressTimeout);
        this.progressTimeout = setTimeout(() => {
            if (this.hasTurnTarget && this.hasConversationTarget) {
                this.load(this.turnTarget, this.conversationTarget.dataset.progressUrl);
            }
        }, PROGRESS_INTERVAL);
    }

    // Instant, the page may ask for a smooth scroll ("scroll-behavior"), which would show the whole conversation passing by
    scrollToEnd() {
        if (this.hasEndTarget) {
            this.endTarget.scrollIntoView({block: 'end', behavior: 'instant'});
        }
    }

    // The end of the messages shows at the bottom of the area that scrolls them: their own block when the chat holds in the window, the window otherwise
    isEndInView() {
        if (!this.hasEndTarget) {
            return false;
        }

        let visibleBottom = window.innerHeight;
        for (let element = this.endTarget.parentElement; element; element = element.parentElement) {
            if (['auto', 'scroll'].includes(getComputedStyle(element).overflowY)) {
                visibleBottom = Math.min(visibleBottom, element.getBoundingClientRect().bottom);
            }
        }

        return this.endTarget.getBoundingClientRect().top <= visibleBottom + FOLLOW_MARGIN;
    }

    // Changing the source of a frame loads it, without touching the address of the page, but setting the same one again does nothing
    load(frame, url) {
        if (frame.getAttribute('src') === url) {
            frame.reload();
        } else {
            frame.src = url;
        }
    }
}
