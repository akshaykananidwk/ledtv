<?php
/**
 * Template library — notices (Wi-Fi, breakfast, checkout, no smoking, pool, menu board, offer, welcome). Some are
 * hospitality samples (checkout, pool, room service); customers edit the text anyway.
 * Definition format: see core/Templates.php. Not reachable from the web (templates/.htaccess).
 */
declare(strict_types=1);

defined('HC_ROOT') || exit;

$title = ['key' => 'title', 'type' => 'text', 'label' => 'Heading', 'required' => true];
$subtitle = ['key' => 'subtitle', 'type' => 'text', 'label' => 'Sub-heading'];
$message = ['key' => 'message', 'type' => 'textarea', 'label' => 'Message'];
$note = ['key' => 'note', 'type' => 'textarea', 'label' => 'Small print / note'];
$footer = ['key' => 'footer', 'type' => 'text', 'label' => 'Footer text (right)'];
$colors = [['key' => 'bg_color', 'type' => 'color', 'label' => 'Background colour'], ['key' => 'accent_color', 'type' => 'color', 'label' => 'Highlight colour']];
$rows = static fn (string $help) => ['key' => 'rows', 'type' => 'list', 'label' => 'Rows', 'help' => $help];

return [
    [
        'id' => 'welcome', 'category' => 'notice', 'variant' => 'notice', 'icon' => '🙏',
        'name' => ['en' => 'Welcome', 'gu' => 'સ્વાગત', 'hi' => 'स्वागत'],
        'fields' => [$title, $subtitle, $message, $rows('Service | How to reach'), $footer, ...$colors],
        'colors' => ['bg_color' => '#4A148C', 'accent_color' => '#FFC107'],
        'defaults' => [
            'en' => ['title' => 'Welcome', 'subtitle' => 'We are delighted to host you', 'message' => 'Have a pleasant and comfortable stay. We are here for anything you need.',
                'rows' => ['Reception | Dial 9', 'Room service | Dial 7', 'Wi-Fi | Ask reception for the password']],
            'gu' => ['title' => 'આપનું હાર્દિક સ્વાગત છે', 'subtitle' => 'આપની સેવા કરવી અમારું સૌભાગ્ય છે', 'message' => 'આપનું રોકાણ આનંદદાયક અને આરામદાયક રહે. કોઈપણ જરૂરિયાત માટે અમે હાજર છીએ.',
                'rows' => ['રિસેપ્શન | 9 ડાયલ કરો', 'રૂમ સર્વિસ | 7 ડાયલ કરો', 'Wi-Fi | પાસવર્ડ માટે રિસેપ્શનને પૂછો']],
            'hi' => ['title' => 'आपका हार्दिक स्वागत है', 'subtitle' => 'आपकी सेवा करना हमारा सौभाग्य है', 'message' => 'आपका प्रवास सुखद और आरामदायक हो। किसी भी ज़रूरत के लिए हम हाज़िर हैं।',
                'rows' => ['रिसेप्शन | 9 डायल करें', 'रूम सर्विस | 7 डायल करें', 'Wi-Fi | पासवर्ड के लिए रिसेप्शन से पूछें']],
        ],
    ],
    [
        'id' => 'wifi', 'category' => 'notice', 'variant' => 'wifi', 'icon' => '📶',
        'name' => ['en' => 'Wi-Fi details', 'gu' => 'Wi-Fi માહિતી', 'hi' => 'Wi-Fi जानकारी'],
        'fields' => [
            $title, $subtitle,
            ['key' => 'ssid_label', 'type' => 'text', 'label' => 'Label for the network name'],
            ['key' => 'ssid', 'type' => 'text', 'label' => 'Wi-Fi network name (SSID)', 'required' => true],
            ['key' => 'password_label', 'type' => 'text', 'label' => 'Label for the password'],
            ['key' => 'password', 'type' => 'text', 'label' => 'Wi-Fi password'],
            ['key' => 'qr_caption', 'type' => 'text', 'label' => 'Text under the QR code'],
            $message, $footer, ...$colors,
        ],
        'colors' => ['bg_color' => '#0D47A1', 'accent_color' => '#4FC3F7'],
        'defaults' => [
            'en' => ['title' => 'Free Wi-Fi', 'subtitle' => 'Stay connected during your stay', 'ssid_label' => 'Network', 'ssid' => 'Hotel-Guest', 'password_label' => 'Password',
                'password' => 'welcome123', 'qr_caption' => 'Scan with your phone camera to connect', 'message' => 'Need help connecting? Call reception.'],
            'gu' => ['title' => 'ફ્રી Wi-Fi', 'subtitle' => 'રોકાણ દરમિયાન કનેક્ટેડ રહો', 'ssid_label' => 'નેટવર્ક', 'ssid' => 'Hotel-Guest', 'password_label' => 'પાસવર્ડ',
                'password' => 'welcome123', 'qr_caption' => 'કનેક્ટ થવા ફોનના કૅમેરાથી સ્કૅન કરો', 'message' => 'કનેક્ટ થવામાં મદદ જોઈએ? રિસેપ્શનને ફોન કરો.'],
            'hi' => ['title' => 'फ्री Wi-Fi', 'subtitle' => 'प्रवास के दौरान जुड़े रहें', 'ssid_label' => 'नेटवर्क', 'ssid' => 'Hotel-Guest', 'password_label' => 'पासवर्ड',
                'password' => 'welcome123', 'qr_caption' => 'कनेक्ट होने के लिए फ़ोन के कैमरे से स्कैन करें', 'message' => 'कनेक्ट करने में मदद चाहिए? रिसेप्शन को कॉल करें।'],
        ],
    ],
    [
        'id' => 'breakfast', 'category' => 'notice', 'variant' => 'timetable', 'icon' => '🍳',
        'name' => ['en' => 'Breakfast & meal timings', 'gu' => 'નાસ્તો અને ભોજન સમય', 'hi' => 'नाश्ता और भोजन समय'],
        'fields' => [$title, $subtitle, $rows('Meal | Time | Place'), $message, $footer, ...$colors],
        'colors' => ['bg_color' => '#BF360C', 'accent_color' => '#FFE082'],
        'defaults' => [
            'en' => ['title' => 'Meal Timings', 'subtitle' => 'Pure vegetarian restaurant', 'rows' => ['Breakfast | 7:30 – 10:30 AM | Restaurant', 'Lunch | 12:30 – 3:00 PM | Restaurant', 'Dinner | 7:30 – 10:30 PM | Restaurant'],
                'message' => 'Breakfast is complimentary for in-house guests.'],
            'gu' => ['title' => 'ભોજનનો સમય', 'subtitle' => 'શુદ્ધ શાકાહારી રેસ્ટોરન્ટ', 'rows' => ['નાસ્તો | સવારે 7:30 – 10:30 | રેસ્ટોરન્ટ', 'બપોરનું ભોજન | 12:30 – 3:00 | રેસ્ટોરન્ટ', 'રાત્રિ ભોજન | 7:30 – 10:30 | રેસ્ટોરન્ટ'],
                'message' => 'હોટલમાં રોકાયેલા મહેમાનો માટે નાસ્તો નિઃશુલ્ક છે.'],
            'hi' => ['title' => 'भोजन का समय', 'subtitle' => 'शुद्ध शाकाहारी रेस्टोरेंट', 'rows' => ['नाश्ता | सुबह 7:30 – 10:30 | रेस्टोरेंट', 'दोपहर का भोजन | 12:30 – 3:00 | रेस्टोरेंट', 'रात का भोजन | 7:30 – 10:30 | रेस्टोरेंट'],
                'message' => 'होटल में ठहरे मेहमानों के लिए नाश्ता निःशुल्क है।'],
        ],
    ],
    [
        'id' => 'checkout', 'category' => 'notice', 'variant' => 'big', 'icon' => '🧳',
        'name' => ['en' => 'Checkout time', 'gu' => 'ચેકઆઉટ સમય', 'hi' => 'चेकआउट समय'],
        'fields' => [$title, ['key' => 'big_label', 'type' => 'text', 'label' => 'Label above the time'], ['key' => 'big', 'type' => 'time', 'label' => 'Checkout time', 'required' => true],
            $message, $note, $footer, ...$colors],
        'colors' => ['bg_color' => '#263238', 'accent_color' => '#FFB300'],
        'defaults' => [
            'en' => ['title' => 'Checkout', 'big_label' => 'Checkout time', 'big' => '10:00 AM', 'message' => 'Need a late checkout? Please call reception — subject to availability.', 'note' => 'Kindly return your room key at the reception.'],
            'gu' => ['title' => 'ચેકઆઉટ', 'big_label' => 'ચેકઆઉટ સમય', 'big' => 'સવારે 10:00', 'message' => 'મોડું ચેકઆઉટ જોઈએ? કૃપા કરીને રિસેપ્શનને ફોન કરો — ઉપલબ્ધતા મુજબ.', 'note' => 'કૃપા કરીને રૂમની ચાવી રિસેપ્શન પર જમા કરાવો.'],
            'hi' => ['title' => 'चेकआउट', 'big_label' => 'चेकआउट समय', 'big' => 'सुबह 10:00', 'message' => 'देर से चेकआउट चाहिए? कृपया रिसेप्शन को कॉल करें — उपलब्धता के अनुसार।', 'note' => 'कृपया कमरे की चाबी रिसेप्शन पर जमा करें।'],
        ],
    ],
    [
        'id' => 'no_smoking', 'category' => 'notice', 'variant' => 'sign', 'icon' => '🚭',
        'name' => ['en' => 'No smoking', 'gu' => 'ધૂમ્રપાન નિષેધ', 'hi' => 'धूम्रपान निषेध'],
        'fields' => [$title, $message, ['key' => 'big', 'type' => 'text', 'label' => 'Fine / extra line'], $footer, ...$colors],
        'colors' => ['bg_color' => '#B71C1C', 'accent_color' => '#FFFFFF'],
        'defaults' => [
            'en' => ['title' => 'No Smoking', 'message' => 'This is a non-smoking area. Smoking is not allowed anywhere inside the premises.', 'big' => 'A cleaning fee of ₹ 2,000 applies.'],
            'gu' => ['title' => 'ધૂમ્રપાન નિષેધ', 'message' => 'આ નોન-સ્મોકિંગ વિસ્તાર છે. પરિસરની અંદર ક્યાંય ધૂમ્રપાનની મંજૂરી નથી.', 'big' => '₹ 2,000 સફાઈ ચાર્જ લાગુ પડશે.'],
            'hi' => ['title' => 'धूम्रपान निषेध', 'message' => 'यह नॉन-स्मोकिंग क्षेत्र है। परिसर के अंदर कहीं भी धूम्रपान की अनुमति नहीं है।', 'big' => '₹ 2,000 सफ़ाई शुल्क लागू होगा।'],
        ],
    ],
    [
        'id' => 'pool', 'category' => 'notice', 'variant' => 'timetable', 'icon' => '🏊',
        'name' => ['en' => 'Swimming pool timings', 'gu' => 'સ્વિમિંગ પૂલ સમય', 'hi' => 'स्विमिंग पूल समय'],
        'fields' => [$title, $subtitle, $rows('Days | Time | Note'), $message, $footer, ...$colors],
        'colors' => ['bg_color' => '#006064', 'accent_color' => '#80DEEA'],
        'defaults' => [
            'en' => ['title' => 'Swimming Pool', 'subtitle' => 'Open for in-house guests', 'rows' => ['Every day | 7:00 – 11:00 AM | ', 'Every day | 4:00 – 8:00 PM | ', 'Monday | 11:00 AM – 4:00 PM | Cleaning'],
                'message' => 'Swimming costume required · Children under 12 with an adult · No glass near the pool'],
            'gu' => ['title' => 'સ્વિમિંગ પૂલ', 'subtitle' => 'હોટલના મહેમાનો માટે', 'rows' => ['દરરોજ | સવારે 7:00 – 11:00 | ', 'દરરોજ | સાંજે 4:00 – 8:00 | ', 'સોમવાર | 11:00 – 4:00 | સફાઈ'],
                'message' => 'સ્વિમિંગ કોસ્ચ્યુમ ફરજિયાત · 12 વર્ષથી નાના બાળકો વડીલ સાથે · પૂલ પાસે કાચના વાસણ નહીં'],
            'hi' => ['title' => 'स्विमिंग पूल', 'subtitle' => 'होटल के मेहमानों के लिए', 'rows' => ['रोज़ | सुबह 7:00 – 11:00 | ', 'रोज़ | शाम 4:00 – 8:00 | ', 'सोमवार | 11:00 – 4:00 | सफ़ाई'],
                'message' => 'स्विमिंग कॉस्ट्यूम अनिवार्य · 12 वर्ष से छोटे बच्चे बड़ों के साथ · पूल के पास कांच के बर्तन नहीं'],
        ],
    ],
    [
        'id' => 'menu_board', 'category' => 'notice', 'variant' => 'board', 'icon' => '🍽️',
        'name' => ['en' => 'Restaurant menu board', 'gu' => 'રેસ્ટોરન્ટ મેનુ બોર્ડ', 'hi' => 'रेस्टोरेंट मेनू बोर्ड'],
        'fields' => [$title, $subtitle, $rows('Dish | Price | Description (optional)'), $message, $footer, ...$colors],
        'colors' => ['bg_color' => '#1B3A2B', 'accent_color' => '#FFD54F'],
        'defaults' => [
            'en' => ['title' => "Today's Specials", 'subtitle' => 'Pure vegetarian · Jain food on request', 'rows' => ['Gujarati Thali | ₹ 280 | Unlimited', 'Kathiyawadi Thali | ₹ 320 | ', 'Masala Dosa | ₹ 150 | ', 'Paneer Butter Masala | ₹ 240 | ', 'Veg Biryani | ₹ 220 | ', 'Masala Chai | ₹ 40 | '],
                'message' => 'Dial 7 for room service.'],
            'gu' => ['title' => 'આજની ખાસ વાનગીઓ', 'subtitle' => 'શુદ્ધ શાકાહારી · વિનંતી પર જૈન ભોજન', 'rows' => ['ગુજરાતી થાળી | ₹ 280 | અનલિમિટેડ', 'કાઠિયાવાડી થાળી | ₹ 320 | ', 'મસાલા ઢોસા | ₹ 150 | ', 'પનીર બટર મસાલા | ₹ 240 | ', 'વેજ બિરયાની | ₹ 220 | ', 'મસાલા ચા | ₹ 40 | '],
                'message' => 'રૂમ સર્વિસ માટે 7 ડાયલ કરો.'],
            'hi' => ['title' => 'आज के ख़ास व्यंजन', 'subtitle' => 'शुद्ध शाकाहारी · अनुरोध पर जैन भोजन', 'rows' => ['गुजराती थाली | ₹ 280 | अनलिमिटेड', 'काठियावाड़ी थाली | ₹ 320 | ', 'मसाला डोसा | ₹ 150 | ', 'पनीर बटर मसाला | ₹ 240 | ', 'वेज बिरयानी | ₹ 220 | ', 'मसाला चाय | ₹ 40 | '],
                'message' => 'रूम सर्विस के लिए 7 डायल करें।'],
        ],
    ],
    [
        'id' => 'offer', 'category' => 'notice', 'variant' => 'offer', 'icon' => '🏷️',
        'name' => ['en' => 'Offer / discount', 'gu' => 'ઓફર / ડિસ્કાઉન્ટ', 'hi' => 'ऑफ़र / छूट'],
        'fields' => [$title, ['key' => 'big', 'type' => 'text', 'label' => 'Offer (big text)', 'required' => true], $message,
            ['key' => 'code', 'type' => 'text', 'label' => 'Coupon code'], ['key' => 'valid', 'type' => 'text', 'label' => 'Validity'], $footer, ...$colors],
        'colors' => ['bg_color' => '#311B92', 'accent_color' => '#FFEB3B'],
        'defaults' => [
            'en' => ['title' => 'Special Offer', 'big' => '20% OFF', 'message' => 'on food & beverages at our restaurant for in-house guests', 'code' => 'STAY20', 'valid' => 'Valid till 31 March'],
            'gu' => ['title' => 'ખાસ ઓફર', 'big' => '20% છૂટ', 'message' => 'હોટલના મહેમાનો માટે અમારા રેસ્ટોરન્ટમાં ભોજન અને પીણાં પર', 'code' => 'STAY20', 'valid' => '31 માર્ચ સુધી માન્ય'],
            'hi' => ['title' => 'ख़ास ऑफ़र', 'big' => '20% छूट', 'message' => 'होटल के मेहमानों के लिए हमारे रेस्टोरेंट में खाने-पीने पर', 'code' => 'STAY20', 'valid' => '31 मार्च तक मान्य'],
        ],
    ],
];
