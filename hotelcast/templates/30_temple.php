<?php
/**
 * Template library — temple (aarti timings, bhog, darshan closed notice).
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

return [
    [
        'id' => 'aarti', 'category' => 'temple', 'variant' => 'timetable', 'icon' => '🛕',
        'name' => ['en' => 'Aarti & darshan timings', 'gu' => 'આરતી અને દર્શન સમય', 'hi' => 'आरती और दर्शन समय'],
        'fields' => [$title, $subtitle, $rows('Time | Aarti / darshan | Note'), $message, $footer, ...$colors],
        'colors' => ['bg_color' => '#4A0E0E', 'accent_color' => '#FFB300'],
        'defaults' => [
            'en' => ['title' => 'Shri Dwarkadhish Darshan', 'subtitle' => 'Daily aarti & darshan timings', 'message' => 'Timings may change on festivals. Please confirm at the temple or reception.',
                'rows' => ['6:30 AM | Mangla Aarti | ', '7:00 – 8:00 AM | Mangla Darshan | ', '10:30 AM | Shringar Aarti | ', '1:00 – 5:00 PM | Darshan closed | Anosar', '7:30 PM | Sandhya Aarti | ', '9:30 PM | Shayan Aarti | ']],
            'gu' => ['title' => 'શ્રી દ્વારકાધીશ દર્શન', 'subtitle' => 'દૈનિક આરતી અને દર્શન સમય', 'message' => 'તહેવારોમાં સમય બદલાઈ શકે છે. કૃપા કરીને મંદિર અથવા રિસેપ્શન પર ખાતરી કરો.',
                'rows' => ['સવારે 6:30 | મંગળા આરતી | ', '7:00 – 8:00 | મંગળા દર્શન | ', '10:30 | શૃંગાર આરતી | ', '1:00 – 5:00 | દર્શન બંધ | અનોસર', 'સાંજે 7:30 | સંધ્યા આરતી | ', 'રાત્રે 9:30 | શયન આરતી | ']],
            'hi' => ['title' => 'श्री द्वारकाधीश दर्शन', 'subtitle' => 'दैनिक आरती और दर्शन समय', 'message' => 'त्योहारों पर समय बदल सकता है। कृपया मंदिर या रिसेप्शन पर पुष्टि करें।',
                'rows' => ['सुबह 6:30 | मंगला आरती | ', '7:00 – 8:00 | मंगला दर्शन | ', '10:30 | श्रृंगार आरती | ', '1:00 – 5:00 | दर्शन बंद | अनोसर', 'शाम 7:30 | संध्या आरती | ', 'रात 9:30 | शयन आरती | ']],
        ],
    ],
    [
        'id' => 'bhog', 'category' => 'temple', 'variant' => 'timetable', 'icon' => '🍚',
        'name' => ['en' => 'Bhog / prasad timings', 'gu' => 'ભોગ / પ્રસાદ સમય', 'hi' => 'भोग / प्रसाद समय'],
        'fields' => [$title, $subtitle, $rows('Time | Bhog | Note'), $message, $footer, ...$colors],
        'colors' => ['bg_color' => '#5D2A00', 'accent_color' => '#FFD54F'],
        'defaults' => [
            'en' => ['title' => 'Bhog Timings', 'subtitle' => 'Darshan remains closed during bhog', 'message' => 'Prasad is available at the temple prasad counter.',
                'rows' => ['8:00 AM | Bal Bhog | ', '11:00 AM | Shringar Bhog | ', '12:00 PM | Raj Bhog | ', '5:30 PM | Utthapan Bhog | ', '8:00 PM | Shayan Bhog | ']],
            'gu' => ['title' => 'ભોગ સમય', 'subtitle' => 'ભોગ દરમિયાન દર્શન બંધ રહે છે', 'message' => 'પ્રસાદ મંદિરના પ્રસાદ કાઉન્ટર પર મળશે.',
                'rows' => ['સવારે 8:00 | બાલ ભોગ | ', '11:00 | શૃંગાર ભોગ | ', 'બપોરે 12:00 | રાજ ભોગ | ', 'સાંજે 5:30 | ઉત્થાપન ભોગ | ', 'રાત્રે 8:00 | શયન ભોગ | ']],
            'hi' => ['title' => 'भोग समय', 'subtitle' => 'भोग के समय दर्शन बंद रहते हैं', 'message' => 'प्रसाद मंदिर के प्रसाद काउंटर पर उपलब्ध है।',
                'rows' => ['सुबह 8:00 | बाल भोग | ', '11:00 | श्रृंगार भोग | ', 'दोपहर 12:00 | राज भोग | ', 'शाम 5:30 | उत्थापन भोग | ', 'रात 8:00 | शयन भोग | ']],
        ],
    ],
    [
        'id' => 'darshan_closed', 'category' => 'temple', 'variant' => 'big', 'icon' => '🔔',
        'name' => ['en' => 'Darshan closed notice', 'gu' => 'દર્શન બંધ સૂચના', 'hi' => 'दर्शन बंद सूचना'],
        'fields' => [$title, ['key' => 'big_label', 'type' => 'text', 'label' => 'Label above the time'], ['key' => 'big', 'type' => 'text', 'label' => 'Closed from – to', 'required' => true],
            $message, $footer, ...$colors],
        'colors' => ['bg_color' => '#3E2723', 'accent_color' => '#FF7043'],
        'defaults' => [
            'en' => ['title' => 'Darshan Closed', 'big_label' => 'Temple closed today', 'big' => '1:00 PM – 5:00 PM', 'message' => 'Darshan re-opens after Utthapan. Jai Dwarkadhish!'],
            'gu' => ['title' => 'દર્શન બંધ', 'big_label' => 'આજે મંદિર બંધ', 'big' => '1:00 – 5:00', 'message' => 'ઉત્થાપન પછી દર્શન ફરી ખુલશે. જય દ્વારકાધીશ!'],
            'hi' => ['title' => 'दर्शन बंद', 'big_label' => 'आज मंदिर बंद', 'big' => '1:00 – 5:00', 'message' => 'उत्थापन के बाद दर्शन फिर से खुलेंगे। जय द्वारकाधीश!'],
        ],
    ],
];
