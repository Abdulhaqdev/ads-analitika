# Facebook reklama tahlil agenti — qanday qurilgan

Bu hujjat boshqa odam (yoki Claude Code) shu tizimni noldan qayta qurishi uchun yozilgan. Maxfiy ma'lumotlar (tokenlar, parollar, kalitlar) bu yerda yo'q: faqat `.env` o'zgaruvchilarining nomlari berilgan.

---

## 1. Tizim nima qiladi

Facebook/Instagram reklama akkauntlariga ulanadi va:

- barcha **faol** akkauntlarni bitta panelda ko'rsatadi (sarf, natija, narx, holat);
- har bir reklamani **o'z natijasi** bo'yicha baholaydi: lid, xabar (direct) yoki trafik (klik);
- qaysi reklama **qimmatlashganini**, **sababini** va **nima qilish kerakligini** qisqa ko'rsatadi (rasm, narx, kichik grafik, bir qator sabab, bir qator tavsiya);
- yosh/jins, soat, hafta kuni, joylashuv, qurilma, hudud bo'yicha taqsimotni ko'rsatadi, istalgan sana oralig'i uchun;
- Telegram botga **har bir akkaunt uchun alohida xabar** yuboradi (`#akkaunt_nomi` hashtag, tugmalar, savolga javob).

**Texnologiyalar:** Laravel 13 (PHP 8.3+), SQLite, Blade + Tailwind CSS v4 (Vite), Chart.js (CDN), Facebook Graph API (v26), Telegram Bot API.

---

## 2. Qurish tartibi (qadamlar)

### 2.1 Loyiha
```bash
composer create-project laravel/laravel ads-analytics
cd ads-analytics && npm install && npm run build
```
SQLite yetarli. Admin foydalanuvchi seeder orqali yaratiladi (`ADMIN_EMAIL`, `ADMIN_PASSWORD` `.env` dan).

### 2.2 Meta (Facebook) ilovasi
1. https://developers.facebook.com → **Create App**.
2. Ilova nomida `meta`, `facebook`, `insta`, `book` kabi brend so'zlar **bo'lmasin** (rad etiladi).
3. Use case: **Marketing API orqali reklama yaratish va sozlash** (yoki Business turi).
4. Biznes-portfolio ulash shart emas ("hozir ulamayman" ni tanlash mumkin): OAuth orqali kirganda akkaunt kira oladigan barcha reklama akkauntlari ko'rinadi.
5. **App settings → Basic:** App ID va App Secret ni `.env` ga yozing; **App Domains** ga o'z domeningizni qo'shing.
6. **Facebook Login for Business → Settings:** *Valid OAuth Redirect URIs* ga `https://<domen>/admin/facebook/callback` ni qo'shing. Client OAuth va Web OAuth **yoqilgan** bo'lsin.
7. Faqat shu ruxsat so'raladi: **`ads_read`**. (`read_insights` — sahifa ruxsati, "Invalid Scopes" xatosini beradi.)

> **Muhim:** Meta `http://localhost` ni qabul qilmaydi ("HTTPS majburiy"). Mahalliy sinov uchun HTTPS tunnel (masalan `cloudflared tunnel --url http://localhost:8000`) yoki haqiqiy domen kerak. Tunnel manzili har safar o'zgaradi, shuning uchun doimiy ishlashi uchun domen/serverga joylang.

Ilova **Development** rejimida bo'lsa, faqat ilovaning admin/developer/tester rolidagi akkauntlar ulana oladi. `ads_read` uchun o'zingizning akkauntingizga App Review kerak emas.

### 2.3 `.env` o'zgaruvchilari (nomlari)
```
FACEBOOK_APP_ID=
FACEBOOK_APP_SECRET=
FACEBOOK_REDIRECT_URI=      # https://<domen>/admin/facebook/callback
FACEBOOK_GRAPH_VERSION=v26.0
ADMIN_EMAIL=
ADMIN_PASSWORD=
TELEGRAM_BOT_TOKEN=
TELEGRAM_CHAT_ID=
```

