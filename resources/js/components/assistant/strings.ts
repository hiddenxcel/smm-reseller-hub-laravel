import type { Locale } from './useAssistant';

/**
 * Everything the widget says in its own voice, as opposed to the answers,
 * which come from the knowledge base.
 *
 * Both halves have to move together. A Kiswahili answer sitting under an
 * English "Need a person?" is half a translation, and half a translation reads
 * worse than none — it says the Kiswahili was a trick the machine did rather
 * than a language anybody here speaks.
 *
 * Two languages only, matching the assistant. The bots themselves serve five,
 * but that is a reseller's own customers on WhatsApp; this is our website.
 */
export const UI = {
    en: {
        title: 'ResellersHub AI',
        status: 'Answers instantly',
        open: 'Open the ResellersHub assistant',
        close: 'Close',
        restart: 'Start over',
        send: 'Send',
        placeholder: 'Ask anything…',
        footnote: 'Powered by SMM ResellersHub',
        greeting: 'Hi there 👋',
        thinking: 'Thinking',

        peekTitle: 'Questions about the bots?',
        peekBody: 'Ask me — I answer instantly.',

        needPerson: 'Need a person?',
        whatsapp: 'WhatsApp',
        leaveDetails: 'Leave details',

        failed: 'That did not go through. Try again in a moment, or reach us on WhatsApp.',
        askAgain: 'Ask again',

        leadTitle: 'Leave your details',
        leadBody: 'We will reply on WhatsApp. Your question so far comes with it.',
        leadNoChat:
            'This form needs a chat to attach to, and nothing went through yet. Ask your question above first, or reach us on WhatsApp.',
        leadName: 'Your name',
        leadPhone: 'WhatsApp number',
        leadExtra: 'Anything to add',
        optional: '(optional)',
        back: 'Back',
        sendToSupport: 'Send to support',
        leadFailed: 'That did not send. Try again, or reach us on WhatsApp.',

        actions: {
            orderBot: 'Order Bot',
            supportBot: 'Support Bot',
            pricing: 'Pricing',
            start: 'How to start',
            whatsappSetup: 'WhatsApp setup',
        },

        // Keyed on the path the visitor is reading, so the opening line uses
        // the one thing we already know about them.
        openers: {
            pricing: 'Comparing the plans? I can explain what each one does and what it costs.',
            features: 'Happy to go deeper on any of these — just ask.',
            apiDocs: 'Questions about the API, or about connecting your panel? Ask away.',
            blog: 'Ask me anything about the bots while you read.',
            contact: 'I can probably answer it right here — and put you through if not.',
            home: "I'm the ResellersHub assistant. Ask me anything about the bots, the setup or the pricing.",
        },
    },

    sw: {
        title: 'ResellersHub AI',
        status: 'Inajibu papo hapo',
        open: 'Fungua msaidizi wa ResellersHub',
        close: 'Funga',
        restart: 'Anza upya',
        send: 'Tuma',
        placeholder: 'Uliza chochote…',
        // A brand line rather than a translated sentence: the name is the
        // name in either language.
        footnote: 'Powered by SMM ResellersHub',
        greeting: 'Habari 👋',
        thinking: 'Inafikiri',

        peekTitle: 'Una swali kuhusu bots?',
        peekBody: 'Niulize — najibu papo hapo.',

        needPerson: 'Unahitaji mtu?',
        whatsapp: 'WhatsApp',
        leaveDetails: 'Acha taarifa',

        failed: 'Haikupita. Jaribu tena baada ya muda, au tufikie kwenye WhatsApp.',
        askAgain: 'Uliza tena',

        leadTitle: 'Acha taarifa zako',
        leadBody: 'Tutakujibu kwenye WhatsApp. Swali lako linakwenda pamoja nazo.',
        leadNoChat:
            'Fomu hii inahitaji mazungumzo ya kuambatanisha nayo, na hakuna kilichopita bado. Uliza swali lako hapo juu kwanza, au tufikie kwenye WhatsApp.',
        leadName: 'Jina lako',
        leadPhone: 'Namba ya WhatsApp',
        leadExtra: 'Kuna la kuongeza',
        optional: '(si lazima)',
        back: 'Rudi',
        sendToSupport: 'Tuma kwa support',
        leadFailed: 'Haikutumwa. Jaribu tena, au tufikie kwenye WhatsApp.',

        actions: {
            orderBot: 'Order Bot',
            supportBot: 'Support Bot',
            pricing: 'Bei',
            start: 'Jinsi ya kuanza',
            whatsappSetup: 'Usanidi wa WhatsApp',
        },

        openers: {
            pricing: 'Unalinganisha bei? Naweza kueleza kila huduma inafanya nini na inagharimu kiasi gani.',
            features: 'Niko tayari kueleza zaidi kuhusu lolote hapa — niulize tu.',
            apiDocs: 'Una swali kuhusu API, au kuunganisha panel yako? Niulize.',
            blog: 'Niulize chochote kuhusu bots wakati unasoma.',
            contact: 'Pengine naweza kukujibu hapa hapa — na kama sivyo, nitakuunganisha na mtu.',
            home: 'Mimi ni msaidizi wa ResellersHub. Niulize chochote kuhusu bots, usanidi au bei.',
        },
    },
} as const;

export function t(locale: Locale) {
    return UI[locale] ?? UI.en;
}
