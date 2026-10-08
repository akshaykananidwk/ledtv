<?php
declare(strict_types=1);

/**
 * Gujarati translations — Shop offers app (#4): core/Apps/OffersApp.php, core/Offers.php, admin/offers.php,
 * plus strings shared by the four business apps (core/BusinessApps.php, sidebar entries).
 * Placeholders like :t / :n stay unchanged.
 */
return [
    // Shared (business apps)
    ':f: enter a number between :a and :b.' => ':f: :a અને :b વચ્ચેની સંખ્યા લખો.',
    'Invalid date and time: :d' => 'અમાન્ય તારીખ અને સમય: :d',
    'Offers' => 'ઓફર',
    'Class schedule' => 'ક્લાસ સમયપત્રક',
    'Departures board' => 'પ્રસ્થાન બોર્ડ',
    'KPI dashboard' => 'KPI ડેશબોર્ડ',
    'Show on TVs' => 'ટીવી પર બતાવો',
    'Sort order' => 'ક્રમ',
    'Smaller numbers are shown first.' => 'નાની સંખ્યા પહેલાં બતાવાય છે.',
    'Change page every (seconds)' => 'પાનું બદલો દર (સેકન્ડ)',
    'Footer text (optional)' => 'નીચેનું લખાણ (વૈકલ્પિક)',
    '24-hour time' => '24-કલાકનો સમય',
    'Every day' => 'દરરોજ',

    // App
    'Shop offers' => 'દુકાનની ઓફર',
    'Discount offers for shops and malls with old price, big % badge and a live countdown. Expired offers disappear automatically.' => 'દુકાનો અને મોલ માટે ડિસ્કાઉન્ટ ઓફર: જૂનો ભાવ, મોટો % બેજ અને લાઇવ કાઉન્ટડાઉન. પૂરી થયેલી ઓફર આપમેળે દૂર થાય છે.',
    'Today\'s offers' => 'આજની ઓફર',
    'One big offer at a time' => 'એક સમયે એક મોટી ઓફર',
    'Grid (4 offers per page)' => 'ગ્રીડ (પાના દીઠ 4 ઓફર)',
    'List (5 offers per page)' => 'યાદી (પાના દીઠ 5 ઓફર)',
    'Maximum offers' => 'વધુમાં વધુ ઓફર',
    'Currency symbol' => 'ચલણ ચિહ્ન',
    'e.g. T&C apply. While stocks last.' => 'દા.ત. શરતો લાગુ. સ્ટોક હોય ત્યાં સુધી.',
    'Show "ends in" countdown' => '"પૂરી થશે" કાઉન્ટડાઉન બતાવો',
    'Festive sale: Silk sarees' => 'તહેવાર સેલ: સિલ્ક સાડી',
    'Pure silk, all colours. Free fall and pico.' => 'શુદ્ધ સિલ્ક, બધા રંગ. ફોલ-પીકો મફત.',
    'Bestseller' => 'સૌથી વધુ વેચાતું',
    'Buy 1 Get 1 free' => '1 ખરીદો 1 મફત',
    'On all shirts and kurtas.' => 'બધા શર્ટ અને કુર્તા પર.',
    'Mobile accessories' => 'મોબાઇલ એક્સેસરીઝ',
    'Ends in' => 'પૂરી થશે',
    ':nd' => ':n દિ',
    'OFF' => 'છૂટ',
    'Add offers on the Offers page.' => 'ઓફર પેજ પર ઓફર ઉમેરો.',

    // Offers (validation)
    'The offer title is required.' => 'ઓફરનું શીર્ષક જરૂરી છે.',
    'The description can have at most :n characters.' => 'વર્ણનમાં વધુમાં વધુ :n અક્ષર હોઈ શકે.',
    'Offer price' => 'ઓફર ભાવ',
    'Old price (MRP)' => 'જૂનો ભાવ (MRP)',
    'Discount %' => 'ડિસ્કાઉન્ટ %',
    'The offer price must not be higher than the old price.' => 'ઓફર ભાવ જૂના ભાવ કરતાં વધુ ન હોવો જોઈએ.',
    'The end must be after the start.' => 'અંત શરૂઆત પછી હોવો જોઈએ.',

    // admin/offers.php
    'Offer not found.' => 'ઓફર મળી નહીં.',
    'Offer ":t" saved.' => 'ઓફર ":t" સાચવી.',
    'Offer ":t" is shown again.' => 'ઓફર ":t" ફરી બતાવાય છે.',
    'Offer ":t" is hidden.' => 'ઓફર ":t" છુપાવી.',
    'Offer ":t" deleted.' => 'ઓફર ":t" કાઢી નાખી.',
    'Edit offer' => 'ઓફર બદલો',
    'New offer' => 'નવી ઓફર',
    'Offers appear on every TV showing a Shop offers app screen, within 15 seconds.' => 'દુકાનની ઓફર એપ બતાવતા દરેક ટીવી પર ઓફર 15 સેકન્ડમાં દેખાય છે.',
    'e.g. Diwali sale: 30% off on sarees' => 'દા.ત. દિવાળી સેલ: સાડી પર 30% છૂટ',
    'Automatic' => 'આપમેળે',
    'Leave empty to calculate it from the old price.' => 'જૂના ભાવ પરથી ગણવા માટે ખાલી રાખો.',
    'Badge (optional)' => 'બેજ (વૈકલ્પિક)',
    'e.g. New, Bestseller, Limited' => 'દા.ત. નવું, સૌથી વધુ વેચાતું, મર્યાદિત',
    'Limited stock' => 'મર્યાદિત સ્ટોક',
    'Today only' => 'ફક્ત આજે',
    'Valid from' => 'ક્યારથી માન્ય',
    'The TV shows a countdown and removes the offer when it ends. Leave empty for no end.' => 'ટીવી કાઉન્ટડાઉન બતાવે છે અને ઓફર પૂરી થાય ત્યારે તેને દૂર કરે છે. અંત ન હોય તો ખાલી રાખો.',
    'Discount offers for the Shop offers app screens on your TVs.' => 'તમારા ટીવી પરની દુકાનની ઓફર એપ સ્ક્રીન માટે ડિસ્કાઉન્ટ ઓફર.',
    'Offer screens (:n)' => 'ઓફર સ્ક્રીન (:n)',
    'Create an offers screen' => 'ઓફર સ્ક્રીન બનાવો',
    'No offers yet. Add the first one.' => 'હજી કોઈ ઓફર નથી. પહેલી ઉમેરો.',
    'Delete offer ":t"?' => 'ઓફર ":t" કાઢી નાખવી છે?',
];
