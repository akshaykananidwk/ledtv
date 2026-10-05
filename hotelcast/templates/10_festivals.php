<?php
/**
 * Template library — festival greetings (CSS / SVG / emoji designs, no external images).
 * Definition format: see core/Templates.php. Not reachable from the web (templates/.htaccess).
 */
declare(strict_types=1);

defined('HC_ROOT') || exit;

$fields = [
    ['key' => 'title', 'type' => 'text', 'label' => 'Greeting', 'required' => true],
    ['key' => 'subtitle', 'type' => 'text', 'label' => 'Sub-heading'],
    ['key' => 'message', 'type' => 'textarea', 'label' => 'Message'],
    ['key' => 'signature', 'type' => 'text', 'label' => 'Signature (empty = hotel name)'],
    ['key' => 'bg_color', 'type' => 'color', 'label' => 'Background colour'],
    ['key' => 'accent_color', 'type' => 'color', 'label' => 'Highlight colour'],
];

$fest = static fn (string $id, array $name, string $motif, array $emoji, string $bg, string $ac, array $en, array $gu, array $hi) => [
    'id' => $id, 'category' => 'festival', 'layout' => 'festival', 'name' => $name, 'icon' => $emoji[0], 'motif' => $motif, 'emoji' => $emoji,
    'fields' => $fields, 'colors' => ['bg_color' => $bg, 'accent_color' => $ac],
    'defaults' => [
        'en' => ['title' => $en[0], 'subtitle' => $en[1], 'message' => $en[2]],
        'gu' => ['title' => $gu[0], 'subtitle' => $gu[1], 'message' => $gu[2]],
        'hi' => ['title' => $hi[0], 'subtitle' => $hi[1], 'message' => $hi[2]],
    ],
];

