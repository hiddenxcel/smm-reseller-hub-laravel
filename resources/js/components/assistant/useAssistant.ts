import { useCallback, useEffect, useRef, useState } from 'react';

export type AssistantCta = { label: string; url: string };

export type AssistantTurn = {
    id: number;
    role: 'user' | 'assistant';
    text: string;
    /** Up to two buttons under an answer. */
    ctas?: AssistantCta[];
    /** Set on the newest assistant turn only — see the reveal in AssistantMessage. */
    fresh?: boolean;
};

/**
 * A language code. English and Kiswahili have written answers; anything else
 * is answered by the model in whatever language was asked, so the type is
 * open — the server can report a language the widget has no strings for, and
 * strings.ts falls back to English furniture for it.
 */
export type Locale = string;

type AskResponse = {
    reply: string;
    cta: AssistantCta | null;
    ctas?: AssistantCta[];
    suggestions: string[];
    answered_by: 'knowledge' | 'ai' | 'fallback';
    token: string;
    escalate: boolean;
    locale: Locale;
};

/**
 * Where the conversation lives between page loads.
 *
 * sessionStorage rather than a cookie: a marketing chat should survive the
 * walk from the landing page to /pricing — which is the walk it exists to
 * cause — and should not survive closing the tab. Nobody returning tomorrow
 * wants to be met by yesterday's half-question.
 */
const STORE_KEY = 'resellershub.assistant';

/**
 * The language the visitor picked, kept across tabs and days.
 *
 * Separate from the conversation, and in localStorage: choosing a language is
 * a statement about the person, not about this chat, and asking again every
 * session would make a Portuguese speaker re-pick it on every visit.
 */
const LANG_KEY = 'resellershub.assistant.lang';

type Stored = { token: string | null; turns: AssistantTurn[]; locale?: Locale };

function read(): Stored {
    // Guarded for SSR: this module is imported by a component that renders on
    // the server, where there is no sessionStorage to read.
    if (typeof window === 'undefined') {
        return { token: null, turns: [] };
    }

    try {
        const raw = window.sessionStorage.getItem(STORE_KEY);

        return raw ? (JSON.parse(raw) as Stored) : { token: null, turns: [] };
    } catch {
        // A quota error or a half-written entry is not worth breaking the
        // widget over. An empty chat is a working chat.
        return { token: null, turns: [] };
    }
}

function write(value: Stored) {
    try {
        window.sessionStorage.setItem(STORE_KEY, JSON.stringify(value));
    } catch {
        // Same reasoning: losing the history is survivable, throwing is not.
    }
}

/**
 * Laravel's CSRF token, read from the cookie it sets on every response.
 *
 * The cookie rather than a meta tag: this page has no csrf-token meta — Inertia
 * does not need one — and adding one for a single fetch would mean a token
 * baked into a server-rendered page that can be cached.
 */
function csrf(): string {
    const cookie = document.cookie
        .split('; ')
        .find((entry) => entry.startsWith('XSRF-TOKEN='));

    return cookie ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) : '';
}

/**
 * The assistant's state and the one call that changes it.
 *
 * Deliberately fetch rather than Inertia's router: an answer must not navigate
 * the page the visitor is reading. The CTA inside an answer is the only thing
 * here that moves them, and it is their decision.
 */