### 2.4 Facebook ulanishi
- `FacebookGraph` xizmati: OAuth havolasi, `code` → qisqa token → **uzoq muddatli token** (~60 kun), `me/adaccounts`.
- Token **shifrlangan** (`encrypted` cast) saqlanadi. `state` sessiyada tekshiriladi.
- Rate limit xatolari (`4, 17, 32, 613, 80000...`) uchun avtomatik qayta urinish; tarmoq vaqti tugasa ham qayta urinadi.

### 2.5 Ma'lumot yuklash (sync)
Eng muhim qism. `php artisan facebook:sync` quyidagilarni qiladi:

1. Faol reklamalarni oladi (`effective_status = ACTIVE`).
2. Kunlik statistikani **7 kunlik bo'laklarga bo'lib** oladi, har bo'lakni saqlab, xotirani bo'shatadi. Filtr: `impressions > 0`.
3. Reklamalarning ma'lumotini (kampaniya maqsadi, adset optimization_goal, kreativ, rasm) **Graph batch API** bilan oladi (50 tadan).
4. Har bir reklama uchun **natija turini** aniqlaydi (3-bo'limga qarang).
5. Hisobotlar uchun akkaunt darajasidagi oynalar (3/7/14/30 kun): qamrov va chastota.
6. Meta sifat reytinglari (quality / engagement / conversion).

Qo'shimcha buyruqlar:
- `facebook:discover` — faol akkauntlarni aniqlaydi (30 kunda sarf > 0 yoki faol reklama bor).
- `facebook:thumbs` — rasmlarni 480px da yangilaydi.
- `analytics:reports` — har akkaunt uchun xato/tavsiya hisobotini saqlaydi.
- `analytics:backfill-results` — eski qatorlar uchun natija turini to'ldiradi.
- `facebook:sync --daily-only` — faqat kunlik statistika (tez).

---

## 3. Natija turi (eng muhim qoida)

Hamma reklamani "lid" deb baholash **xato**. Har bir reklama o'z maqsadi bo'yicha baholanadi:

| Tur | Kampaniya maqsadi | Natija | Norma |
|---|---|---|---|
| Lid | LEAD_GENERATION, OUTCOME_LEADS, OFFSITE_CONVERSIONS | lid soni | akkauntning maqsadli CPL i, yo'q bo'lsa o'rtacha |
| Xabar (direct) | CONVERSATIONS, MESSAGES | boshlangan suhbatlar (`onsite_conversion.messaging_conversation_started_7d`) | ≤ $0.30 yaxshi, > $0.45 qimmat |
| Trafik | LINK_CLICKS, OUTCOME_TRAFFIC, PROFILE_VISIT | link kliklar | ≤ $0.04 yaxshi, > $0.06 qimmat |
| Landing view | LANDING_PAGE_VIEWS | landing view | o'rtacha |
| Boshqa | REACH, IMPRESSIONS, engagement (faollik) | baholanmaydi | — |

- **Trafik** reklamalarda ko'rishlar, hook, hold, chastota hisobga olinmaydi: faqat klik narxi.
- Maqsad noma'lum bo'lsa, tur faktdan aniqlanadi (lid bor → lid; xabar bor → xabar; aks holda klik).
- Akkaunt darajasida har bir tur **alohida** yig'iladi, eng ko'p sarflangani sarlavhada ko'rinadi (aralash "CPL" ma'nosiz).

---

## 4. Metrikalar va formulalar

| Metrika | Formula |
|---|---|
| CPM | sarf / ko'rsatuv × 1000 |
| CTR (link) | link klik / ko'rsatuv |
| CPC | sarf / link klik |
| Landing / klik | landing view / link klik |
| Lid rate | lid / landing view |
| Klik → lid | lid / link klik |
| **Hook rate** | 3 soniyalik ko'rish / ko'rsatuv |
| **Hold rate** | ThruPlay / 3 soniyalik ko'rish |
| ThruPlay narxi, o'rtacha ko'rish vaqti | |
| Ushlab turish | 25 / 50 / 75 / 95 / 100% |
| Chastota, qamrov | Meta dan (qamrov kunlar bo'yicha qo'shilmaydi) |

**Pol qiymatlari** (`config/analytics.php`):

