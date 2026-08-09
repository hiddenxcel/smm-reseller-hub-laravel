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
        ];
    }
}
