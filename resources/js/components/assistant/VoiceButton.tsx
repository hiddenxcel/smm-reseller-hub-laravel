import { cn } from '@/lib/utils';
import { Mic } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { SPEECH, t } from './strings';
import type { Locale } from './useAssistant';

type Recognition = {
    lang: string;
    interimResults: boolean;
    maxAlternatives: number;
    start: () => void;
    stop: () => void;
    onresult: ((event: { results: ArrayLike<ArrayLike<{ transcript: string }>> }) => void) | null;
    onend: (() => void) | null;
    onerror: (() => void) | null;
};

type RecognitionCtor = new () => Recognition;

function ctor(): RecognitionCtor | null {
    if (typeof window === 'undefined') {
        return null;
    }

    const w = window as unknown as { SpeechRecognition?: RecognitionCtor; webkitSpeechRecognition?: RecognitionCtor };

    return w.SpeechRecognition ?? w.webkitSpeechRecognition ?? null;
}

/**
 * Say the question instead of typing it.
 *
 * Many of the people reading this site are on a phone, typing in a second or
 * third language, with a thumb. Speech is faster and spells nothing wrong. It
 * uses the browser's own recogniser — nothing is recorded or sent by us — and
 * is simply absent where the browser has none, rather than shown broken.
 */
export default function VoiceButton({
    locale,
    onText,
}: {
    locale: Locale;
    onText: (text: string) => void;
}) {
    const copy = t(locale);
    const [supported, setSupported] = useState(false);
    const [listening, setListening] = useState(false);
    const recognition = useRef<Recognition | null>(null);

    // Decided after mount: the server-rendered HTML has no window to ask.
    useEffect(() => setSupported(ctor() !== null), []);

    if (!supported) {
        return null;
    }

    function toggle() {
        if (listening) {
            recognition.current?.stop();

            return;
        }

        const Recognizer = ctor();

        if (!Recognizer) {
            return;
        }

        const next = new Recognizer();
        next.lang = SPEECH[locale] ?? 'en-US';
        next.interimResults = false;
        next.maxAlternatives = 1;
        next.onresult = (event) => {
            const spoken = event.results[0]?.[0]?.transcript;

            if (spoken) {
                onText(spoken);
            }
        };
        next.onend = () => setListening(false);
        next.onerror = () => setListening(false);

        recognition.current = next;
        setListening(true);
        next.start();
    }

    return (
        <button
            type="button"
            onClick={toggle}
            aria-label={listening ? copy.listening : copy.listen}
            aria-pressed={listening}
            title={listening ? copy.listening : copy.listen}
            className={cn(
                'flex size-8 shrink-0 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-muted hover:text-foreground',
                listening && 'animate-pulse bg-destructive/15 text-destructive',
            )}
        >
            <Mic className="size-4" />
        </button>
    );
}