| Metrika | Yaxshi | Yomon |
|---|---|---|
| Hook | ≥ 30% | < 20% |
| Hold | ≥ 25% | < 12% |
| CTR | ≥ 1.5% | < 0.8% |
| Landing / klik | ≥ 80% | < 70% |
| Lid rate | ≥ 12% | < 8% |
| Chastota | ≤ 2.5 | > 3.5 |

Faqat 2 ta norma (klik $0.04, xabar $0.30) egasidan olingan, qolganlari umumiy ko'rsatkichlar: o'z nishangizga moslang.

---

## 5. Tahlil mantig'i

### 5.1 Ogohlantirishlar (`AdAnalyzer`)
Oxirgi **3 kun** vs oldingi **7 kun**:
- narx ≥ +30% ogohlantirish, ≥ +60% jiddiy, ≥ +100% "to'xtating";
- narx ≥ −20% (kamida 3 natija) → "yaxshi, byudjetni 15–20% oshiring";
- natija yo'q va sarf ≥ $8 → qizil;
- trafik/xabar: faqat norma buzilganda;
- chastota ≥ 2.5 (trafikdan tashqari).

### 5.2 Sabab (bitta qator)
Eng kuchlisi tanlanadi: CTR tushishi, CPM oshishi, past hook, past hold, klik→lid tushishi (faqat lid), yuqori chastota. Akkaunt darajasida narx formula bilan bo'linadi:

`CPL = CPM / (1000 × CTR × klik→lid)`

va har bir omilning hissasi % da ko'rsatiladi.

### 5.3 Tavsiyalar
Qizil: nol natijali reklamani o'chirish, qimmat reklamani yangilash, past hook/hold, yomon lending. Yashil: arzon joylashuv/soat/auditoriya, yaxshi reklamaga byudjet. Razbivka segmenti: qimmat ≥ 1.3× mediana va sarfning ≥ 5%; arzon ≤ 0.85× mediana.

### 5.4 Razbivka
Yosh/jins, soat, joylashuv, qurilma, hudud — Facebook'dan **akkaunt darajasida jonli** olinadi (`LiveBreakdown`, kesh 10–60 daqiqa), shuning uchun istalgan sana oralig'i ishlaydi. Hafta kunlari bazadagi kunlik qatorlardan hisoblanadi.

---

## 6. Ma'lumotlar bazasi (asosiy jadvallar)

- `facebook_connections` — token (shifrlangan), muddati
- `facebook_ad_accounts` — `is_tracked`, `pinned`, `target_cpl`, `spend_30d`, `active_ads`
- `ads` — nom, holat, kampaniya, `objective`, `optimization_goal`, **`result_type`**, rasm, chastota, reytinglar
- `ad_insights` — kunlik qator: sarf, ko'rsatuv, klik, `leads`, `messages`, **`results`**, landing view, video ko'rishlar, ThruPlay, reaksiyalar...
- `ad_alerts` — kunlik ogohlantirishlar (sabab, tavsiya, metrikalar)
- `account_windows` — akkaunt qamrovi/chastotasi (3/7/14/30 kun)
- `account_reports` — tayyor xato/tavsiya hisoboti (sahifa tez ochilishi uchun)

---

## 7. Sahifalar (panel)

Chap menyu: **Akkauntlar · Xatolar va tavsiyalar · Ogohlantirishlar · Razbivka tahlili · Sozlamalar**.

- **Akkauntlar:** ixcham ro'yxat (Sarf · Natija · Narxi · Holat) + tepada natija turlari bo'yicha bloklar. Faqat faol akkauntlar; qolganlari "Boshqa akkauntlar" ostida.
- **Akkaunt sahifasi:** chapda bo'limlar (Umumiy, Holatlar, Sabablar, Tavsiyalar, Kampaniyalar, Reklamalar, Video, Joylashuv, Yosh va jins, Soatlar, Hafta kunlari, Qurilma, Hudud).
- **Ogohlantirishlar / Tavsiyalar:** rasmli kichik kartalar (rasm, narx oldin→hozir, kichik grafik, sabab, tavsiya).
- **Sana tanlash:** Bugun, Kecha, 3/7/14/30 kun, yoki istalgan oraliq.

