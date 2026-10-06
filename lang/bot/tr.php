<?php

/**
 * Order Bot conversation strings — Türkçe (Turkish).
 * Mirrors en.php key-for-key (en is the fallback).
 *
 * NOTE: Machine-assisted translation — a native Turkish speaker should review
 * the wording before heavy production use.
 */

return [
    // ---- Generic / navigation ------------------------------------------------
    'default_customer_name' => 'değerli müşteri',
    'btn_open_menu' => 'Menüyü Aç',
    'menu_header' => 'Ana Menü',
    'send_hi_again' => 'Yeniden başlamak için *hi* yazın.',
    'not_understood_menu' => 'Lütfen menüden bir seçenek seçin. Tekrar görmek için *hi* yazın.',
    'store_not_ready' => '⚠️ Bu mağaza henüz hazır değil. Lütfen daha sonra tekrar deneyin.',
    'route_error' => 'Bir hata oluştu. Yeniden başlamak için *hi* yazın.',

    // ---- Main menu -----------------------------------------------------------
    'menu_welcome' => "👑 *HOŞ GELDİNİZ, {name_upper}!*\n\n".
        "Merhaba {name}! 👋 *{business}* mağazasına hoş geldiniz.\n\n".
        "Ben sizin asistanınızım, sosyal medya hesaplarınızı büyütmenize yardımcı olmak için buradayım — takipçi, beğeni ve görüntülenme; hızlı, güvenli ve uygun fiyatlı 🚀📈\n\n".
        '👇 Aşağıdan bir seçenek seçin:',
    'menu_new_order_title' => '🛒 Yeni Sipariş',
    'menu_new_order_desc' => 'Takipçi, beğeni veya görüntülenme',
    'menu_topup_title' => '💰 Bakiye Yükle',
    'menu_topup_desc' => 'Cüzdanınızı doldurun',
    'menu_profile_title' => '👤 Profilim',
    'menu_profile_desc' => 'Bakiye ve harcama',
    'menu_referral_title' => '🎁 Arkadaş Davet Et',
    'menu_referral_desc' => 'Her davette kazanın',
    'menu_track_title' => '📦 Sipariş Takibi',
    'menu_track_desc' => 'Sipariş durumunu kontrol edin',
    'menu_support_title' => '🎧 Destek',
    'menu_support_desc' => 'Yardım alın',
    'menu_settings_title' => '⚙️ Ayarlar',
    'menu_settings_desc' => 'Dil',
    'menu_group_title' => '👥 Grubumuz',
    'menu_group_desc' => 'Güncellemelerimize katılın',
    'menu_website_title' => '🌐 Web Sitesi',
    'menu_website_desc' => 'Çevrimiçi daha fazlası',

    // ---- AI support ----------------------------------------------------------
    'ai_chat_open' => "🤖 Hizmetlerimiz veya fiyatlarımız hakkında bana her şeyi sorabilirsiniz.\nSipariş vermeye hazır olduğunuzda *menu* yazın.",
    'ai_chat_empty' => 'Lütfen sorunuzu yazın veya geri dönmek için *menu* gönderin.',
    'ai_chat_failed' => '⚠️ Üzgünüm, şu anda buna cevap veremedim.',
    'support_unavailable' => '🎧 Yardım için bize buradan yazın, ekibimiz size dönecektir.',

    // ---- Settings / language -------------------------------------------------
    'settings_choose_language' => '🌐 *Dilinizi seçin:*',
    'settings_press_language' => 'Lütfen dil düğmelerinden birine dokunun.',
    'language_changed' => '✅ Dil Türkçe olarak ayarlandı.',
    'lang_name_en' => 'English',
    'lang_name_fr' => 'Français',
    'lang_name_sw' => 'Kiswahili',
    'lang_name_tr' => 'Türkçe',
    'lang_name_hi' => 'हिन्दी',

    // ---- New order flow ------------------------------------------------------
    'choose_platform' => '👇 *PLATFORM SEÇİN*' . "\n" .
        'Tamam {name}, hesabınızı büyütelim! 🚀' . "\n\n" .
        'Bugün hangi platformu büyütmek istersiniz?',
    'btn_platforms' => 'Platformlar',
    'platforms_header' => 'Platformlar',
    'pick_platform_again' => 'Lütfen listeden bir platform seçin. Tekrar görmek için *hi* yazın.',
    'choose_category' => '📂 *KATEGORİ SEÇİN*' . "\n" .
        '*{platform}* seçtiniz. Hangi kategoriye ihtiyacınız var?',
    'categories_header' => 'Kategoriler',
    'category_other' => 'Diğer',
    'pick_category_again' => 'Lütfen listeden bir kategori seçin. Yeniden başlamak için *hi* yazın.',
    'choose_service' => '🎯 *{heading_upper} HİZMETLERİNİ SEÇİN*' . "\n" .
        'Harika seçim {name}! {heading} seçtiniz.' . "\n\n" .
        'Hesabınıza tam olarak ne gerekiyor? 👇',
    'services_header' => 'Hizmetler',
    'btn_services' => 'Hizmet Seç',
    'per_1k' => '/ 1k',
    'pick_service_again' => 'Lütfen listeden bir hizmet seçin. Yeniden başlamak için *hi* yazın.',
    'service_paused_label' => 'Kullanılamıyor',
    'service_paused' => '⏸️ Bu hizmet şu anda kullanılamıyor. Lütfen başka birini seçin veya daha sonra tekrar deneyin.',
    'how_many' => 'Kaç adet *{service}*?',
    'packages_header' => 'Miktar',
    'btn_packages' => 'Paketler',
    'qty_custom_title' => 'Özel miktar',
    'qty_custom_prompt' => '🔢 Bir miktar girin ({min} – {max}).',
    'qty_out_of_range' => 'Lütfen {min} ile {max} arasında bir miktar girin.',
    'link_request' => '🔗 *BAĞLANTINIZI GÖNDERİN*' . "\n\n" .
        'Merhaba {name}, {qty} sipariş veriyorsunuz.' . "\n\n" .
        '{image_note}📱 Adımlar:' . "\n" . '{steps}' . "\n\n" .
        '📌 Örnek: {example}' . "\n\n" .
        'Ana menüye dönmek için "#" gönderin.',
    'link_see_image' => '👉 Doğru biçim için yukarıdaki görsele bakın.' . "\n\n",
    'link_steps_generic' => '1️⃣ {platform} hesabınızı veya gönderinizi açın' . "\n" .
        '2️⃣ Paylaş simgesine dokunun' . "\n" .
        '3️⃣ Bağlantıyı kopyala seçeneğini seçin' . "\n" .
        '4️⃣ Bağlantıyı aşağıya yapıştırın',
    'link_steps_instagram_profile' => '1️⃣ Instagram profilinizi açın' . "\n" .
        '2️⃣ Profili paylaş seçeneğine dokunun' . "\n" .
        '3️⃣ Bağlantıyı kopyala seçeneğini seçin' . "\n" .
        '4️⃣ Bağlantıyı aşağıya yapıştırın',
    'link_steps_instagram_post' => '1️⃣ Instagram gönderinizi açın' . "\n" .
        '2️⃣ Paylaş simgesine (✈️) dokunun' . "\n" .
        '3️⃣ Bağlantıyı kopyala seçeneğini seçin' . "\n" .
        '4️⃣ Bağlantıyı aşağıya yapıştırın',
    'link_steps_tiktok_profile' => '1️⃣ TikTok hesabınızı açın' . "\n" .
        '2️⃣ Üstteki paylaş simgesine dokunun' . "\n" .
        '3️⃣ Bağlantıyı kopyala seçeneğini seçin' . "\n" .
        '4️⃣ Bağlantıyı aşağıya yapıştırın',
    'link_steps_tiktok_post' => '1️⃣ TikTok videonuzu açın' . "\n" .
        '2️⃣ Paylaş simgesine (✈️) dokunun' . "\n" .
        '3️⃣ Bağlantıyı kopyala seçeneğini seçin' . "\n" .
        '4️⃣ Bağlantıyı aşağıya yapıştırın',
    'link_steps_facebook_profile' => '1️⃣ Facebook hesabınızı açın' . "\n" .
        '2️⃣ Üç noktaya dokunun' . "\n" .
        '3️⃣ Bağlantıyı kopyala seçeneğini seçin' . "\n" .
        '4️⃣ Bağlantıyı aşağıya yapıştırın',
    'link_steps_facebook_post' => '1️⃣ Facebook gönderinizi açın' . "\n" .
        '2️⃣ Paylaş simgesine dokunun' . "\n" .
        '3️⃣ Bağlantıyı kopyala seçeneğini seçin' . "\n" .
        '4️⃣ Bağlantıyı aşağıya yapıştırın',
    'link_steps_youtube_profile' => '1️⃣ YouTube kanalınızı açın' . "\n" .
        '2️⃣ Paylaş seçeneğine dokunun' . "\n" .
        '3️⃣ Bağlantıyı kopyala seçeneğini seçin' . "\n" .
        '4️⃣ Bağlantıyı aşağıya yapıştırın',
    'link_steps_youtube_post' => '1️⃣ YouTube videonuzu açın' . "\n" .
        '2️⃣ Paylaş seçeneğine dokunun' . "\n" .
        '3️⃣ Bağlantıyı kopyala seçeneğini seçin' . "\n" .
        '4️⃣ Bağlantıyı aşağıya yapıştırın',
    'invalid_link' => "Bu geçerli bir bağlantı gibi görünmüyor. Lütfen tam URL'yi gönderin (https://…).",
    'confirm_order' => "✅ Siparişinizi onaylayın:\n\n*{service}*\nBağlantı: {link}\nMiktar: {qty}\nToplam: *{total}*",
    'btn_confirm' => 'Onayla',
    'btn_cancel' => 'İptal',
    'order_cancelled' => '❌ Sipariş iptal edildi. Yeniden başlamak için *hi* yazın.',
    'wallet_charge_failed' => '⚠️ Cüzdanınızdan tahsilat yapılamadı. Lütfen tekrar deneyin.',
    'order_placed' => "✅ Sipariş *#{number}* verildi!\n\n*{service}*\nMiktar: {qty}\nTahsil edilen: {amount}\nYeni bakiye: {balance}\n\nTekrar sipariş vermek için *hi* yazın.",

    // ---- Insufficient balance / top-up decision ------------------------------
    'insufficient_balance' => "💰 Bakiyeniz {balance}, ancak bu sipariş {amount} tutarında.\n{shortfall} daha gerekiyor.",
    'btn_topup_pay' => 'Yükle & öde',
    'topup_no_gateway' => '⚠️ Bu mağaza için çevrimiçi ödeme henüz ayarlanmadı. Bakiye eklemek için lütfen destek ile iletişime geçin.',
    'topup_prompt' => '💰 Cüzdanınıza ne kadar eklemek istersiniz? {cur} cinsinden bir tutar girin (en az {min}).',
    'topup_amount_invalid' => 'Lütfen geçerli bir tutar girin (en az {min} {cur}).',

    // ---- Choosing a payment method ------------------------------------------
    'choose_payment_method' => '💳 *{amount}* tutarını nasıl ödemek istersiniz?',
    'btn_choose_payment' => 'Ödeme yöntemleri',
    'payment_header' => 'Şununla öde',
    'pay_method_mobile' => 'Telefonunuzdan ödeyin',
    'pay_method_online' => 'Çevrimiçi ödeyin',
    'pay_method_invalid' => 'Bu ödeme yöntemi kullanılamıyor. Lütfen listeden birini seçin.',

    // ---- Payment phone (mobile money) ---------------------------------------
    'ask_pay_phone' => '📱 Ödeme yapılacak telefon numarasını girin (mobil ödeme). *{suggest}* kullanın veya başka bir numara gönderin.',
    'pay_phone_invalid' => 'Bu telefon numarası doğru görünmüyor. Lütfen tekrar gönderin (örn. 07XXXXXXXX).',
    'payment_cancelled' => '❌ Ödeme iptal edildi. Yeniden başlamak için *hi* yazın.',
    'gateway_unavailable' => '⚠️ Ödeme ağ geçidi kullanılamıyor. Lütfen destek ile iletişime geçin.',
    'payment_start_failed' => '⚠️ Ödeme başlatılamadı: {message}.',
    'payment_link' => "💳 Cüzdanınıza *{amount}* eklemek için ödemeyi burada tamamlayın:\n{url}\n\nÖdeme onaylandığında siparişiniz otomatik olarak verilir.",
    'payment_push' => "💳 *{amount}* tutarında bir ödeme talebi *{phone}* numarasına gönderildi.\nTelefonunuzdan onaylayın. Ödeme onaylandığında siparişiniz otomatik olarak verilir.",
    'awaiting_payment' => '⏳ Ödemenizin onaylanması bekleniyor. Telefonunuzdaki talebi onaylayın veya iptal edip yeniden başlamak için *hi* yazın.',
    'topup_only_link' => "💳 Cüzdanınıza *{amount}* eklemek için ödemeyi burada tamamlayın:\n{url}",
    'topup_only_push' => '💳 *{amount}* tutarında bir ödeme talebi *{phone}* numarasına gönderildi. Telefonunuzdan onaylayın — cüzdanınıza otomatik olarak eklenir.',

    // ---- Binance verify flow -------------------------------------------------
    'binance_not_setup' => '⚠️ Binance ödemesi bu mağaza için henüz tam olarak ayarlanmadı. Lütfen destek ile iletişime geçin.',
    'binance_pay_instructions' => "💰 *Binance ile {amount} USDT ödeyin*\n\n".
        "1️⃣ Binance'i açın → *Pay* → *Send*\n".
        "2️⃣ Binance ID'ye *{amount} USDT* gönderin:\n*{pay_id}*\n".
        "3️⃣ Başarılı ödemedeki *Order ID*'yi kopyalayıp buraya gönderin.\n\n".
        'Ödeme doğrulandığında siparişiniz otomatik olarak verilir.',
    'binance_session_expired' => '⚠️ Ödeme oturumu sona erdi. Yeniden başlamak için *hi* yazın.',
    'binance_order_used' => '⚠️ Bu Binance Order ID zaten kullanılmış. Lütfen yeni bir transfer yapın.',
    'binance_not_configured' => '⚠️ Binance bu mağaza için yapılandırılmadı. Lütfen destek ile iletişime geçin.',
    'binance_verify_failed' => "❌ {message}\n\nDoğru *Order ID*'yi gönderin veya *cancel* yazın.",
    'binance_verified' => '✅ Ödeme doğrulandı! Bakiye ekleniyor ve siparişiniz veriliyor…',

    // ---- Profile -------------------------------------------------------------
    'profile' => "👤 *Profiliniz*\n\n".
        "Bakiye: *{balance}*\n".
        "Toplam harcama: {spent}\n".
        "Davet kodu: *{code}*\n\n".
        'Menü için *hi* yazın.',

    // ---- Referral ------------------------------------------------------------
    'referral_info' => "🎁 *Davet Et & Kazan*\n\n".
        "*{code}* kodunuzu arkadaşlarınızla paylaşın.\n".
        "Bakiye yüklediklerinde, ilk yüklemelerinden bonus kazanırsınız.\n\n".
        "Şu ana kadarki davetleriniz: *{count}*\n".
        "Kazançlar: *{earnings}*\n\n".
        'Menü için *hi* yazın.',
    'referral_ask_code' => "🎁 Bir arkadaşınız sizi davet etti mi?\n\n".
        'Hesabınızı bağlamak için kodunu gönderin veya devam etmek için *skip* yazın.',
    'referral_claimed' => "✅ Artık *{code}* ile bağlısınız. İlk bakiye yüklemenizde bonus kazanacaklar.\n\n".
        'Menü için *hi* yazın.',
    'referral_unknown_code' => '❌ Burada kimsede bu kod yok. Kontrol edip tekrar gönderin veya *skip* yazın.',
    'referral_already_linked' => "Zaten birine bağlısınız.\n\nMenü için *hi* yazın.",
    'referral_skipped' => "Sorun değil.\n\nMenü için *hi* yazın.",

    // ---- Track order ---------------------------------------------------------
    'track_none' => '📦 Henüz siparişiniz yok. *hi* yazıp *Yeni Sipariş* seçerek sipariş verin.',
    'order_refunded_cancelled' => '✅ *İADE*' . "\n" . '*#{number}* numaralı siparişiniz ({service}) sağlayıcı tarafından iptal edildi.' . "\n" . '' . "\n" . '💰 {amount} cüzdanınıza iade edildi.' . "\n" . 'Bakiye: {balance}',
    'order_refunded_partial' => '✅ *KISMİ İADE*' . "\n" . '*#{number}* numaralı siparişiniz ({service}) yalnızca kısmen teslim edildi.' . "\n" . '' . "\n" . '💰 Teslim edilmeyen kısım için {amount} cüzdanınıza iade edildi.' . "\n" . 'Bakiye: {balance}',
    'payment_credited' => '✅ *ÖDEME ALINDI*' . "\n" . '💰 {amount} cüzdanınıza eklendi.' . "\n" . 'Bakiye: {balance}',
    'payment_credited_order' => '✅ *ÖDEME ALINDI*' . "\n" . '💰 {amount} cüzdanınıza eklendi.' . "\n" . '' . "\n" . '🛒 *#{number}* numaralı siparişiniz ({service}) verildi.' . "\n" . 'Bakiye: {balance}',
    'payment_credited_order_waiting' => '✅ *ÖDEME ALINDI*' . "\n" . '💰 {amount} cüzdanınıza eklendi.' . "\n" . '' . "\n" . '⚠️ Siparişiniz henüz verilemedi — bakiyeniz ({balance}) hâlâ yetersiz. Bakiye ekleyin veya yeniden başlamak için *hi* gönderin.',
    'card_price' => '💰 *Fiyat:* 1K başına {price}',
    'card_quality' => '⭐ *Kalite:* {value}',
    'card_speed' => '⚡ *Hız:* {value}',
    'card_drop' => '🛡️ *Düşüş (drop):* {value}',
    'card_refill' => '♻️ *Yenileme (refill):* {value}',
    'card_link' => '🔗 *Gerekli bağlantı:* {value}',
    'card_range' => '📏 *Sipariş miktarı:* {min} – {max}',
    'card_link_profile' => 'Profil bağlantınız',
    'card_link_post' => 'Gönderi veya video bağlantınız',
    'card_footer' => '✅ Bu hizmetten memnun musunuz? Aşağıdan bir miktar seçin 👇',
    'card_back_hint' => '↩️ Başka bir hizmet seçmek için *back* gönderin.',
    'qty_back_title' => '⬅️ Geri',
    'qty_back_desc' => 'Başka bir hizmet seç',
    'settings_menu' => '⚙️ *AYARLAR*' . "\n" . 'Neyi değiştirmek istersiniz?',
    'btn_language' => '🌐 Dil',
    'btn_currency' => '💱 Para birimi',
    'settings_press_option' => 'Lütfen *Dil* veya *Para birimi* düğmesine dokunun.',
    'settings_choose_currency' => '💱 *PARA BİRİMİNİZİ SEÇİN*' . "\n" . 'Fiyatlar ve bakiyeler seçtiğiniz para biriminde (yaklaşık olarak) gösterilir. Cüzdanınız ve ödemeleriniz {shop} olarak kalır.' . "\n" . '' . "\n" . 'Listede yok mu? 3 harfli kodunu yazın, örneğin *KES*.',
    'currency_more_title' => 'Daha fazla para birimi ➡️',
    'currency_more_desc' => 'Sayfa {page} / {pages}',
    'currency_first_title' => '⬅️ İlk sayfa',
    'currency_first_desc' => 'Listenin başına dön',
    'currency_page' => 'Sayfa {page} / {pages}.',
    'currency_shop_row' => 'Mağazanın para birimi',
    'currency_changed' => '✅ Fiyatlar artık *{currency}* olarak (yaklaşık) gösterilecek. Cüzdanınız ve ödemeleriniz {shop} olarak kalır.',
    'currency_reset' => '✅ Fiyatlar mağazanın para biriminde, *{currency}*, gösteriliyor.',
    'settings_press_currency' => 'Lütfen listeden bir para birimi seçin veya 3 harfli kodunu yazın (örneğin KES).',
    'track_header' => "📦 *Son siparişleriniz:*\n",
    'track_line' => "\n*#{number}* — {service}\nDurum: {status} · {amount}",
    'track_footer' => "\n\nMenü için *hi* yazın.",

    // ---- Support -------------------------------------------------------------
    'support_ai_intro' => "🤖 *Yapay Zeka Desteği* — hizmetlerimiz, siparişleriniz veya ödemeleriniz hakkında bana her şeyi sorabilirsiniz.\n(Menüye dönmek için *hi* yazın.)",
    'support_ai_unavailable' => '⚠️ Şu anda yapay zekaya ulaşılamıyor. Lütfen tekrar deneyin veya menü için *hi* yazın.',
    'support_human' => "👤 Yardıma mı ihtiyacınız var? Bizimle buradan sohbet edin:\n{url}\n\nMenü için *hi* yazın.",
    'support_human_soon' => '👤 Ekip üyemiz kısa süre içinde sizinle iletişime geçecek. Menü için *hi* yazın.',

    // ---- Group / website -----------------------------------------------------
    'group_info' => "👥 Grubumuza buradan katılın:\n{url}\n\nMenü için *hi* yazın.",
    'website_info' => "🌐 Web sitemizi ziyaret edin:\n{url}\n\nMenü için *hi* yazın.",
];