return [
    $fest('diwali', ['en' => 'Diwali', 'gu' => 'દિવાળી', 'hi' => 'दीपावली'], 'diya', ['🪔', '✨', '🪔'], '#2A0845', '#FFC107',
        ['Happy Diwali', 'May the festival of lights brighten your life', 'Wishing you and your family joy, prosperity and good health.'],
        ['દિવાળીની હાર્દિક શુભકામનાઓ', 'પ્રકાશનું આ પર્વ આપના જીવનને ઉજ્જવળ બનાવે', 'આપને અને આપના પરિવારને સુખ, સમૃદ્ધિ અને આરોગ્યની શુભેચ્છા.'],
        ['दीपावली की हार्दिक शुभकामनाएँ', 'रोशनी का यह पर्व आपके जीवन को रोशन करे', 'आपको और आपके परिवार को सुख, समृद्धि और अच्छे स्वास्थ्य की शुभकामनाएँ।']),

    $fest('new_year', ['en' => 'New Year (Bestu Varas)', 'gu' => 'નૂતન વર્ષ (બેસતું વર્ષ)', 'hi' => 'नव वर्ष'], 'fireworks', ['🎆', '🎉', '🎇'], '#0B1A3A', '#FFD54F',
        ['Happy New Year', 'Saal Mubarak!', 'May the new year bring new hopes, new joys and new success.'],
        ['નૂતન વર્ષાભિનંદન', 'સાલ મુબારક!', 'નવું વર્ષ આપના જીવનમાં નવી આશા, નવો આનંદ અને નવી સફળતા લાવે.'],
        ['नव वर्ष की शुभकामनाएँ', 'साल मुबारक!', 'नया साल आपके जीवन में नई उम्मीदें, नई खुशियाँ और नई सफलता लाए।']),

    $fest('janmashtami', ['en' => 'Janmashtami', 'gu' => 'જન્માષ્ટમી', 'hi' => 'जन्माष्टमी'], 'peacock', ['🦚', '🪈', '🧈'], '#0D2B5E', '#FFD600',
        ['Happy Janmashtami', 'Jai Shri Krishna', 'May Lord Krishna fill your life with love, joy and peace. Nand ghar anand bhayo!'],
        ['જન્માષ્ટમીની શુભકામનાઓ', 'જય શ્રી કૃષ્ણ', 'શ્રી કૃષ્ણ આપના જીવનને પ્રેમ, આનંદ અને શાંતિથી ભરી દે. નંદ ઘેર આનંદ ભયો!'],
        ['जन्माष्टमी की शुभकामनाएँ', 'जय श्री कृष्ण', 'श्री कृष्ण आपके जीवन को प्रेम, आनंद और शांति से भर दें। नंद घर आनंद भयो!']),

    $fest('navratri', ['en' => 'Navratri', 'gu' => 'નવરાત્રી', 'hi' => 'नवरात्रि'], 'dandiya', ['🪘', '💃', '🌺'], '#5B0E2D', '#FFB300',
        ['Happy Navratri', 'Nine nights of garba, devotion and joy', 'May Maa Amba bless you with strength, happiness and prosperity.'],
        ['નવરાત્રીની શુભકામનાઓ', 'ગરબા, ભક્તિ અને આનંદની નવ રાત', 'મા અંબા આપને શક્તિ, સુખ અને સમૃદ્ધિના આશીર્વાદ આપે.'],
        ['नवरात्रि की शुभकामनाएँ', 'गरबा, भक्ति और आनंद की नौ रातें', 'माँ अम्बा आपको शक्ति, सुख और समृद्धि का आशीर्वाद दें।']),

    $fest('dussehra', ['en' => 'Dussehra', 'gu' => 'દશેરા', 'hi' => 'दशहरा'], 'bow', ['🏹', '🔥', '🌼'], '#4E1A00', '#FF9800',
        ['Happy Dussehra', 'Victory of good over evil', 'May this Vijayadashami bring success and happiness to you and your family.'],
        ['દશેરાની શુભકામનાઓ', 'અસત્ય પર સત્યનો વિજય', 'આ વિજયાદશમી આપને અને આપના પરિવારને સફળતા અને ખુશી આપે.'],
        ['दशहरा की शुभकामनाएँ', 'बुराई पर अच्छाई की जीत', 'यह विजयादशमी आपके और आपके परिवार के लिए सफलता और खुशियाँ लाए।']),

    $fest('holi', ['en' => 'Holi', 'gu' => 'હોળી - ધૂળેટી', 'hi' => 'होली'], 'splash', ['🎨', '🌈', '💦'], '#3B0A57', '#FFEB3B',
        ['Happy Holi', 'Let the colours of joy fill your life', 'Wishing you a colourful, safe and joyful Holi.'],
        ['હોળી - ધૂળેટીની શુભકામનાઓ', 'આનંદના રંગોથી જીવન રંગાઈ જાય', 'આપને રંગબેરંગી, સુરક્ષિત અને આનંદમય હોળીની શુભેચ્છા.'],
        ['होली की हार्दिक शुभकामनाएँ', 'खुशियों के रंग आपके जीवन में भर जाएँ', 'आपको रंगीन, सुरक्षित और आनंदमय होली की शुभकामनाएँ।']),

    $fest('uttarayan', ['en' => 'Uttarayan (Makar Sankranti)', 'gu' => 'ઉત્તરાયણ (મકરસંક્રાંતિ)', 'hi' => 'मकर संक्रांति'], 'kites', ['🪁', '☀️', '🪁'], '#0277BD', '#FFEB3B',
        ['Happy Uttarayan', 'Kai po che!', 'May your dreams fly as high as the kites. Enjoy undhiyu, chikki and the open sky!'],
        ['ઉત્તરાયણની શુભકામનાઓ', 'કાઈપો છે!', 'આપના સપના પતંગની જેમ ઊંચે ઊડે. ઊંધિયું, ચિક્કી અને ખુલ્લા આકાશની મજા માણો!'],
        ['मकर संक्रांति की शुभकामनाएँ', 'काई पो छे!', 'आपके सपने पतंग की तरह ऊँचे उड़ें। तिल-गुड़ और खुले आसमान का आनंद लें!']),

    $fest('rath_yatra', ['en' => 'Rath Yatra', 'gu' => 'રથયાત્રા', 'hi' => 'रथ यात्रा'], 'chariot', ['🛕', '🚩', '🌸'], '#6D1B1B', '#FFCA28',
        ['Happy Rath Yatra', 'Jai Jagannath', 'May Lord Jagannath bless you with happiness, health and prosperity.'],
        ['રથયાત્રાની શુભકામનાઓ', 'જય જગન્નાથ', 'ભગવાન જગન્નાથ આપને સુખ, આરોગ્ય અને સમૃદ્ધિના આશીર્વાદ આપે.'],
        ['रथ यात्रा की शुभकामनाएँ', 'जय जगन्नाथ', 'भगवान जगन्नाथ आपको सुख, स्वास्थ्य और समृद्धि का आशीर्वाद दें।']),

    $fest('ganesh_chaturthi', ['en' => 'Ganesh Chaturthi', 'gu' => 'ગણેશ ચતુર્થી', 'hi' => 'गणेश चतुर्थी'], 'mandala', ['🐘', '🌺', '🪔'], '#7A1F00', '#FFC107',
        ['Happy Ganesh Chaturthi', 'Ganpati Bappa Morya!', 'May Lord Ganesha remove all obstacles and bless you with wisdom and success.'],
        ['ગણેશ ચતુર્થીની શુભકામનાઓ', 'ગણપતિ બાપ્પા મોરયા!', 'વિઘ્નહર્તા ગણેશજી આપના બધા વિઘ્નો દૂર કરે અને બુદ્ધિ તથા સફળતા આપે.'],
        ['गणेश चतुर्थी की शुभकामनाएँ', 'गणपति बप्पा मोरया!', 'विघ्नहर्ता गणेश जी आपके सभी विघ्न दूर करें और बुद्धि व सफलता दें।']),

    $fest('raksha_bandhan', ['en' => 'Raksha Bandhan', 'gu' => 'રક્ષાબંધન', 'hi' => 'रक्षाबंधन'], 'rakhi', ['🧵', '💝', '🌸'], '#880E4F', '#FFD54F',
        ['Happy Raksha Bandhan', 'A bond of love and protection', 'Wishing all brothers and sisters a joyful Raksha Bandhan.'],
        ['રક્ષાબંધનની શુભકામનાઓ', 'પ્રેમ અને રક્ષાનું પવિત્ર બંધન', 'સર્વે ભાઈ-બહેનોને રક્ષાબંધનની હાર્દિક શુભેચ્છા.'],
        ['रक्षाबंधन की शुभकामनाएँ', 'प्रेम और रक्षा का पवित्र बंधन', 'सभी भाई-बहनों को रक्षाबंधन की हार्दिक शुभकामनाएँ।']),

    $fest('independence_day', ['en' => 'Independence Day', 'gu' => 'સ્વતંત્રતા દિવસ', 'hi' => 'स्वतंत्रता दिवस'], 'tricolor', ['🇮🇳', '🕊️', '🇮🇳'], '#0A1F44', '#FF9933',
        ['Happy Independence Day', '15 August — Jai Hind', 'Saluting the freedom fighters who gave us a free India.'],
        ['સ્વતંત્રતા દિવસની શુભકામનાઓ', '૧૫ ઓગસ્ટ — જય હિન્દ', 'આઝાદી અપાવનાર સ્વાતંત્ર્ય સેનાનીઓને શત શત નમન.'],
        ['स्वतंत्रता दिवस की शुभकामनाएँ', '15 अगस्त — जय हिन्द', 'हमें आज़ादी दिलाने वाले स्वतंत्रता सेनानियों को शत-शत नमन।']),

    $fest('republic_day', ['en' => 'Republic Day', 'gu' => 'પ્રજાસત્તાક દિવસ', 'hi' => 'गणतंत्र दिवस'], 'tricolor', ['🇮🇳', '🎖️', '🇮🇳'], '#0A2A1E', '#FF9933',
        ['Happy Republic Day', '26 January — Jai Hind', 'Celebrating the spirit of our Constitution, unity and democracy.'],
        ['પ્રજાસત્તાક દિવસની શુભકામનાઓ', '૨૬ જાન્યુઆરી — જય હિન્દ', 'આપણા બંધારણ, એકતા અને લોકશાહીની ભાવનાની ઉજવણી.'],
        ['गणतंत्र दिवस की शुभकामनाएँ', '26 जनवरी — जय हिन्द', 'हमारे संविधान, एकता और लोकतंत्र की भावना का उत्सव।']),
];
