<?php

/**
 * Order Bot conversation strings — Kiswahili.
 * Mirrors en.php key-for-key (en is the fallback).
 */

return [
    // ---- Generic / navigation ------------------------------------------------
    'default_customer_name' => 'rafiki',
    'btn_open_menu' => 'Fungua Menyu',
    'menu_header' => 'Menyu Kuu',
    'send_hi_again' => 'Tuma *hi* kuanza upya.',
    'not_understood_menu' => 'Tafadhali chagua chaguo kwenye menyu. Tuma *hi* kuiona tena.',
    'store_not_ready' => '⚠️ Duka hili bado halijawekwa tayari. Tafadhali jaribu tena baadaye.',
    'route_error' => 'Kuna hitilafu imetokea. Tuma *hi* kuanza upya.',

    // ---- Main menu -----------------------------------------------------------
    'menu_welcome' => "👑 *KARIBU, {name_upper}!*\n\n".
        "Hujambo {name}! 👋 Karibu *{business}*.\n\n".
        "Mimi ni msaidizi wako, nipo hapa kukusaidia kukuza akaunti zako za mitandao ya kijamii — followers, likes, na views kwa haraka, salama, na nafuu 🚀📈\n\n".
        '👇 Chagua chaguo hapa chini:',
    'menu_new_order_title' => '🛒 Weka Oda Mpya',
    'menu_new_order_desc' => 'Followers, likes au views',
    'menu_topup_title' => '💰 Weka Pesa',
    'menu_topup_desc' => 'Ongeza salio lako',
    'menu_profile_title' => '👤 Wasifu Wangu',
    'menu_profile_desc' => 'Salio na matumizi',
    'menu_referral_title' => '🎁 Mwalike Rafiki',
    'menu_referral_desc' => 'Pata bonus kwa kila rafiki',
    'menu_track_title' => '📦 Fuatilia Oda',
    'menu_track_desc' => 'Angalia hatua ya oda yako',
    'menu_support_title' => '🎧 Huduma Kwa Wateja',
    'menu_support_desc' => 'Pata msaada',
    'menu_settings_title' => '⚙️ Mipangilio',
    'menu_settings_desc' => 'Lugha',
    'menu_group_title' => '👥 Grupu Letu',
    'menu_group_desc' => 'Jiunge na taarifa zetu',
    'menu_website_title' => '🌐 Tovuti',
    'menu_website_desc' => 'Huduma zaidi mtandaoni',

    // ---- Settings / language -------------------------------------------------
    'settings_choose_language' => '🌐 *Chagua lugha yako:*',
    'settings_press_language' => 'Tafadhali bonyeza mojawapo ya vitufe vya lugha.',
    'language_changed' => '✅ Lugha imewekwa kuwa Kiswahili.',
    'lang_name_en' => 'English',
    'lang_name_fr' => 'Français',
    'lang_name_sw' => 'Kiswahili',
    'lang_name_tr' => 'Türkçe',
    'lang_name_hi' => 'हिन्दी',

    // ---- New order flow ------------------------------------------------------
    'choose_platform' => '👇 Chagua mtandao:',
    'btn_platforms' => 'Mitandao',
    'platforms_header' => 'Mitandao',
    'pick_platform_again' => 'Tafadhali chagua mtandao kwenye orodha. Tuma *hi* kuiona tena.',
    'choose_category' => '*{platform}* — chagua kategoria:',
    'categories_header' => 'Kategoria',
    'category_other' => 'Nyingine',
    'pick_category_again' => 'Tafadhali chagua kategoria kwenye orodha. Tuma *hi* kuanza upya.',
    'choose_service' => '{heading} — chagua huduma:',
    'services_header' => 'Huduma',
    'btn_services' => 'Huduma',
    'per_1k' => '/ 1k',
    'pick_service_again' => 'Tafadhali chagua huduma kwenye orodha. Tuma *hi* kuanza upya.',
    'how_many' => 'Unahitaji *{service}* kiasi gani?',
    'packages_header' => 'Kiasi',
    'btn_packages' => 'Vifurushi',
    'qty_custom_title' => 'Kiasi chako',
    'qty_custom_prompt' => '🔢 Andika kiasi ({min} – {max}).',
    'qty_out_of_range' => 'Tafadhali andika kiasi kati ya {min} na {max}.',
    'send_link' => '🔗 Tuma *link* ya *{service}* (URL ya profaili au post).',
    'invalid_link' => 'Hiyo haionekani kuwa link sahihi. Tafadhali tuma URL kamili (https://…).',
    'confirm_order' => "✅ Thibitisha oda yako:\n\n*{service}*\nLink: {link}\nKiasi: {qty}\nJumla: *{total}*",
    'btn_confirm' => 'Thibitisha',
    'btn_cancel' => 'Sitisha',
    'order_cancelled' => '❌ Oda imesitishwa. Tuma *hi* kuanza upya.',
    'wallet_charge_failed' => '⚠️ Imeshindikana kukata salio lako. Tafadhali jaribu tena.',
    'order_placed' => "✅ Oda *#{number}* imewekwa!\n\n*{service}*\nKiasi: {qty}\nImekatwa: {amount}\nSalio jipya: {balance}\n\nTuma *hi* kuweka oda tena.",

    // ---- Insufficient balance / top-up decision ------------------------------
    'insufficient_balance' => "💰 Salio lako ni {balance}, lakini oda hii inagharimu {amount}.\nUnahitaji {shortfall} zaidi.",
    'btn_topup_pay' => 'Weka pesa & lipa',
    'topup_no_gateway' => '⚠️ Malipo mtandaoni bado hayajawekwa kwenye duka hili. Tafadhali wasiliana na huduma kuongeza pesa.',
    'topup_prompt' => '💰 Ungependa kuongeza kiasi gani kwenye salio lako? Andika kiasi kwa {cur} (kima cha chini {min}).',
    'topup_amount_invalid' => 'Tafadhali andika kiasi sahihi (kima cha chini {min} {cur}).',

    // ---- Payment phone (mobile money) ---------------------------------------
    'ask_pay_phone' => '📱 Andika namba ya simu ya kulipia (mobile money). Tumia *{suggest}* au tuma namba nyingine.',
    'pay_phone_invalid' => 'Namba hiyo ya simu haionekani sahihi. Tafadhali tuma tena (mfano 07XXXXXXXX).',
    'payment_cancelled' => '❌ Malipo yamesitishwa. Tuma *hi* kuanza upya.',
    'gateway_unavailable' => '⚠️ Njia ya malipo haipatikani. Tafadhali wasiliana na huduma.',
    'payment_start_failed' => '⚠️ Imeshindikana kuanzisha malipo: {message}.',
    'payment_link' => "💳 Kuongeza *{amount}* kwenye salio lako, kamilisha malipo hapa:\n{url}\n\nOda yako inawekwa moja kwa moja mara malipo yatakapothibitishwa.",
    'payment_push' => "💳 Ombi la malipo la *{amount}* limetumwa kwa *{phone}*.\nLithibitishe kwenye simu yako. Oda yako inawekwa moja kwa moja mara malipo yatakapothibitishwa.",
    'awaiting_payment' => '⏳ Tunasubiri malipo yako yathibitishwe. Thibitisha ombi kwenye simu yako, au tuma *hi* kusitisha na kuanza upya.',
    'topup_only_link' => "💳 Kuongeza *{amount}* kwenye salio lako, kamilisha malipo hapa:\n{url}",
    'topup_only_push' => '💳 Ombi la malipo la *{amount}* limetumwa kwa *{phone}*. Lithibitishe kwenye simu yako — salio lako litaongezwa moja kwa moja.',

    // ---- Binance verify flow -------------------------------------------------
    'binance_not_setup' => '⚠️ Malipo ya Binance bado hayajawekwa kikamilifu kwenye duka hili. Tafadhali wasiliana na huduma.',
    'binance_pay_instructions' => "💰 *Lipa {amount} USDT kupitia Binance*\n\n".
        "1️⃣ Fungua Binance → *Pay* → *Send*\n".
        "2️⃣ Tuma *{amount} USDT* kwa Binance ID:\n*{pay_id}*\n".
        "3️⃣ Nakili *Order ID* kutoka kwenye malipo yaliyofanikiwa na uitume hapa.\n\n".
        'Oda yako inawekwa moja kwa moja mara malipo yatakapothibitishwa.',
    'binance_session_expired' => '⚠️ Kipindi cha malipo kimeisha. Tuma *hi* kuanza upya.',
    'binance_order_used' => '⚠️ Order ID hii ya Binance imeshatumika. Tafadhali fanya muamala mpya.',
    'binance_not_configured' => '⚠️ Binance haijawekwa kwenye duka hili. Tafadhali wasiliana na huduma.',
    'binance_verify_failed' => "❌ {message}\n\nTuma *Order ID* sahihi, au *cancel*.",
    'binance_verified' => '✅ Malipo yamethibitishwa! Tunaongeza pesa na kuweka oda yako…',

    // ---- Profile -------------------------------------------------------------
    'profile' => "👤 *Wasifu Wako*\n\n".
        "Salio: *{balance}*\n".
        "Umetumia jumla: {spent}\n".
        "Namba ya rufaa: *{code}*\n\n".
        'Tuma *hi* kupata menyu.',

    // ---- Referral ------------------------------------------------------------
    'referral_info' => "🎁 *Alika & Pata*\n\n".
        "Shiriki namba yako *{code}* na marafiki.\n".
        "Watakapoweka pesa, unapata bonus kwenye malipo yao ya kwanza.\n\n".
        "Marafiki uliowaalika: *{count}*\n".
        "Mapato: *{earnings}*\n\n".
        'Tuma *hi* kupata menyu.',

    // ---- Track order ---------------------------------------------------------
    'track_none' => '📦 Bado huna oda yoyote. Tuma *hi* uchague *Weka Oda Mpya* kuanza.',
    'track_header' => "📦 *Oda zako za karibuni:*\n",
    'track_line' => "\n*#{number}* — {service}\nHatua: {status} · {amount}",
    'track_footer' => "\n\nTuma *hi* kupata menyu.",

    // ---- Support -------------------------------------------------------------
    'support_ai_intro' => "🤖 *Huduma ya AI* — niulize chochote kuhusu huduma zetu, oda, au malipo.\n(Tuma *hi* kurudi menyu.)",
    'support_ai_unavailable' => '⚠️ Imeshindikana kufikia AI kwa sasa. Tafadhali jaribu tena, au tuma *hi* kupata menyu.',
    'support_human' => "👤 Unahitaji msaada? Wasiliana nasi hapa:\n{url}\n\nTuma *hi* kupata menyu.",
    'support_human_soon' => '👤 Mtumishi wetu atakufikia hivi karibuni. Tuma *hi* kupata menyu.',

    // ---- Group / website -----------------------------------------------------
    'group_info' => "👥 Jiunge na grupu letu hapa:\n{url}\n\nTuma *hi* kupata menyu.",
    'website_info' => "🌐 Tembelea tovuti yetu:\n{url}\n\nTuma *hi* kupata menyu.",
];