export function useAssistant(page: string) {
    const [turns, setTurns] = useState<AssistantTurn[]>([]);
    const [suggestions, setSuggestions] = useState<string[]>([]);
    const [pending, setPending] = useState(false);
    const [escalate, setEscalate] = useState(false);

    /** The question that did not go through, so it can be offered back. */
    const [failed, setFailed] = useState<string | null>(null);

    /**
     * What language the conversation is in, as the server last judged it.
     *
     * Decided by the server, which is the only side that can see the whole
     * conversation. The widget's own wording follows it, so a visitor writing
     * Kiswahili is not answered in Kiswahili underneath English buttons.
     */
    const [detected, setDetected] = useState<Locale>('en');

    /** 'auto' lets the server decide; anything else is the visitor's own choice. */
    const [choice, setChoiceState] = useState<string>('auto');

    const token = useRef<string | null>(null);
    const nextId = useRef(1);
    const choiceRef = useRef('auto');

    // Restored after mount, never during render: this component is
    // server-rendered and the server has no session to restore from.
    useEffect(() => {
        const stored = read();

        token.current = stored.token;

        if (stored.locale) {
            setDetected(stored.locale);
        }

        try {
            const saved = window.localStorage.getItem(LANG_KEY);

            if (saved) {
                choiceRef.current = saved;
                setChoiceState(saved);
            }
        } catch {
            // Private mode. Auto-detect is the safe default.
        }

        if (stored.turns.length > 0) {
            // Nothing is replayed as new — a restored answer has already been
            // read, and animating it again on every page load would be a
            // conversation that never settles.
            setTurns(stored.turns.map((turn) => ({ ...turn, fresh: false })));
            nextId.current = Math.max(...stored.turns.map((turn) => turn.id)) + 1;
        }
    }, []);

    useEffect(() => {
        if (turns.length > 0) {
            write({ token: token.current, turns, locale: detected });
        }
    }, [turns]);

    const setChoice = useCallback((code: string) => {
        choiceRef.current = code;
        setChoiceState(code);

        try {
            if (code === 'auto') {
                window.localStorage.removeItem(LANG_KEY);
            } else {
                window.localStorage.setItem(LANG_KEY, code);
            }
        } catch {
            // See above.
        }
    }, []);

    const ask = useCallback(
        async (message: string) => {
            const text = message.trim();

            if (text === '' || pending) {
                return;
            }

            const turnId = nextId.current++;

            setFailed(null);
            setSuggestions([]);
            setPending(true);
            setTurns((current) => [...current, { id: turnId, role: 'user', text }]);

            try {
                const response = await fetch('/assistant/ask', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-XSRF-TOKEN': csrf(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        message: text,
                        token: token.current,
                        page,
                        lang: choiceRef.current === 'auto' ? null : choiceRef.current,
                    }),
                });

                if (!response.ok) {
                    throw new Error(String(response.status));
                }

                const data = (await response.json()) as AskResponse;

                token.current = data.token;

                const ctas = data.ctas ?? (data.cta ? [data.cta] : []);

                setTurns((current) => [
                    ...current,
                    {
                        id: nextId.current++,
                        role: 'assistant',
                        text: data.reply,
                        ctas,
                        fresh: true,
                    },
                ]);
                setSuggestions(data.suggestions ?? []);
                setEscalate(data.escalate);
                setDetected(data.locale ?? 'en');
            } catch {
                // Rate limits and dropped connections both land here. The
                // message says what to do next rather than what went wrong,
                // because a visitor can act on one and not the other.
                //
                // The question is taken back out. Left in, it sits there
                // unanswered for the rest of the session and follows them from
                // page to page in sessionStorage, reading as a question the
                // assistant simply ignored — and their words are put back in
                // the box below instead, so trying again is one tap.
                setTurns((current) => current.filter((turn) => turn.id !== turnId));
                setFailed(text);
                setEscalate(true);
            } finally {
                setPending(false);
            }
        },
        [page, pending],
    );

    const reset = useCallback(() => {
        token.current = null;
        nextId.current = 1;
        setTurns([]);
        setSuggestions([]);
        setEscalate(false);
        setFailed(null);
        setDetected('en');

        try {
            window.sessionStorage.removeItem(STORE_KEY);
        } catch {
            // See write().
        }
    }, []);

    return {
        turns,
        suggestions,
        pending,
        escalate,
        failed,
        // What the widget's own wording speaks: the visitor's pick when they
        // made one, otherwise whatever the conversation turned out to be in.
        locale: choice === 'auto' ? detected : choice,
        choice,
        setChoice,
        ask,
        reset,
        token: token.current,
        started: turns.length > 0,
    };
}
