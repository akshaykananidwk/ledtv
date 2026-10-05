<?php
/**
 * Template library — local guide (#7): attractions, Bet Dwarka boat timings, taxi / auto contacts,
 * emergency numbers, map QR. Pick one of these as "Local guide" to show it in the TV guest menu.
 * Definition format: see core/Templates.php. Not reachable from the web (templates/.htaccess).
 */
declare(strict_types=1);

defined('HC_ROOT') || exit;

$title = ['key' => 'title', 'type' => 'text', 'label' => 'Heading', 'required' => true];
$subtitle = ['key' => 'subtitle', 'type' => 'text', 'label' => 'Sub-heading'];
$message = ['key' => 'message', 'type' => 'textarea', 'label' => 'Message'];
$footer = ['key' => 'footer', 'type' => 'text', 'label' => 'Footer text (right)'];
$colors = [['key' => 'bg_color', 'type' => 'color', 'label' => 'Background colour'], ['key' => 'accent_color', 'type' => 'color', 'label' => 'Highlight colour']];
$rows = static fn (string $help) => ['key' => 'rows', 'type' => 'list', 'label' => 'Rows', 'help' => $help];
$columns = ['key' => 'columns', 'type' => 'text', 'label' => 'Column titles (separated by |)'];
$url = ['key' => 'url', 'type' => 'url', 'label' => 'Link for the QR code (optional)'];
$qrCaption = ['key' => 'qr_caption', 'type' => 'text', 'label' => 'Text under the QR code'];

