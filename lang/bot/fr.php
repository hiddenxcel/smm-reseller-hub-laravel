<?php

/**
 * Order Bot conversation strings — Français.
 * Mirrors en.php key-for-key (en is the fallback).
 */

return [
    // ---- Generic / navigation ------------------------------------------------
    'default_customer_name' => 'cher client',
    'btn_open_menu' => 'Ouvrir le menu',
    'menu_header' => 'Menu principal',
    'send_hi_again' => 'Envoyez *hi* pour recommencer.',
    'not_understood_menu' => 'Veuillez choisir une option dans le menu. Envoyez *hi* pour le revoir.',
    'store_not_ready' => "⚠️ Cette boutique n'est pas encore configurée. Veuillez réessayer plus tard.",
    'route_error' => "Une erreur s'est produite. Envoyez *hi* pour recommencer.",

    // ---- Main menu -----------------------------------------------------------
    'menu_welcome' => "👑 *BIENVENUE, {name_upper}!*\n\n".
        "Bonjour {name}! 👋 Bienvenue chez *{business}*.\n\n".
        "Je suis votre assistant, là pour vous aider à développer vos comptes sur les réseaux sociaux — followers, likes et vues, rapidement, en toute sécurité et à petit prix 🚀📈\n\n".
        '👇 Choisissez une option ci-dessous :',
    'menu_new_order_title' => '🛒 Nouvelle commande',
    'menu_new_order_desc' => 'Followers, likes ou vues',
    'menu_topup_title' => '💰 Ajouter des fonds',
    'menu_topup_desc' => 'Rechargez votre portefeuille',
    'menu_profile_title' => '👤 Mon profil',
    'menu_profile_desc' => 'Solde et dépenses',
    'menu_referral_title' => '🎁 Parrainer un ami',
    'menu_referral_desc' => 'Gagnez à chaque parrainage',
    'menu_track_title' => '📦 Suivre la commande',
    'menu_track_desc' => 'Vérifiez le statut',
    'menu_support_title' => '🎧 Assistance',
    'menu_support_desc' => 'Obtenir de l\'aide',
    'menu_settings_title' => '⚙️ Paramètres',
    'menu_settings_desc' => 'Langue',
    'menu_group_title' => '👥 Notre groupe',
    'menu_group_desc' => 'Rejoignez nos actualités',
    'menu_website_title' => '🌐 Site web',
    'menu_website_desc' => 'Plus en ligne',

    // ---- Settings / language -------------------------------------------------
    'settings_choose_language' => '🌐 *Choisissez votre langue :*',
    'settings_press_language' => 'Veuillez appuyer sur l\'un des boutons de langue.',
    'language_changed' => '✅ Langue définie sur le français.',
    'lang_name_en' => 'English',
    'lang_name_fr' => 'Français',
    'lang_name_sw' => 'Kiswahili',
    'lang_name_tr' => 'Türkçe',
    'lang_name_hi' => 'हिन्दी',

    // ---- New order flow ------------------------------------------------------
    'choose_platform' => '👇 Choisissez une plateforme :',
    'btn_platforms' => 'Plateformes',
    'platforms_header' => 'Plateformes',
    'pick_platform_again' => 'Veuillez choisir une plateforme dans la liste. Envoyez *hi* pour la revoir.',
    'choose_category' => '*{platform}* — choisissez une catégorie :',
    'categories_header' => 'Catégories',
    'category_other' => 'Autre',
    'pick_category_again' => 'Veuillez choisir une catégorie dans la liste. Envoyez *hi* pour recommencer.',
    'choose_service' => '{heading} — choisissez un service :',
    'services_header' => 'Services',
    'btn_services' => 'Services',
    'per_1k' => '/ 1k',
    'pick_service_again' => 'Veuillez choisir un service dans la liste. Envoyez *hi* pour recommencer.',
    'service_paused_label' => 'Indisponible',
    'service_paused' => "⏸️ Celui-ci est indisponible pour le moment. Choisissez-en un autre, ou réessayez plus tard.",
    'how_many' => 'Combien de *{service}* ?',
    'packages_header' => 'Quantité',
    'btn_packages' => 'Forfaits',
    'qty_custom_title' => 'Montant personnalisé',
    'qty_custom_prompt' => '🔢 Saisissez une quantité ({min} – {max}).',
    'qty_out_of_range' => 'Veuillez saisir une quantité entre {min} et {max}.',
    'send_link' => '🔗 Envoyez le *lien* pour *{service}* (URL du profil ou de la publication).',
    'invalid_link' => "Cela ne ressemble pas à un lien valide. Veuillez envoyer l'URL complète (https://…).",
    'confirm_order' => "✅ Confirmez votre commande :\n\n*{service}*\nLien : {link}\nQuantité : {qty}\nTotal : *{total}*",
    'btn_confirm' => 'Confirmer',
    'btn_cancel' => 'Annuler',
    'order_cancelled' => '❌ Commande annulée. Envoyez *hi* pour recommencer.',
    'wallet_charge_failed' => '⚠️ Impossible de débiter votre portefeuille. Veuillez réessayer.',
    'order_placed' => "✅ Commande *#{number}* passée !\n\n*{service}*\nQuantité : {qty}\nDébité : {amount}\nNouveau solde : {balance}\n\nEnvoyez *hi* pour commander à nouveau.",

    // ---- Insufficient balance / top-up decision ------------------------------
    'insufficient_balance' => "💰 Votre solde est de {balance}, mais cette commande coûte {amount}.\nIl vous manque {shortfall}.",
    'btn_topup_pay' => 'Recharger & payer',
    'topup_no_gateway' => "⚠️ Le paiement en ligne n'est pas encore configuré pour cette boutique. Veuillez contacter l'assistance pour ajouter des fonds.",
    'topup_prompt' => '💰 Combien souhaitez-vous ajouter à votre portefeuille ? Saisissez un montant en {cur} (minimum {min}).',
    'topup_amount_invalid' => 'Veuillez saisir un montant valide (minimum {min} {cur}).',

    // ---- Choosing a payment method ------------------------------------------
    'choose_payment_method' => '💳 Comment souhaitez-vous payer *{amount}* ?',
    'btn_choose_payment' => 'Moyens de paiement',
    'payment_header' => 'Payer avec',
    'pay_method_mobile' => 'Payer depuis votre téléphone',
    'pay_method_online' => 'Payer en ligne',
    'pay_method_invalid' => "Ce moyen de paiement n'est pas disponible. Veuillez en choisir un dans la liste.",

    // ---- Payment phone (mobile money) ---------------------------------------
    'ask_pay_phone' => '📱 Saisissez le numéro pour payer (mobile money). Utilisez *{suggest}* ou envoyez un autre numéro.',
    'pay_phone_invalid' => 'Ce numéro de téléphone ne semble pas correct. Veuillez le renvoyer (ex. 07XXXXXXXX).',
    'payment_cancelled' => '❌ Paiement annulé. Envoyez *hi* pour recommencer.',
    'gateway_unavailable' => "⚠️ Passerelle de paiement indisponible. Veuillez contacter l'assistance.",
    'payment_start_failed' => '⚠️ Impossible de démarrer le paiement : {message}.',
    'payment_link' => "💳 Pour ajouter *{amount}* à votre portefeuille, effectuez le paiement ici :\n{url}\n\nVotre commande est passée automatiquement une fois le paiement confirmé.",
    'payment_push' => "💳 Une demande de paiement de *{amount}* a été envoyée à *{phone}*.\nApprouvez-la sur votre téléphone. Votre commande est passée automatiquement une fois le paiement confirmé.",
    'awaiting_payment' => '⏳ En attente de la confirmation de votre paiement. Approuvez la demande sur votre téléphone, ou envoyez *hi* pour annuler et recommencer.',
    'topup_only_link' => "💳 Pour ajouter *{amount}* à votre portefeuille, effectuez le paiement ici :\n{url}",
    'topup_only_push' => '💳 Une demande de paiement de *{amount}* a été envoyée à *{phone}*. Approuvez-la sur votre téléphone — votre portefeuille est crédité automatiquement.',

    // ---- Binance verify flow -------------------------------------------------
    'binance_not_setup' => "⚠️ Le paiement Binance n'est pas encore entièrement configuré pour cette boutique. Veuillez contacter l'assistance.",
    'binance_pay_instructions' => "💰 *Payez {amount} USDT via Binance*\n\n".
        "1️⃣ Ouvrez Binance → *Pay* → *Send*\n".
        "2️⃣ Envoyez *{amount} USDT* à l'ID Binance :\n*{pay_id}*\n".
        "3️⃣ Copiez l'*Order ID* du paiement réussi et envoyez-le ici.\n\n".
        'Votre commande est passée automatiquement une fois le paiement vérifié.',
    'binance_session_expired' => '⚠️ La session de paiement a expiré. Envoyez *hi* pour recommencer.',
    'binance_order_used' => '⚠️ Cet Order ID Binance a déjà été utilisé. Veuillez effectuer un nouveau transfert.',
    'binance_not_configured' => "⚠️ Binance n'est pas configuré pour cette boutique. Veuillez contacter l'assistance.",
    'binance_verify_failed' => "❌ {message}\n\nEnvoyez le bon *Order ID*, ou *cancel*.",
    'binance_verified' => '✅ Paiement vérifié ! Ajout des fonds et passage de votre commande…',

    // ---- Profile -------------------------------------------------------------
    'profile' => "👤 *Votre profil*\n\n".
        "Solde : *{balance}*\n".
        "Total dépensé : {spent}\n".
        "Code de parrainage : *{code}*\n\n".
        'Envoyez *hi* pour le menu.',

    // ---- Referral ------------------------------------------------------------
    'referral_info' => "🎁 *Parrainez & gagnez*\n\n".
        "Partagez votre code *{code}* avec vos amis.\n".
        "Lorsqu'ils rechargent, vous gagnez un bonus sur leur premier dépôt.\n\n".
        "Vos parrainages jusqu'à présent : *{count}*\n".
        "Gains : *{earnings}*\n\n".
        'Envoyez *hi* pour le menu.',
    'referral_ask_code' => "🎁 Avez-vous été invité par un ami ?\n\n".
        'Envoyez son code pour lier votre compte, ou envoyez *skip* pour continuer.',
    'referral_claimed' => "✅ Vous êtes maintenant lié à *{code}*. Cette personne gagnera un bonus sur votre premier rechargement.\n\n".
        'Envoyez *hi* pour le menu.',
    'referral_unknown_code' => "❌ Personne ici n'a ce code. Vérifiez-le et renvoyez-le, ou envoyez *skip*.",
    'referral_already_linked' => "Vous êtes déjà lié à quelqu'un.\n\nEnvoyez *hi* pour le menu.",
    'referral_skipped' => "Pas de problème.\n\nEnvoyez *hi* pour le menu.",

    // ---- Track order ---------------------------------------------------------
    'track_none' => "📦 Vous n'avez pas encore de commande. Envoyez *hi* et choisissez *Nouvelle commande* pour en passer une.",
    'track_header' => "📦 *Vos commandes récentes :*\n",
    'track_line' => "\n*#{number}* — {service}\nStatut : {status} · {amount}",
    'track_footer' => "\n\nEnvoyez *hi* pour le menu.",

    // ---- Support -------------------------------------------------------------
    'support_ai_intro' => "🤖 *Assistance IA* — posez-moi n'importe quelle question sur nos services, commandes ou paiements.\n(Envoyez *hi* pour revenir au menu.)",
    'support_ai_unavailable' => "⚠️ Impossible de joindre l'IA pour le moment. Veuillez réessayer, ou envoyez *hi* pour le menu.",
    'support_human' => "👤 Besoin d'aide ? Discutez avec nous ici :\n{url}\n\nEnvoyez *hi* pour le menu.",
    'support_human_soon' => "👤 Un membre de l'équipe vous contactera sous peu. Envoyez *hi* pour le menu.",

    // ---- Group / website -----------------------------------------------------
    'group_info' => "👥 Rejoignez notre groupe ici :\n{url}\n\nEnvoyez *hi* pour le menu.",
    'website_info' => "🌐 Visitez notre site web :\n{url}\n\nEnvoyez *hi* pour le menu.",
];
