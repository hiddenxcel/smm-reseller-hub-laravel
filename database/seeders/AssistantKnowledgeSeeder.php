<?php

namespace Database\Seeders;

use App\Models\AssistantKnowledge;
use Illuminate\Database\Seeder;

/**
 * What the website assistant knows on day one.
 *
 * These are the questions people actually ask before buying, in the order they
 * ask them: what is this, how does it work, what does it cost, what do I need,
 * how do I start. Every one of them is answered here rather than left to the
 * model, because these are the answers we cannot afford to have improvised.
 *
 * No prices are written into any answer. They live in the `plans` table and
 * reach the assistant through the prompt, so a price change is one edit in the
 * console rather than a hunt through paragraphs. An answer that names a figure
 * is an answer that will be wrong eventually.
 *
 * Idempotent on the question, so re-seeding a live database refreshes these
 * rows instead of duplicating them — which is what makes it safe to improve a
 * paragraph here and run it again.
 */
class AssistantKnowledgeSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->entries() as $index => $entry) {
            AssistantKnowledge::updateOrCreate(
                ['question' => $entry['question']],
                [...$entry, 'status' => 'active', 'sort_order' => ($index + 1) * 10],
            );
        }

        AssistantKnowledge::forget();
    }

    /** @return array<int, array<string, string|null>> */
    private function entries(): array
    {
        return [
            // ---- Order Bot ----
            [
                'topic' => 'order_bot',
                'question' => 'What is Order Bot?',
                'question_sw' => 'Order Bot ni nini?',
                'keywords' => 'order bot, orderbot, ordering, sell on whatsapp, order bot ni nini, bot ya order',
                'answer' => 'Order Bot turns your WhatsApp number into a shop. Your customers message you, browse your services, place an order and pay — all inside the chat. The order is sent to your SMM panel automatically, so you are not copying anything by hand at midnight.',
                'answer_sw' => 'Order Bot inageuza namba yako ya WhatsApp kuwa duka. Wateja wako wanakutumia ujumbe, wanaona huduma zako, wanaagiza na kulipa — yote ndani ya chat. Oda inapelekwa kwenye SMM panel yako moja kwa moja, hivyo huhitaji kuandika chochote kwa mkono usiku wa manane.',
                'cta_label' => 'See Order Bot pricing',
                'cta_url' => '/pricing',
            ],
            [
                'topic' => 'order_bot',
                'question' => 'How does Order Bot work?',
                'question_sw' => 'Order Bot inafanyaje kazi?',
                'keywords' => 'how does order bot work, order bot inafanyaje, order handling, jinsi order bot inavyofanya kazi',
                'answer' => 'A customer sends *menu* to your number. The bot shows your services and prices, asks for the link and the quantity, takes payment into their wallet, then places the order on your panel and reports back when it is delivered. You watch it happen from your dashboard.',
                'answer_sw' => 'Mteja anatuma *menu* kwenye namba yako. Bot inaonyesha huduma na bei zako, inauliza link na idadi, inapokea malipo kwenye wallet yake, kisha inaweka oda kwenye panel yako na kutoa taarifa ikishakamilika. Wewe unaangalia tu kutoka kwenye dashboard.',
                'cta_label' => 'See how it works',
                'cta_url' => '/features',
            ],
            [
                'topic' => 'order_bot',
                'question' => 'Which SMM panels can I connect?',
                'question_sw' => 'Naweza kuunganisha panel gani?',
                'keywords' => 'panel, smm panel, provider, api, connect panel, panel gani, unganisha panel',
                'answer' => 'Any panel that speaks the standard SMM API v2 — which is nearly all of them. You paste your panel URL and API key, we import your service list, and you set your own selling price on top of each one. You can connect several panels and the bot picks the one you marked as primary.',
                'answer_sw' => 'Panel yoyote inayotumia SMM API v2 ya kawaida — ambazo ni karibu zote. Unaweka URL ya panel yako na API key, tunaingiza orodha ya huduma zako, kisha wewe unaweka bei yako ya kuuza juu ya kila moja. Unaweza kuunganisha panel kadhaa na bot itachagua ile uliyoiweka kuu.',
                'cta_label' => 'Read the API docs',
                'cta_url' => '/api-docs',
            ],

            // ---- Support Bot ----
            [
                'topic' => 'support_bot',
                'question' => 'What is Support Bot?',
                'question_sw' => 'Support Bot ni nini?',
                'keywords' => 'support bot, supportbot, customer support, support bot ni nini, bot ya support',
                'answer' => 'Support Bot answers the questions that arrive after an order: where is my order, it has dropped, can you speed it up, I want a refill. It checks the real order on your panel and answers from that. The ones it cannot settle it hands to you, with the whole conversation attached.',
                'answer_sw' => 'Support Bot inajibu maswali yanayokuja baada ya oda: oda yangu iko wapi, imepungua, unaweza kuiharakisha, nataka refill. Inaangalia oda halisi kwenye panel yako na kujibu kutoka hapo. Zile isizoweza kumaliza inakukabidhi wewe, pamoja na mazungumzo yote.',
                'cta_label' => 'See Support Bot pricing',
                'cta_url' => '/pricing',
            ],
            [
                'topic' => 'support_bot',
                'question' => 'How does Support Bot work?',
                'question_sw' => 'Support Bot inafanyaje kazi?',
                'keywords' => 'how does support bot work, support bot inafanyaje, refill, order status, tiketi',
                'answer' => 'It opens a ticket for every customer question, checks the order on your panel, and handles refills, status and cancellations on its own. Anything it is unsure about becomes a ticket in your inbox — you reply from the dashboard and the customer gets it on WhatsApp.',
                'answer_sw' => 'Inafungua tiketi kwa kila swali la mteja, inaangalia oda kwenye panel yako, na inashughulikia refill, status na kufuta oda yenyewe. Lolote isilokuwa na uhakika nalo linakuwa tiketi kwenye inbox yako — unajibu kutoka dashboard na mteja anapata jibu kwenye WhatsApp.',
                'cta_label' => 'See Support Bot',
                'cta_url' => '/features',
            ],

            // ---- AI Tickets & AI Chat ----
            [
                'topic' => 'ai_tickets',
                'question' => 'What are AI Tickets?',
                'question_sw' => 'AI Tickets ni nini?',
                'keywords' => 'ai tickets, ai ticket, tickets, tiketi za ai, ai',
                'answer' => 'AI Tickets is the add-on that lets the bot answer in its own words instead of only from a menu. It uses your own service list and prices, so it can explain what you sell without inventing anything. Questions it cannot answer still come to you.',
                'answer_sw' => 'AI Tickets ni huduma ya nyongeza inayoruhusu bot kujibu kwa maneno yake badala ya menu tu. Inatumia orodha ya huduma zako na bei zako, hivyo inaweza kueleza unachouza bila kutunga. Maswali isiyoweza kujibu bado yanakuja kwako.',
                'cta_label' => 'See add-on pricing',
                'cta_url' => '/pricing',
            ],
            [
                'topic' => 'ai_tickets',
                'question' => 'Do I need my own AI key?',
                'question_sw' => 'Nahitaji key yangu ya AI?',
                'keywords' => 'ai key, deepseek, openai key, api key ya ai, ai inahitaji key',
                'answer' => 'Yes — for the AI add-on you bring your own DeepSeek key, and DeepSeek bills you directly for what your bot uses. It keeps the cost yours and predictable rather than bundled into a subscription you cannot see inside. You paste the key once in Settings.',
                'answer_sw' => 'Ndiyo — kwa huduma ya AI unaleta key yako mwenyewe ya DeepSeek, na DeepSeek inakutoza wewe moja kwa moja kwa matumizi ya bot yako. Hii inafanya gharama iwe yako na ieleweke, badala ya kufichwa ndani ya subscription. Unaweka key mara moja kwenye Settings.',
                'cta_label' => null,
                'cta_url' => null,
            ],

            // ---- Ready Number / WhatsApp ----
            [
                'topic' => 'ready_number',
                'question' => 'What is a Ready Number?',
                'question_sw' => 'Namba Tayari ni nini?',
                'keywords' => 'ready number, namba tayari, rent a number, rent number, kukodi namba, namba ya kukodi',
                'answer' => 'A Ready Number is a WhatsApp Cloud API number we rent to you, already verified and connected. It exists for people who do not have a Meta Business account or do not want to go through verification — you rent it, and your bot is live the same day.',
                'answer_sw' => 'Namba Tayari ni namba ya WhatsApp Cloud API tunayokukodisha, tayari imethibitishwa na kuunganishwa. Ipo kwa ajili ya watu wasio na akaunti ya Meta Business au wasiotaka kupitia uthibitishaji — unakodi, na bot yako inaanza kufanya kazi siku hiyo hiyo.',
                'cta_label' => 'See Ready Number pricing',
                'cta_url' => '/pricing',
            ],
            [
                'topic' => 'whatsapp_setup',
                'question' => 'Can I use my own WhatsApp number?',
                'question_sw' => 'Naweza kutumia namba yangu ya WhatsApp?',
                'keywords' => 'use my own number, my own number, bring my own number, keep my number, existing number, namba yangu mwenyewe, kutumia namba yangu',
                'answer' => 'Yes. If you have a Meta Business account you connect your own number through WhatsApp Cloud API and keep full ownership of it. If you would rather not deal with Meta, rent a Ready Number from us instead. Both work identically once connected.',
                'answer_sw' => 'Ndiyo. Kama una akaunti ya Meta Business unaunganisha namba yako mwenyewe kupitia WhatsApp Cloud API na unabaki kuwa mmiliki kamili. Kama hutaki kushughulika na Meta, kodi Namba Tayari kutoka kwetu. Zote zinafanya kazi sawa zikishaunganishwa.',
                'cta_label' => 'Start setup',
                'cta_url' => '/register',
            ],
            [
                'topic' => 'whatsapp_setup',
                'question' => 'Is WhatsApp Cloud API required?',
                'question_sw' => 'WhatsApp Cloud API inahitajika?',
                'keywords' => 'whatsapp cloud api, cloud api, meta, business account, api inahitajika, whatsapp business',
                'answer' => 'Yes — the bot runs on WhatsApp Cloud API, which is Meta official. That is what keeps your number safe from being banned, unlike unofficial tools. You either connect your own Cloud API number, or rent a Ready Number from us and skip the setup entirely.',
                'answer_sw' => 'Ndiyo — bot inatumia WhatsApp Cloud API, ambayo ni rasmi kutoka Meta. Ndiyo inayolinda namba yako isifungiwe, tofauti na zana zisizo rasmi. Unaweza kuunganisha namba yako ya Cloud API, au kukodi Namba Tayari kutoka kwetu na kuruka hatua zote za usanidi.',
                'cta_label' => null,
                'cta_url' => null,
            ],
            [
                'topic' => 'whatsapp_setup',
                'question' => 'How do I connect my WhatsApp number?',
                'question_sw' => 'Ninaunganishaje namba yangu ya WhatsApp?',
                'keywords' => 'connect whatsapp, setup whatsapp, webhook, phone number id, kuunganisha whatsapp, usanidi',
                'answer' => 'In the setup wizard you paste four values from your Meta app: the phone number ID, the business account ID, a permanent token and a verify token. We give you the webhook URL to paste back into Meta. The wizard checks the connection before it lets you go live.',
                'answer_sw' => 'Kwenye wizard ya usanidi unaweka vitu vinne kutoka Meta app yako: phone number ID, business account ID, token ya kudumu na verify token. Sisi tunakupa webhook URL ya kuweka kwenye Meta. Wizard inakagua muunganisho kabla ya kukuruhusu kuanza.',
                'cta_label' => 'Create an account',
                'cta_url' => '/register',
            ],

            // ---- Getting started ----
            [
                'topic' => 'getting_started',
                'question' => 'How do I get started?',
                'question_sw' => 'Ninawezaje kuanza?',
                'keywords' => 'get started, how to start, sign up, ninawezaje kuanza, kuanza, anza',
                'answer' => 'Create an account, then the setup wizard walks you through it: connect your SMM panel, import your services and set your prices, connect a WhatsApp number, switch on payments, and send a test message to yourself. Most people are live the same day.',
                'answer_sw' => 'Fungua akaunti, kisha wizard ya usanidi inakuongoza: unganisha SMM panel yako, ingiza huduma zako na weka bei, unganisha namba ya WhatsApp, washa malipo, na jitumie ujumbe wa majaribio. Watu wengi wanaanza siku hiyo hiyo.',
                'cta_label' => 'Create your account',
                'cta_url' => '/register',
            ],
            [
                'topic' => 'getting_started',
                'question' => 'How long does setup take?',
                'question_sw' => 'Usanidi unachukua muda gani?',
                'keywords' => 'how long, setup time, muda, inachukua muda gani, haraka',
                'answer' => 'With a Ready Number, under an hour — most of it is deciding your prices. With your own Meta number it depends on how quickly Meta verifies your business, which is out of anyone here\'s hands. The wizard saves as you go, so you can stop and come back.',
                'answer_sw' => 'Ukitumia Namba Tayari, chini ya saa moja — muda mwingi ni wa kuamua bei zako. Ukitumia namba yako ya Meta inategemea Meta wanachukua muda gani kuthibitisha biashara yako, jambo lisilo mikononi mwetu. Wizard inahifadhi unavyoendelea, hivyo unaweza kusimama na kurudi.',
                'cta_label' => null,
                'cta_url' => null,
            ],
            [
                'topic' => 'getting_started',
                'question' => 'What is a Setup Service?',
                'question_sw' => 'Huduma ya usanidi ni nini?',
                'keywords' => 'setup service, done for you, mtu wa kuniweka, huduma ya usanidi, msaada wa kuanza',
                'answer' => 'If you would rather not do the setup yourself, message us and we will connect your panel, your number and your payments with you. Tell us on WhatsApp what you already have and what you are missing, and we will tell you what is involved.',
                'answer_sw' => 'Kama hutaki kufanya usanidi mwenyewe, tuandikie na tutaunganisha panel yako, namba yako na malipo yako pamoja nawe. Tuambie kwenye WhatsApp una nini tayari na unakosa nini, nasi tutakueleza inahusisha nini.',
                'cta_label' => 'Talk to us',
                'cta_url' => '/contact',
            ],

            // ---- Pricing & payments ----
            [
                'topic' => 'pricing',
                'question' => 'How much does it cost?',
                'question_sw' => 'Bei ni kiasi gani?',
                'keywords' => 'how much does it cost, what does it cost, price list, pricing plans, monthly price, cost per month, subscription cost, bei ni kiasi gani, bei ngapi, gharama ni kiasi gani, bei za huduma',
                'answer' => 'Each service is bought on its own, monthly, so you pay only for what you switch on. Longer terms are discounted, up to 25% on twelve months. The exact figures are in the PRICING section of my instructions and on the pricing page.',
                'answer_sw' => 'Kila huduma inanunuliwa peke yake, kwa mwezi, hivyo unalipia kile unachowasha tu. Muda mrefu zaidi una punguzo, hadi 25% kwa miezi kumi na miwili. Bei kamili ziko kwenye ukurasa wa bei.',
                'cta_label' => 'See full pricing',
                'cta_url' => '/pricing',
            ],
            [
                'topic' => 'pricing',
                'question' => 'Is there a free trial?',
                'question_sw' => 'Kuna majaribio ya bure?',
                'keywords' => 'free trial, trial, free, bure, majaribio, jaribio',
                'answer' => 'There is no free trial, but there is no contract either — you buy one month of one service and stop whenever you like. You can also try the bot yourself before you pay: message our demo number and it will answer you exactly as it would answer your customers.',
                'answer_sw' => 'Hakuna majaribio ya bure, lakini pia hakuna mkataba — unanunua mwezi mmoja wa huduma moja na unaacha wakati wowote. Pia unaweza kujaribu bot mwenyewe kabla ya kulipa: tuma ujumbe kwenye namba yetu ya demo na itakujibu kama itakavyowajibu wateja wako.',
                'cta_label' => 'See pricing',
                'cta_url' => '/pricing',
            ],
            [
                'topic' => 'payments',
                'question' => 'How do I pay?',
                'question_sw' => 'Ninawezaje kulipia?',
                'keywords' => 'pay, payment, mobile money, mpesa, crypto, ninawezaje kulipia, malipo, lipa',
                'answer' => 'Mobile money — M-Pesa, Tigo Pesa, Airtel Money — or crypto. You pick your service and term at checkout and the subscription starts the moment the payment confirms. No card is needed.',
                'answer_sw' => 'Mobile money — M-Pesa, Tigo Pesa, Airtel Money — au crypto. Unachagua huduma na muda wakati wa malipo, na subscription inaanza pale malipo yanapothibitishwa. Huhitaji kadi.',
                'cta_label' => 'See pricing and payment options',
                'cta_url' => '/pricing',
            ],
            [
                'topic' => 'payments',
                'question' => 'How do my customers pay me?',
                'question_sw' => 'Wateja wangu wananilipaje?',
                'keywords' => 'customers pay, customer payment, wallet, wateja wananilipaje, malipo ya wateja',
                'answer' => 'Through your own payment gateway, not ours. You connect your mobile money or crypto account in Settings and your customers top up a wallet inside the chat, then spend it on orders. The money goes to you directly — we never hold it.',
                'answer_sw' => 'Kupitia gateway yako mwenyewe, si yetu. Unaunganisha akaunti yako ya mobile money au crypto kwenye Settings na wateja wako wanaweka pesa kwenye wallet ndani ya chat, kisha wanatumia kwa oda. Pesa inakwenda kwako moja kwa moja — sisi hatuishiki kamwe.',
                'cta_label' => null,
                'cta_url' => null,
            ],
            [
                'topic' => 'pricing',
                'question' => 'Can I cancel anytime?',
                'question_sw' => 'Naweza kusitisha wakati wowote?',
                'keywords' => 'cancel, refund, contract, kufuta, kusitisha, mkataba',
                'answer' => 'Yes. Every service is bought for a fixed term and simply stops at the end of it — nothing renews behind your back and there is nothing to cancel. If you stop paying, your bot pauses and your data stays where it is.',
                'answer_sw' => 'Ndiyo. Kila huduma inanunuliwa kwa muda maalum na inaishia mwisho wa muda huo — hakuna kinachojirudia bila wewe kujua na hakuna cha kufuta. Ukiacha kulipa, bot yako inasimama na taarifa zako zinabaki pale pale.',
                'cta_label' => null,
                'cta_url' => null,
            ],

            // ---- Contact ----
            [
                'topic' => 'contact',
                'question' => 'How do I contact support?',
                'question_sw' => 'Ninawezaje kuwasiliana na support?',
                'keywords' => 'contact, support, help, human, wasiliana, msaada, mtu, nizungumze na mtu',
                'answer' => 'Message us on WhatsApp for the fastest reply, or use the contact form and we will answer by email. If you already have an account, open a ticket from your dashboard — that way we can see your setup while we answer.',
                'answer_sw' => 'Tuandikie kwenye WhatsApp kwa jibu la haraka zaidi, au tumia fomu ya mawasiliano nasi tutajibu kwa barua pepe. Kama tayari una akaunti, fungua tiketi kutoka kwenye dashboard yako — hivyo tutaona usanidi wako tunapojibu.',
                'cta_label' => 'Contact us',
                'cta_url' => '/contact',
            ],
            [
                'topic' => 'contact',
                'question' => 'Can I see a demo?',
                'question_sw' => 'Naweza kuona demo?',
                'keywords' => 'demo, try it, test, example, naomba demo, nijaribu, mfano',
                'answer' => 'Yes — the best demo is the bot itself. Message our demo number on WhatsApp and order something the way a customer would. Nothing is charged and no account is needed.',
                'answer_sw' => 'Ndiyo — demo bora ni bot yenyewe. Tuma ujumbe kwenye namba yetu ya demo kwenye WhatsApp na uagize kitu kama mteja angefanya. Hutozwi chochote na huhitaji akaunti.',
                'cta_label' => 'See what it does',
                'cta_url' => '/features',
            ],

            // ---- Platform questions ----
            [
                'topic' => 'getting_started',
                'question' => 'Do I need my own SMM panel?',
                'question_sw' => 'Nahitaji SMM panel yangu mwenyewe?',
                'keywords' => 'need panel, own panel, provider, nahitaji panel, panel yangu',
                'answer' => 'Yes — we are the layer between your customers and your panel, not the panel itself. You bring an SMM panel you already buy from, connect it with its API key, and set your own prices on top. If you do not have one yet, message us and we will point you at the ones our resellers use.',
                'answer_sw' => 'Ndiyo — sisi ni daraja kati ya wateja wako na panel yako, si panel yenyewe. Unaleta SMM panel unayonunua tayari, unaiunganisha kwa API key yake, na unaweka bei zako juu yake. Kama huna, tuandikie na tutakuelekeza zile ambazo resellers wetu wanatumia.',
                'cta_label' => null,
                'cta_url' => null,
            ],
            [
                'topic' => 'getting_started',
                'question' => 'Do you have an API?',
                'question_sw' => 'Mna API?',
                'keywords' => 'api, api v2, integration, developer, api yenu, muunganisho',
                'answer' => 'Yes. We speak the standard SMM API v2, so anything that can talk to an SMM panel can talk to us — you get an API key from your dashboard and point your existing tools at it.',
                'answer_sw' => 'Ndiyo. Tunatumia SMM API v2 ya kawaida, hivyo chochote kinachoweza kuzungumza na SMM panel kinaweza kuzungumza nasi — unapata API key kutoka dashboard yako na kuelekeza zana zako zilizopo hapo.',
                'cta_label' => 'Read the API docs',
                'cta_url' => '/api-docs',
            ],
            [
                'topic' => 'order_bot',
                'question' => 'What languages does the bot speak?',
                'question_sw' => 'Bot inazungumza lugha gani?',
                'keywords' => 'language, languages, swahili, kiswahili, english, french, lugha',
                'answer' => 'Your customers can be served in English, Kiswahili, French, Turkish or Hindi. Each customer picks their own language in the chat, so one number can serve people who do not share one.',
                'answer_sw' => 'Wateja wako wanaweza kuhudumiwa kwa Kiingereza, Kiswahili, Kifaransa, Kituruki au Kihindi. Kila mteja anachagua lugha yake ndani ya chat, hivyo namba moja inaweza kuhudumia watu wasio na lugha moja.',
                'cta_label' => null,
                'cta_url' => null,
            ],
        ];
    }
}