return [
    [
        'id' => 'attractions', 'category' => 'guide', 'variant' => 'list', 'icon' => '🗺️',
        'name' => ['en' => 'Nearby attractions', 'gu' => 'નજીકના જોવાલાયક સ્થળો', 'hi' => 'आसपास के दर्शनीय स्थल'],
        'fields' => [$title, $subtitle, $columns, $rows('Place | Timing | Distance'), $message, $url, $qrCaption, $footer, ...$colors],
        'colors' => ['bg_color' => '#004D40', 'accent_color' => '#FFD54F'],
        'defaults' => [
            'en' => ['title' => 'Explore Dwarka', 'subtitle' => 'Places to visit nearby', 'columns' => 'Place | Timing | Distance',
                'rows' => ['Dwarkadhish Temple | 6:30 AM – 1 PM, 5 – 9:30 PM | 1 km', 'Gomti Ghat | Sunrise – sunset | 1 km', 'Rukmini Devi Temple | 6 AM – 9 PM | 2 km',
                    'Shivrajpur Beach | 8 AM – 6 PM | 12 km', 'Nageshwar Jyotirlinga | 6 AM – 9 PM | 17 km', 'Bet Dwarka (Sudarshan Setu) | 6 AM – 8 PM | 30 km'],
                'message' => 'Timings may change. Reception can book a taxi for you.', 'qr_caption' => 'Scan for the map'],
            'gu' => ['title' => 'દ્વારકા દર્શન', 'subtitle' => 'નજીકના જોવાલાયક સ્થળો', 'columns' => 'સ્થળ | સમય | અંતર',
                'rows' => ['દ્વારકાધીશ મંદિર | 6:30 – 1, 5 – 9:30 | 1 કિમી', 'ગોમતી ઘાટ | સૂર્યોદય – સૂર્યાસ્ત | 1 કિમી', 'રુક્મિણી દેવી મંદિર | 6 – 9 | 2 કિમી',
                    'શિવરાજપુર બીચ | 8 – 6 | 12 કિમી', 'નાગેશ્વર જ્યોતિર્લિંગ | 6 – 9 | 17 કિમી', 'બેટ દ્વારકા (સુદર્શન સેતુ) | 6 – 8 | 30 કિમી'],
                'message' => 'સમય બદલાઈ શકે છે. રિસેપ્શન આપના માટે ટેક્સી બુક કરી શકે છે.', 'qr_caption' => 'નકશા માટે સ્કૅન કરો'],
            'hi' => ['title' => 'द्वारका दर्शन', 'subtitle' => 'आसपास के दर्शनीय स्थल', 'columns' => 'स्थान | समय | दूरी',
                'rows' => ['द्वारकाधीश मंदिर | 6:30 – 1, 5 – 9:30 | 1 किमी', 'गोमती घाट | सूर्योदय – सूर्यास्त | 1 किमी', 'रुक्मिणी देवी मंदिर | 6 – 9 | 2 किमी',
                    'शिवराजपुर बीच | 8 – 6 | 12 किमी', 'नागेश्वर ज्योतिर्लिंग | 6 – 9 | 17 किमी', 'बेट द्वारका (सुदर्शन सेतु) | 6 – 8 | 30 किमी'],
                'message' => 'समय बदल सकता है। रिसेप्शन आपके लिए टैक्सी बुक कर सकता है।', 'qr_caption' => 'नक्शे के लिए स्कैन करें'],
        ],
    ],
    [
        'id' => 'ferry', 'category' => 'guide', 'variant' => 'timetable', 'icon' => '⛴️',
        'name' => ['en' => 'Bet Dwarka boat timings', 'gu' => 'બેટ દ્વારકા બોટ સમય', 'hi' => 'बेट द्वारका नाव समय'],
        'fields' => [$title, $subtitle, $rows('From | Time | Note'), $message, $footer, ...$colors],
        'colors' => ['bg_color' => '#01579B', 'accent_color' => '#FFE082'],
        'defaults' => [
            'en' => ['title' => 'Okha – Bet Dwarka Boats', 'subtitle' => 'Okha jetty is 30 km from Dwarka', 'rows' => ['Okha → Bet Dwarka | 6:00 AM – 7:00 PM | Every 20–30 min', 'Bet Dwarka → Okha | 6:30 AM – 7:30 PM | Last boat 7:30 PM', 'Bet Dwarka temple | 6 AM – 1 PM, 5 – 8 PM | '],
                'message' => 'Boats depend on weather and tide. You can also reach Bet Dwarka by road over Sudarshan Setu.'],
            'gu' => ['title' => 'ઓખા – બેટ દ્વારકા બોટ', 'subtitle' => 'ઓખા જેટી દ્વારકાથી 30 કિમી', 'rows' => ['ઓખા → બેટ દ્વારકા | સવારે 6:00 – સાંજે 7:00 | દર 20–30 મિનિટે', 'બેટ દ્વારકા → ઓખા | 6:30 – 7:30 | છેલ્લી બોટ 7:30', 'બેટ દ્વારકા મંદિર | 6 – 1, 5 – 8 | '],
                'message' => 'બોટ હવામાન અને ભરતી પર આધાર રાખે છે. સુદર્શન સેતુ પરથી રોડ દ્વારા પણ બેટ દ્વારકા જઈ શકાય છે.'],
            'hi' => ['title' => 'ओखा – बेट द्वारका नाव', 'subtitle' => 'ओखा जेट्टी द्वारका से 30 किमी', 'rows' => ['ओखा → बेट द्वारका | सुबह 6:00 – शाम 7:00 | हर 20–30 मिनट', 'बेट द्वारका → ओखा | 6:30 – 7:30 | आख़िरी नाव 7:30', 'बेट द्वारका मंदिर | 6 – 1, 5 – 8 | '],
                'message' => 'नाव मौसम और ज्वार पर निर्भर है। सुदर्शन सेतु से सड़क मार्ग द्वारा भी बेट द्वारका जा सकते हैं।'],
        ],
    ],
    [
        'id' => 'taxi', 'category' => 'guide', 'variant' => 'list', 'icon' => '🚕',
        'name' => ['en' => 'Taxi & auto contacts', 'gu' => 'ટેક્સી અને રિક્ષા સંપર્ક', 'hi' => 'टैक्सी और ऑटो संपर्क'],
        'fields' => [$title, $subtitle, $columns, $rows('Service | Name | Phone'), $message, $footer, ...$colors],
        'colors' => ['bg_color' => '#212121', 'accent_color' => '#FFD600'],
        'defaults' => [
            'en' => ['title' => 'Taxi & Auto', 'subtitle' => 'Trusted drivers recommended by the hotel', 'columns' => 'Service | Name | Phone',
                'rows' => ['Taxi (AC) | Ask reception | Dial 9', 'Auto rickshaw | Hotel stand | Dial 9', 'Dwarka darshan tour | Half day | Dial 9'],
                'message' => 'Agree on the fare before starting. Reception will gladly help.'],
            'gu' => ['title' => 'ટેક્સી અને રિક્ષા', 'subtitle' => 'હોટલ દ્વારા ભલામણ કરેલા વિશ્વાસુ ડ્રાઇવરો', 'columns' => 'સેવા | નામ | ફોન',
                'rows' => ['ટેક્સી (AC) | રિસેપ્શનને પૂછો | 9 ડાયલ કરો', 'રિક્ષા | હોટલ સ્ટેન્ડ | 9 ડાયલ કરો', 'દ્વારકા દર્શન ટૂર | અડધો દિવસ | 9 ડાયલ કરો'],
                'message' => 'મુસાફરી શરૂ કરતા પહેલા ભાડું નક્કી કરો. રિસેપ્શન મદદ કરશે.'],
            'hi' => ['title' => 'टैक्सी और ऑटो', 'subtitle' => 'होटल द्वारा सुझाए गए भरोसेमंद ड्राइवर', 'columns' => 'सेवा | नाम | फ़ोन',
                'rows' => ['टैक्सी (AC) | रिसेप्शन से पूछें | 9 डायल करें', 'ऑटो रिक्शा | होटल स्टैंड | 9 डायल करें', 'द्वारका दर्शन टूर | आधा दिन | 9 डायल करें'],
                'message' => 'यात्रा शुरू करने से पहले किराया तय कर लें। रिसेप्शन मदद करेगा।'],
        ],
    ],
    [
        'id' => 'emergency', 'category' => 'guide', 'variant' => 'tiles', 'icon' => '🚨',
        'name' => ['en' => 'Emergency numbers', 'gu' => 'ઇમરજન્સી નંબર', 'hi' => 'आपातकालीन नंबर'],
        'fields' => [$title, $subtitle, $rows('Service | Number | Note'), $message, $footer, ...$colors],
        'colors' => ['bg_color' => '#1A1A2E', 'accent_color' => '#FF5252'],
        'defaults' => [
            'en' => ['title' => 'Emergency Numbers', 'subtitle' => 'Free calls from any phone', 'rows' => ['Police | 100 / 112 | ', 'Ambulance | 108 | ', 'Fire | 101 | ', 'Women helpline | 181 | ', 'Nearest hospital | Dial 9 | Reception will connect you', 'Tourist helpline | 1363 | '],
                'message' => 'In any emergency inside the hotel, call reception immediately.'],
            'gu' => ['title' => 'ઇમરજન્સી નંબર', 'subtitle' => 'કોઈપણ ફોનથી મફત કૉલ', 'rows' => ['પોલીસ | 100 / 112 | ', 'એમ્બ્યુલન્સ | 108 | ', 'ફાયર | 101 | ', 'મહિલા હેલ્પલાઇન | 181 | ', 'નજીકની હોસ્પિટલ | 9 ડાયલ કરો | રિસેપ્શન જોડી આપશે', 'પ્રવાસી હેલ્પલાઇન | 1363 | '],
                'message' => 'હોટલમાં કોઈપણ કટોકટીમાં તરત રિસેપ્શનને ફોન કરો.'],
            'hi' => ['title' => 'आपातकालीन नंबर', 'subtitle' => 'किसी भी फ़ोन से मुफ़्त कॉल', 'rows' => ['पुलिस | 100 / 112 | ', 'एम्बुलेंस | 108 | ', 'फ़ायर | 101 | ', 'महिला हेल्पलाइन | 181 | ', 'नज़दीकी अस्पताल | 9 डायल करें | रिसेप्शन जोड़ देगा', 'पर्यटक हेल्पलाइन | 1363 | '],
                'message' => 'होटल में किसी भी आपात स्थिति में तुरंत रिसेप्शन को कॉल करें।'],
        ],
    ],
    [
        'id' => 'map_qr', 'category' => 'guide', 'variant' => 'qr', 'icon' => '📍',
        'name' => ['en' => 'Map QR code', 'gu' => 'નકશો QR કોડ', 'hi' => 'नक्शा QR कोड'],
        'fields' => [$title, $subtitle, ['key' => 'place', 'type' => 'text', 'label' => 'Place name'], ['key' => 'address', 'type' => 'textarea', 'label' => 'Address'],
            ['key' => 'url', 'type' => 'url', 'label' => 'Map link (Google Maps etc.)', 'required' => true], $qrCaption, $message, $footer, ...$colors],
        'colors' => ['bg_color' => '#1A237E', 'accent_color' => '#FFCA28'],
        'defaults' => [
            'en' => ['title' => 'Find Your Way', 'subtitle' => 'Open the map on your phone', 'place' => 'Dwarkadhish Temple', 'address' => "Jagat Mandir, Dwarka\nGujarat 361335",
                'url' => 'https://www.google.com/maps/search/?api=1&query=Dwarkadhish+Temple+Dwarka', 'qr_caption' => 'Scan with your phone camera', 'message' => 'Walking distance from the hotel.'],
            'gu' => ['title' => 'રસ્તો શોધો', 'subtitle' => 'ફોનમાં નકશો ખોલો', 'place' => 'દ્વારકાધીશ મંદિર', 'address' => "જગત મંદિર, દ્વારકા\nગુજરાત 361335",
                'url' => 'https://www.google.com/maps/search/?api=1&query=Dwarkadhish+Temple+Dwarka', 'qr_caption' => 'ફોનના કૅમેરાથી સ્કૅન કરો', 'message' => 'હોટલથી ચાલીને જઈ શકાય તેટલું અંતર.'],
            'hi' => ['title' => 'रास्ता खोजें', 'subtitle' => 'फ़ोन में नक्शा खोलें', 'place' => 'द्वारकाधीश मंदिर', 'address' => "जगत मंदिर, द्वारका\nगुजरात 361335",
                'url' => 'https://www.google.com/maps/search/?api=1&query=Dwarkadhish+Temple+Dwarka', 'qr_caption' => 'फ़ोन के कैमरे से स्कैन करें', 'message' => 'होटल से पैदल दूरी पर।'],
        ],
    ],
];
