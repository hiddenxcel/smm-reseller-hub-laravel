<?php

/**
 * Order Bot conversation strings — English (source locale).
 *
 * Placeholders use {name} style; {business} = tenant business name,
 * {name} = customer name, {cur} = tenant currency code. Keep every key present
 * in the other locale files (en is the fallback).
 */

return [
    // ---- Generic / navigation ------------------------------------------------
    'default_customer_name' => 'there',
    'btn_open_menu' => 'Open Menu',
    'menu_header' => 'Main Menu',
    'send_hi_again' => 'Send *hi* to start again.',
    'not_understood_menu' => 'Please pick an option from the menu. Send *hi* to see it again.',
    'store_not_ready' => "⚠️ This store isn't set up yet. Please try again later.",
    'route_error' => 'Something went wrong. Send *hi* to start again.',

    // ---- Main menu -----------------------------------------------------------
    'menu_welcome' => "👑 *WELCOME, {name_upper}!*\n\n".
        "Hello {name}! 👋 Welcome to *{business}*.\n\n".
        "I'm your assistant, here to help you grow your social media accounts — followers, likes, and views, fast, safe, and affordable 🚀📈\n\n".
        '👇 Choose an option below:',
    'menu_new_order_title' => '🛒 New Order',
    'menu_new_order_desc' => 'Followers, likes or views',
    'menu_topup_title' => '💰 Add Funds',
    'menu_topup_desc' => 'Top up your wallet',
    'menu_profile_title' => '👤 My Profile',
    'menu_profile_desc' => 'Balance and spending',
    'menu_referral_title' => '🎁 Refer a Friend',
    'menu_referral_desc' => 'Earn on every referral',
    'menu_track_title' => '📦 Track Order',
    'menu_track_desc' => 'Check your order status',
    'menu_support_title' => '🎧 Support',
    'menu_support_desc' => 'Get help',
    'menu_settings_title' => '⚙️ Settings',
    'menu_settings_desc' => 'Language',
    'menu_group_title' => '👥 Our Group',
    'menu_group_desc' => 'Join our updates',
    'menu_website_title' => '🌐 Website',
    'menu_website_desc' => 'More online',

    // ---- Settings / language -------------------------------------------------
    'settings_choose_language' => '🌐 *Choose your language:*',
    'settings_press_language' => 'Please tap one of the language buttons.',
    'language_changed' => '✅ Language set to English.',
    'lang_name_en' => 'English',
    'lang_name_fr' => 'Français',
    'lang_name_sw' => 'Kiswahili',
    'lang_name_tr' => 'Türkçe',
    'lang_name_hi' => 'हिन्दी',

    // ---- New order flow ------------------------------------------------------
    'choose_platform' => '👇 Choose a platform:',
    'btn_platforms' => 'Platforms',
    'platforms_header' => 'Platforms',
    'pick_platform_again' => 'Please pick a platform from the list. Send *hi* to see it again.',
    'choose_category' => '*{platform}* — choose a category:',
    'categories_header' => 'Categories',
    'category_other' => 'Other',
    'pick_category_again' => 'Please pick a category from the list. Send *hi* to start over.',
    'choose_service' => '{heading} — choose a service:',
    'services_header' => 'Services',
    'btn_services' => 'Services',
    'per_1k' => '/ 1k',
    'pick_service_again' => 'Please pick a service from the list. Send *hi* to start over.',
    'service_paused_label' => 'Unavailable',
    'service_paused' => "⏸️ That one is unavailable right now. Please pick another, or try again later.",
    'how_many' => 'How many *{service}*?',
    'packages_header' => 'Quantity',
    'btn_packages' => 'Packages',
    'qty_custom_title' => 'Custom amount',
    'qty_custom_prompt' => '🔢 Enter a quantity ({min} – {max}).',
    'qty_out_of_range' => 'Please enter a quantity between {min} and {max}.',
    'send_link' => '🔗 Send the *link* for *{service}* (profile or post URL).',
    'invalid_link' => "That doesn't look like a valid link. Please send the full URL (https://…).",
    'confirm_order' => "✅ Confirm your order:\n\n*{service}*\nLink: {link}\nQuantity: {qty}\nTotal: *{total}*",
    'btn_confirm' => 'Confirm',
    'btn_cancel' => 'Cancel',
    'order_cancelled' => '❌ Order cancelled. Send *hi* to start again.',
    'wallet_charge_failed' => "⚠️ Couldn't charge your wallet. Please try again.",
    'order_placed' => "✅ Order *#{number}* placed!\n\n*{service}*\nQuantity: {qty}\nCharged: {amount}\nNew balance: {balance}\n\nSend *hi* to order again.",

    // ---- Insufficient balance / top-up decision ------------------------------
    'insufficient_balance' => "💰 Your balance is {balance}, but this order costs {amount}.\nYou need {shortfall} more.",
    'btn_topup_pay' => 'Top up & pay',
    'topup_no_gateway' => "⚠️ Online payment isn't set up for this store yet. Please contact support to add funds.",
    'topup_prompt' => '💰 How much would you like to add to your wallet? Enter an amount in {cur} (minimum {min}).',
    'topup_amount_invalid' => 'Please enter a valid amount (minimum {min} {cur}).',

    // ---- Payment phone (mobile money) ---------------------------------------
    'ask_pay_phone' => '📱 Enter the phone to pay from (mobile money). Use *{suggest}* or send another number.',
    'pay_phone_invalid' => "That phone number doesn't look right. Please send it again (e.g. 07XXXXXXXX).",
    'payment_cancelled' => '❌ Payment cancelled. Send *hi* to start again.',
    'gateway_unavailable' => '⚠️ Payment gateway unavailable. Please contact support.',
    'payment_start_failed' => "⚠️ Couldn't start the payment: {message}.",
    'payment_link' => "💳 To add *{amount}* to your wallet, complete the payment here:\n{url}\n\nYour order is placed automatically once payment is confirmed.",
    'payment_push' => "💳 A payment request for *{amount}* was sent to *{phone}*.\nApprove it on your phone. Your order is placed automatically once payment is confirmed.",
    'awaiting_payment' => '⏳ Waiting for your payment to be confirmed. Approve the prompt on your phone, or send *hi* to cancel and start over.',
    'topup_only_link' => "💳 To add *{amount}* to your wallet, complete the payment here:\n{url}",
    'topup_only_push' => '💳 A payment request for *{amount}* was sent to *{phone}*. Approve it on your phone — your wallet is credited automatically.',

    // ---- Binance verify flow -------------------------------------------------
    'binance_not_setup' => "⚠️ Binance payment isn't fully set up for this store yet. Please contact support.",
    'binance_pay_instructions' => "💰 *Pay {amount} USDT via Binance*\n\n".
        "1️⃣ Open Binance → *Pay* → *Send*\n".
        "2️⃣ Send *{amount} USDT* to Binance ID:\n*{pay_id}*\n".
        "3️⃣ Copy the *Order ID* from the successful payment and send it here.\n\n".
        'Your order is placed automatically once the payment is verified.',
    'binance_session_expired' => '⚠️ Payment session expired. Send *hi* to start again.',
    'binance_order_used' => '⚠️ This Binance Order ID has already been used. Please make a new transfer.',
    'binance_not_configured' => "⚠️ Binance isn't configured for this store. Please contact support.",
    'binance_verify_failed' => "❌ {message}\n\nSend the correct *Order ID*, or *cancel*.",
    'binance_verified' => '✅ Payment verified! Adding funds and placing your order…',

    // ---- Profile -------------------------------------------------------------
    'profile' => "👤 *Your Profile*\n\n".
        "Balance: *{balance}*\n".
        "Total spent: {spent}\n".
        "Referral code: *{code}*\n\n".
        'Send *hi* for the menu.',

    // ---- Referral ------------------------------------------------------------
    'referral_info' => "🎁 *Refer & Earn*\n\n".
        "Share your code *{code}* with friends.\n".
        "When they top up, you earn a bonus on their first deposit.\n\n".
        "Your referrals so far: *{count}*\n".
        "Earnings: *{earnings}*\n\n".
        'Send *hi* for the menu.',
    'referral_ask_code' => "🎁 Were you invited by a friend?\n\n".
        "Send their code to link your account, or send *skip* to carry on.",
    'referral_claimed' => "✅ You are now linked to *{code}*. They will earn a bonus on your first top-up.\n\n".
        'Send *hi* for the menu.',
    'referral_unknown_code' => "❌ No one here has that code. Check it and send it again, or send *skip*.",
    'referral_already_linked' => "You are already linked to someone.\n\nSend *hi* for the menu.",
    'referral_skipped' => "No problem.\n\nSend *hi* for the menu.",

    // ---- Track order ---------------------------------------------------------
    'track_none' => '📦 You have no orders yet. Send *hi* and choose *New Order* to place one.',
    'track_header' => "📦 *Your recent orders:*\n",
    'track_line' => "\n*#{number}* — {service}\nStatus: {status} · {amount}",
    'track_footer' => "\n\nSend *hi* for the menu.",

    // ---- Support -------------------------------------------------------------
    'support_ai_intro' => "🤖 *AI Support* — ask me anything about our services, orders, or payments.\n(Send *hi* to return to the menu.)",
    'support_ai_unavailable' => "⚠️ Couldn't reach the AI just now. Please try again, or send *hi* for the menu.",
    'support_human' => "👤 Need a hand? Chat with us here:\n{url}\n\nSend *hi* for the menu.",
    'support_human_soon' => '👤 A team member will reach out shortly. Send *hi* for the menu.',

    // ---- Group / website -----------------------------------------------------
    'group_info' => "👥 Join our group here:\n{url}\n\nSend *hi* for the menu.",
    'website_info' => "🌐 Visit our website:\n{url}\n\nSend *hi* for the menu.",
];
