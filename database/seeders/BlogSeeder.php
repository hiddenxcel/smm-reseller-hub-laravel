<?php

namespace Database\Seeders;

use App\Models\BlogPost;
use Illuminate\Database\Seeder;

/**
 * The opening posts.
 *
 * Written against the questions resellers actually search for — "will my
 * WhatsApp number get banned", "how do I connect my SMM panel to WhatsApp" —
 * rather than around keywords nobody types. A post that answers a real
 * question earns the link; one built backwards from a search volume does not.
 *
 * Idempotent: updateOrCreate on the slug, so re-running does not duplicate
 * and an edited draft here overwrites the live copy.
 */
class BlogSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->posts() as $post) {
            BlogPost::updateOrCreate(['slug' => $post['slug']], $post);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function posts(): array
    {
        return [
            [
                'slug' => 'why-whatsapp-bans-smm-panel-numbers',
                'title' => 'Why WhatsApp bans SMM panel numbers — and how to avoid it',
                'excerpt' => 'Most WhatsApp automation for SMM shops runs on QR-code scraping. Meta detects it, and the number goes with it. Here is what actually happens, and the route that does not risk your account.',
                'author' => 'Resellers Hub',
                'published_at' => now()->subDays(21),
                'body' => <<<'BODY'
If you sell SMM services and you have automated WhatsApp, you have probably met one of two outcomes: a number that works fine for months, or a number that disappears overnight with every customer conversation in it.

The difference is almost never luck. It is which door your tool knocked on.

## The two ways a bot talks to WhatsApp

There are only two, and they are not variations on a theme.

The first is **QR-code scraping**. A tool opens WhatsApp Web in a hidden browser, you scan a code with your phone, and from then on the tool acts as though it were you sitting at a keyboard. It is quick to set up, cheap to build, and it is what most inexpensive bots use.

The second is the **Cloud API** — the interface Meta built for businesses. You register a number, Meta issues credentials, and messages move over an official channel that Meta can see and account for.

## Why the first one gets numbers banned

WhatsApp Web was built for a person at a laptop. Meta knows what a person at a laptop looks like: pauses between messages, typing that varies, a session that ends when they close the tab.

Automation does not look like that. It replies in eighty milliseconds, at four in the morning, to two hundred people, forever. Meta's systems are good at spotting the difference, and the terms of service are explicit that unofficial clients are not allowed.

When it is spotted, the ban is not a warning. The number stops working, the chats are gone, and the customers who had been messaging you for a year have no way to reach you.

- Your conversation history goes with the number
- Customers with money in their wallet cannot reach you
- The number itself is usually unrecoverable

For a shop where WhatsApp *is* the storefront, that is not an inconvenience. That is the business.

## What the Cloud API changes

On the official API, automation is the point. Meta expects a business to reply instantly, at scale, at any hour — that is what the product is for. There is nothing to detect, because nothing is being hidden.

There are trade-offs and it is fair to name them. You need a Meta Business account, which takes days to approve. Meta charges for conversations. Message templates need approval before you can start a conversation outside a 24-hour window.

But your number is yours, and it stays yours.

## What this means if you are choosing a tool

Ask one question before anything about features or price: **does it scan a QR code, or does it use the Cloud API?**

If the answer is a QR code — or if the setup asks you to scan something with your phone — you are being sold speed today at the cost of the number later.

If you do not have a Meta Business account yet, that is a solvable problem. Some platforms, this one included, will rent you a Cloud API number so you can start today and move to your own once you are approved. That is a delay. A ban is not.
BODY,
            ],
            [
                'slug' => 'connect-smm-panel-to-whatsapp',
                'title' => 'How to connect your SMM panel to WhatsApp',
                'excerpt' => 'Your panel already has an API. This is what it takes to put it behind a WhatsApp bot that sells, charges and delivers — without replacing anything you already run.',
                'author' => 'Resellers Hub',
                'published_at' => now()->subDays(14),
                'body' => <<<'BODY'
Almost every SMM panel speaks the same API. That is the quiet fact that makes this possible: Perfect Panel, Apex, Glycon and most of the scripts in circulation all implement the standard SMM API v2, and a tool written against one works against the others.

So connecting a panel to WhatsApp is not a migration. It is a layer.

## What you already have

Your panel does three things a bot needs:

- It lists services, with prices, minimums and maximums
- It accepts an order — service, link, quantity
- It reports status, and handles refills

That is the whole surface. Everything a customer wants to do over WhatsApp maps onto one of those calls.

## What the layer adds

What the panel does not have is a conversation. It cannot ask "which platform?", it cannot hold a wallet balance, and it cannot tell a customer at 2am that their order is halfway done.

That is what sits on top:

- A menu the customer walks through in WhatsApp
- A wallet per customer, topped up through your own payment gateways
- The order placed on your panel the moment the balance covers it
- Status and refill questions answered from the panel, without you looking

## Connecting it

In practice it is two fields — the panel address and an admin API key.

Everything else is detectable. Whether the endpoint wants `/api/v2` on the end, whether the key goes in the request body or a header, which API version it speaks: a good tool probes for these rather than asking you, because you should not have to know.

The key is worth a note. It is an **admin** key, which means it can place orders and read your balance. Store it somewhere it is encrypted at rest, and rotate it if it has ever been pasted into a chat window.

## What does not change

Your panel keeps doing its job. Your suppliers, your pricing rules, your existing customers on the web — none of that moves.

What changes is that a customer who would have opened a browser, logged in and filled a form can now send a message instead. For most SMM buyers that is the difference between ordering and not bothering.
BODY,
            ],
            [
                'slug' => 'accepting-mobile-money-and-crypto',
                'title' => 'Taking mobile money and crypto for SMM orders',
                'excerpt' => 'Card payments fail for most of the people buying SMM services. Here is how wallets, mobile money and stablecoins actually work for a reseller shop — and why the money should never pass through your platform.',
                'author' => 'Resellers Hub',
                'published_at' => now()->subDays(7),
                'body' => <<<'BODY'
If you sell SMM services outside of North America and Europe, you have already discovered that a card checkout does not work. Most of your buyers do not have a card. The ones who do often find it declined on a cross-border transaction.

What they do have is mobile money and, increasingly, stablecoins.

## The wallet model

The pattern that works is not "pay per order". It is a wallet.

A customer tops up once — say ten dollars — and then orders against that balance. It matters for three reasons:

- One payment covers many orders, so the friction is paid once
- The order can be placed the instant it is confirmed, with no waiting
- You are not chasing a payment for a two-dollar order

The bot charges the wallet before placing anything on your panel. If the balance does not cover it, the customer is prompted to top up and the order places itself the moment the money lands.

## What "confirmed" has to mean

This is where shops lose money.

A customer sends a screenshot of a payment. Someone on your side looks at it, believes it, and credits the wallet. Sometimes the screenshot is edited. Sometimes the payment was reversed. Sometimes it was never made.

The only safe confirmation comes from the gateway itself — a webhook, or a lookup against the gateway's API. Not a screenshot, not a reference number the customer typed, and not your own eyes at midnight.

## Keeping the money yours

There is a structural question worth asking about any platform you use: **does the money pass through them?**

If it does, you are trusting a third party with your revenue, your payout schedule and your chargebacks. If that company has a bad month, so do you.

The alternative is that you connect your own gateway accounts — your keys, your merchant ID — and the funds move from your customer to you directly. The platform never touches them. It only knows that a payment cleared, so it can credit the right wallet.

That is worth checking before you commit. Ask where the money sits, and who can move it.

## Which gateways

For East Africa, mobile money is the answer, usually through an aggregator that covers M-Pesa, Tigo Pesa and Airtel Money behind one integration.

For international customers, USDT is the practical choice — it settles in minutes, it does not care about borders, and the fee is predictable.

Cards still have a place for the customers who have them. They are just no longer the default.
BODY,
            ],
            [
                'slug' => 'start-without-meta-business-account',
                'title' => 'Starting a WhatsApp bot without a Meta Business account',
                'excerpt' => 'The Cloud API needs a verified Meta Business account, and approval takes days. Here is what the wait actually involves — and how to be selling before it finishes.',
                'author' => 'Resellers Hub',
                'published_at' => now()->subDays(2),
                'body' => <<<'BODY'
Everyone who sets out to build a WhatsApp bot the right way hits the same wall: the Cloud API needs a Meta Business account, and getting one is not instant.

It is worth understanding what the wait is, because it changes what you should do about it.

## What Meta is actually checking

Business verification is Meta confirming that your business exists and that you are entitled to act for it. In practice that means:

- A Meta Business account, created and filled in
- Business documents — registration, a utility bill, something official
- A phone number that is not already on WhatsApp
- A review, which is where the days go

None of it is difficult. It is just not fast, and the review queue does not care that you were hoping to launch this week.

## The trap of not waiting

The temptation at this point is to reach for a QR-code tool "just to get started". It works immediately, it costs almost nothing, and it is the single most expensive shortcut in this business.

Unofficial clients get numbers banned, and the ban takes your conversation history with it. Starting on one means either living with that risk or migrating later — and migrating means telling every customer you have a new number.

## Renting a number instead

The other option is to rent a Cloud API number from a platform that already has them provisioned.

You get a number that is live today, on the same official API you would have used, with the same protection against bans. Your bot answers, your customers order, and none of it is a workaround.

When your own Meta approval comes through, you switch. The bot, the flows, the customer wallets and the order history all stay where they are — only the number underneath changes.

## Which to choose

If you are not in a hurry, do the verification and use your own number. It is cheaper over time and there is nothing to move later.

If you want to be selling this week, rent one and run the verification in the background. The point is that "wait" and "risk your number" are not the only two options, and the third one costs less than either.
BODY,
            ],

            // ---- East Africa -------------------------------------------
            //
            // The market with the least competition for these terms and the
            // most buyers already holding the payment method: someone
            // searching "M-Pesa SMM panel" is a customer, not a browser.

            [
                'slug' => 'smm-panel-mpesa-payments-kenya-tanzania',
                'title' => 'Accepting M-Pesa for SMM panel orders in Kenya and Tanzania',
                'excerpt' => 'Card checkouts fail for most East African buyers, and manual M-Pesa confirmation does not survive the tenth order. Here is how mobile money works for an SMM reseller, and where shops lose money doing it by hand.',
                'author' => 'Resellers Hub',
                'published_at' => now()->subDays(14),
                'body' => <<<'BODY'
If you sell SMM services in Kenya, Tanzania or Uganda, you already know that a card checkout is not the answer. Most of your buyers do not hold a card. The ones who do often watch it decline on a cross-border charge.

What almost all of them hold is a mobile money wallet — M-Pesa in Kenya and Tanzania, Tigo Pesa and Airtel Money alongside it.

## Why manual confirmation stops working

Most shops start the same way. A customer sends money to a till number, screenshots the confirmation, and pastes it into WhatsApp. Someone reads it and credits the order by hand.

That works for the first ten orders. Then it becomes the whole job.

It also has a hole in it. An SMS confirmation is text, and text can be edited. A reversed payment looks identical to a completed one. A reference number that was never issued looks like one that was. The shop finds out days later, when the panel balance does not match what came in.

## What confirmation has to mean

The only confirmation worth acting on comes from the gateway, not the customer.

That means a webhook — the gateway telling your system a payment cleared — or your system asking the gateway's API directly. Not a screenshot. Not a reference the customer typed. Not your own reading of an SMS at eleven at night.

Once that is in place the sequence is boring, which is the point: the customer pays, the gateway confirms, the wallet is credited, the order goes to the panel. Nobody is awake for any of it.

## Aggregator or direct

Going direct to Safaricom for a Daraja integration means paperwork, a registered business, and a wait.

An aggregator — Pesapal and Flutterwave both cover the region — puts M-Pesa, Tigo Pesa and Airtel Money behind one integration and one set of keys. You pay a slightly higher fee for the convenience of not building three of them.

For most resellers starting out the aggregator is the right trade. You can always move to a direct integration once the volume makes the fee difference matter.

## Keep the money in your own account

This matters more than the gateway you choose: connect your own accounts, with your own keys.

If a platform collects your customers' payments into its account and pays you out later, then your revenue, your payout timing and your chargeback exposure all belong to somebody else's company. If they have a bad month, so do you.

The alternative is that the money moves from your customer to your merchant account directly, and the platform only learns that a payment cleared so it can credit the right wallet. Ask any platform you are considering which of those it does.

## Top up once, order many times

One last thing worth building in: charge a wallet, not an order.

Mobile money has a per-transaction cost and a few taps of friction. Paying it on a two-dollar order is painful; paying it once on a ten-dollar top-up, then ordering against the balance, is not. The customer tops up when they run low, and every order after that is instant.
BODY,
            ],

            [
                'slug' => 'swahili-whatsapp-bot-smm-reseller',
                'title' => 'Running your SMM bot in Kiswahili — and why it sells more',
                'excerpt' => 'Most SMM bots answer in English only. In East Africa that quietly costs you the customers who would rather buy in Kiswahili, and it is a setting rather than a rebuild.',
                'author' => 'Resellers Hub',
                'published_at' => now()->subDays(9),
                'body' => <<<'BODY'
Most SMM automation speaks English and nothing else. In Nairobi or Dar es Salaam that is not a blocker — plenty of buyers read English fine — but it is a filter, and filters cost money quietly.

The customer who hesitates over an English menu does not complain. They just stop replying.

## Language per customer, not per shop

The instinct is to pick one language for the shop. That is the wrong unit.

Your customers do not share a language. Some want Kiswahili, some prefer English, some are ordering from outside the region entirely. A shop-wide setting makes you choose which half to serve.

The setting that works is per customer: each person's conversation runs in their own language, decided the first time they message and remembered after that. One bot, one number, one catalogue — several languages.

## What actually needs translating

Not everything. The parts that carry money or instructions:

- The menu and service names
- Prices and wallet balance
- Payment instructions, which is where confusion is most expensive
- Order confirmations and status updates
- The error you send when something fails

Marketing copy matters less than people expect. A customer who has already opened the chat is not reading your pitch — they are trying to buy something, and they want the next step to be unambiguous.

## Where it changes behaviour

Two places, in our experience.

The first is payment. "Tuma pesa kwa namba hii" removes a hesitation that "send payment to this number" leaves in place for some buyers. Payment instructions are exactly where a misunderstanding turns into a support message instead of an order.

The second is support. A customer who can describe a problem in their own language gives you a usable description. In a second language they give you "haifanyi kazi", and you spend three messages finding out what it means.

## The practical setup

If you are on Resellers Hub, the bot speaks English, French, Kiswahili, Turkish or Hindi, chosen per customer rather than per shop. It is a setting, not a rebuild.

If you are building your own, the thing to get right early is that the language belongs to the customer record, not to a global config. Retrofitting that later means touching every message you send.
BODY,
            ],

            // ---- West Africa -------------------------------------------
            //
            // The biggest SMM market on the continent, and the one where
            // "which gateway" is the question people actually search.

            [
                'slug' => 'paystack-flutterwave-smm-panel-nigeria',
                'title' => 'Paystack or Flutterwave for an SMM panel in Nigeria',
                'excerpt' => 'Both take Nigerian payments; they are not interchangeable for a reseller shop. What matters is settlement, webhooks and who holds the money — not the checkout page.',
                'author' => 'Resellers Hub',
                'published_at' => now()->subDays(6),
                'body' => <<<'BODY'
Nigeria is the largest SMM reseller market in Africa, and the question every new shop asks is the same one: Paystack or Flutterwave?

Both will take your customers' money. The differences that matter to a reseller are not on the pricing page.

## What a reseller actually needs from a gateway

Set aside the checkout design. For a shop selling automated services, the gateway has to do three things well:

- **Confirm payments to your software, not to your inbox.** A webhook your system can act on, so an order places itself.
- **Settle predictably.** You are buying panel credit with this money. A settlement cycle you cannot predict is working capital you do not have.
- **Let you verify a payment on demand.** Webhooks get missed. An API you can ask "did this reference actually clear?" is what closes that gap.

Anything else is preference.

## Paystack

Strong Nigerian coverage, clean API, and webhooks that behave. Bank transfer and USSD are first-class, which matters because a large share of Nigerian buyers pay that way rather than by card.

If your customers are overwhelmingly Nigerian, this is the simpler choice.

## Flutterwave

Wider geographic reach — Nigeria, Ghana, Kenya and beyond on one integration. If you sell into more than one country, or expect to, that is the argument for it.

The price of that breadth is a larger API surface. You are integrating a platform rather than a payment method.

## The honest answer

If you sell only in Nigeria, start with Paystack. If you already sell across borders, or intend to within the year, Flutterwave saves you a second integration later.

Neither decision is permanent, which is the more useful point. A shop that treats gateways as pluggable can add the second one when a customer asks for it.

## The question that matters more

Whichever you pick, connect it with your own keys, under your own merchant account.

Some platforms collect on your behalf and pay you out on their schedule. That means your revenue sits in another company's account, their payout timing is your cash flow, and their risk decisions are your problem.

The alternative is that funds move from your customer to you directly, and the platform only sees that a payment cleared so it can credit the right wallet. For a business buying panel credit out of today's revenue, that difference is worth more than a few basis points on the fee.

## One gateway is not enough for long

Nigerian payments fail more often than most — bank downtime, limits, cards that decline for no stated reason.

A shop with one gateway loses those orders. A shop with two offers the customer another way to pay when the first one fails. Set the second one up before you need it, not during the outage.
BODY,
            ],

            [
                'slug' => 'start-smm-reseller-business-with-no-capital',
                'title' => 'Starting an SMM reseller business with almost no capital',
                'excerpt' => 'The honest version: what you actually have to pay for on day one, what can wait, and the two mistakes that cost beginners the most money.',
                'author' => 'Resellers Hub',
                'published_at' => now()->subDays(3),
                'body' => <<<'BODY'
Most guides to starting an SMM reseller business are written by people selling you something. Here is the version with the costs left in.

## What you actually need on day one

Three things, and only three:

- **A supplier panel.** Somewhere to buy the services you resell. Most take a small first deposit.
- **A way to take payment.** Your own gateway account — mobile money, bank transfer or crypto, depending on where your customers are.
- **A way for customers to order.** WhatsApp is where they already are.

That is the whole list. Not a website, not a logo, not a company registration in most places, not a subscription to anything until you are actually selling.

## What can wait

A custom panel of your own. A brand. Paid advertising. An API integration for other resellers to buy from you.

All of these are things you build once orders exist. Building them first is how people spend three months and a few hundred dollars before discovering nobody wanted the service they picked.

## The first mistake: buying the cheapest services

The cheapest provider on any panel list is cheap for a reason. Drops, refills that never complete, orders that sit pending for a week.

You will not notice on your first order. You will notice on your fortieth, when a third of your customers are asking where their followers went, and you are refunding out of a margin that was thin to begin with.

Test a supplier with your own money before selling their services. One order, watched to completion, is worth more than any review.

## The second mistake: doing it by hand

Manual works and it feels free. A customer messages, you check the price, they send payment, you check the payment, you place the order, you report back.

Twenty minutes per order at fifty cents' margin is not a business — it is a job that pays badly. And it caps you at the hours you are awake, in a market where a customer who waits until morning has usually bought elsewhere by then.

The point at which to automate is earlier than most people think. Roughly: when you are getting more than a few orders a day, or when you have missed one overnight.

## What automation actually costs

Less than the orders it saves, which is the only comparison worth making.

A bot that takes orders, charges a wallet, forwards to your panel and answers status questions runs from a few dollars a month. One recovered overnight order usually covers it.

The mistake is treating it as a purchase you make once you are big. It is the thing that lets you get there — because it removes the ceiling your own sleep puts on the shop.

## A realistic first month

Pick one service you understand — Instagram followers, say — and one supplier you have tested. Price it with a margin you can defend. Put it behind a bot so orders do not need you.

Then spend your time on the only thing that is actually scarce: customers. The machinery is cheap now. Attention is not.
BODY,
            ],

            // ---- International English ----------------------------------
            //
            // The hardest terms to rank for, so these answer questions the
            // generic guides skip rather than competing head-on.

            [
                'slug' => 'whatsapp-bot-vs-telegram-bot-smm-panel',
                'title' => 'WhatsApp or Telegram for your SMM bot?',
                'excerpt' => 'Telegram is easier to build on and free to run. That is not the same as being the right place to sell — and for most reseller shops it is not.',
                'author' => 'Resellers Hub',
                'published_at' => now()->subDays(2),
                'body' => <<<'BODY'
Telegram bots are easier. The API is free, the setup takes an afternoon, and there is no business verification standing between you and a working bot.

For a lot of SMM shops it is still the wrong choice, and the reason has nothing to do with the technology.

## Sell where the customers already are

The question is not which platform is nicer to build on. It is which one your buyers already have open.

In most of Africa, South Asia and Latin America, that is WhatsApp — not as one app among several, but as the default way people message anyone at all. In parts of Eastern Europe, Iran and the crypto-adjacent world, it is Telegram by the same margin.

A bot on the platform your customer does not use is a bot that asks them to install something before buying a two-dollar service. Most will not.

## What each one actually costs

Telegram is free and unlimited.

WhatsApp charges per conversation window through the Cloud API, and requires a verified Meta Business account to use your own number.

That sounds decisive until you price it against a lost order. Conversation costs are small fractions of a dollar; a customer who does not buy because they would have to install an app costs you the whole margin.

## The risk nobody mentions

Telegram bots are safe to run. WhatsApp bots are safe to run **if you use the official Cloud API**.

The tools that scan a QR code to hijack a normal WhatsApp account are the ones that get numbers banned — and the ban takes your conversation history and your customer list with it. That risk is real, and it is entirely avoidable by using the official route.

If you are comparing a Telegram bot against a QR-code WhatsApp tool, Telegram wins easily. Against the Cloud API, it is a straight question of where your customers are.

## Why not both

There is no rule that says one.

The sensible pattern is one catalogue, one wallet balance and one order history, reachable from more than one place. A customer on Telegram and a customer on WhatsApp buy from the same shop and see the same prices.

If you build the ordering logic tied to a single messaging platform, adding the second one later means rewriting it. Keep the shop separate from the channel, and the channel becomes a detail.

## The short answer

Look at your last twenty customers and ask which app they messaged you on first. That is your answer, and it is more reliable than any comparison table.
BODY,
            ],

            [
                'slug' => 'smm-panel-api-integration-guide',
                'title' => 'What the standard SMM panel API actually looks like',
                'excerpt' => 'Nearly every panel speaks the same four actions. Once you know the shape, connecting one — or ten — stops being a per-panel integration project.',
                'author' => 'Resellers Hub',
                'published_at' => now()->subDay(),
                'body' => <<<'BODY'
Almost every SMM panel in existence exposes the same API. Not similar — the same, down to the parameter names, because they nearly all descend from the same original script.

Knowing its shape is what turns "integrating a panel" into a configuration step.

## The four actions

One endpoint, usually `/api/v2`, taking POST requests with a `key` and an `action`:

- **services** — the full catalogue: id, name, rate per thousand, minimum and maximum quantity
- **add** — place an order: service id, link, quantity. Returns an order id.
- **status** — where an order stands: pending, in progress, completed, partial, canceled. Usually with start count and remaining.
- **balance** — what is left in your account

There are extras — refill, cancel, multi-status — and support for those varies. The four above are effectively universal.

## Where the money is made

The rate a panel returns is your cost, not your price.

Your margin is whatever you add on top, and the decision is per service rather than global: competitive services need thin margins, unusual ones carry more. A flat percentage across a catalogue of a thousand services leaves money on the table at one end and prices you out at the other.

This is also why importing a catalogue wholesale and selling it untouched rarely works. The catalogue is the supplier's, the prices have to be yours.

## What breaks in practice

Three things, consistently:

- **Balance runs out.** Orders start failing while everything looks healthy from the outside. Watch the balance, not just the orders.
- **Service ids change.** A panel reorganises its catalogue and the id you saved now points at something else, or nothing. Re-sync rather than trusting a stored id forever.
- **Status stops moving.** An order sits "in progress" for days. You need a definition of stuck and something that surfaces it, or your customer finds it before you do.

## More than one panel

Most shops end up with several — one for cheap volume, one that is reliable for the services that matter, one that carries something niche.

The thing to get right is that a service remembers which panel it came from. When an order is placed, it has to go back to that panel, with that panel's service id and that panel's key. Mixing them up sends an order into the void with no error to explain it.

## Building on top

If you are writing this yourself, the shape above is all you need for a first version.

If you are using a platform, the useful question is whether it exposes the same API outward — so your own customers can buy from you programmatically, the same way you buy from your suppliers. A reseller who can sell to other resellers has a different business from one who cannot.
BODY,
            ],
        ];
    }
}