Dizayn qoidasi: har sahifada faqat asosiy narsa, qolgan ma'lumot bosganda ochiladi. 13 ustunli jadval o'rniga 4–5 ustun.

---

## 8. Telegram bot

1. Telegramda **@BotFather** → `/newbot` → token → `.env` ga `TELEGRAM_BOT_TOKEN`.
2. Botga `/start` yozing, so'ng `php artisan telegram:setup` — chat ID ni topib `.env` ga yozadi va sinov xabarini yuboradi.
3. `php artisan telegram:listen` — botni tinglaydi (long polling).

Imkoniyatlar:
- **Har bir akkaunt alohida xabar**, tepasida `#akkaunt_nomi` hashtag.
- `#hashtag` yoki akkaunt nomini yozsangiz, shu akkaunt holati keladi.
- Doimiy tugmalar: Akkauntlarim, Reklamalar, Xarajatlar, Ogohlantirishlar, Kunlik hisobot.
- Akkaunt ostida tugmalar: Reklamalar, Xarajat, Joylashuv, Yosh/jins, Soatlar, Hudud, Bugun/Kecha/7/30 kun, Ads Manager.
- Faqat egasining chat ID siga javob beradi.

---

## 9. Jadval (scheduler)

`php artisan schedule:work` ishlab turishi kerak (yoki serverda cron):

| Vaqt | Buyruq |
|---|---|
| har 3 soat | `facebook:discover`, `facebook:sync --days=7`, `facebook:thumbs`, `analytics:reports` |
| har kuni 06:30 | `facebook:sync --days=30` |
| har kuni 09:00 | `analytics:digest` (Telegramga kunlik hisobot) |

---

## 10. Tajribadan kelib chiqqan xatolar (qaytarmang)

1. **Hammasini lid deb hisoblash** — xabar va trafik reklamalar "o'chiring" deb xato belgilanadi. Avval natija turini aniqlang.
2. **`ids=` parametri** v26 da eskirgan → Graph **batch API** ishlating.
3. **30 kunlik kunma-kun so'rov bir martada** vaqt tugatadi → 7 kunlik bo'laklar + `impressions > 0` filtri.
4. **Katta akkauntlarda xotira (128 MB) tugaydi** → bo'lak-bo'lak saqlang, sync buyrug'ida `memory_limit` ni oshiring.
5. **Qamrov (reach) kunlar bo'yicha qo'shilmaydi** → akkaunt darajasida alohida oynalar oling.
6. **Rasm juda kichik (64px)** → `creative.thumbnail_width(480).thumbnail_height(480){thumbnail_url}`.
7. **Blade:** so'zga yopishgan direktiva (`matn@if(...)`) ishlamaydi. `@if` oldida bo'shliq qo'ying.
8. **Tailwind:** yangi klass qo'shgandan keyin `npm run build` qiling.
9. **`http://localhost` OAuth** ni Meta rad etadi → HTTPS (tunnel yoki domen).
10. **Tunnel manzili o'zgaradi** → har o'zgarganda Meta dagi *Valid OAuth Redirect URIs* va *App Domains* ni yangilang.
11. **Telegram `getUpdates`** faqat botga xabar yozilgandan keyin chat ID beradi.
12. **Faol bo'lmagan akkauntlarni** yuklab vaqt yo'qotmang: avval `facebook:discover`.

---

## 11. Claude Code'ga topshiriq namunasi

> Laravel loyihada Facebook Marketing API (ads_read) orqali reklama akkauntlarini ulaydigan tahlil paneli qur. Har bir reklamani kampaniya maqsadiga qarab alohida bahola: lid (maqsadli CPL), xabar (≤ $0.30), trafik (klik ≤ $0.04). Qimmatlashgan reklamalarni oxirgi 3 kunni oldingi 7 kun bilan solishtirib top, sababini (CTR, CPM, hook, hold, klik→lid, chastota) va bitta qisqa tavsiyani rasmli kartada ko'rsat. Yosh/jins, soat, joylashuv razbivkasini istalgan sana uchun ko'rsat. Har akkaunt uchun alohida Telegram xabar va tugmalar qo'sh. Shu faylni to'liq yo'l-yo'riq sifatida ishlat.
