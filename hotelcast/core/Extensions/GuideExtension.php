<?php
declare(strict_types=1);

/**
 * Local guide (#7): the content item chosen in Templates → "Local guide on the TV" (setting
 * local_guide_content_id) is offered in the TV guest menu as an item of type "content":
 *   { "id": "guide", "type": "content", "title": "Local guide", "icon": "map", "content": {ContentItem} }
 * Appended to $content['guest_menu'] (other modules' items are kept; an older "guide" item is replaced).
 */
final class GuideExtension implements ContentExtension
{
    /** Modes without a guest menu. */
    private const SKIP_MODES = ['suspended', 'emergency', 'off'];

    public function apply(array &$content, array $room): void
    {
        $id = Settings::int('local_guide_content_id', 0);
        if ($id <= 0 || in_array($content['mode'] ?? '', self::SKIP_MODES, true) || !Tenant::feature('templates')) {
            return;
        }
        $item = ContentManager::findOwn($id);
        if (!$item || !(int) $item['is_active']) {
            return;
        }
        $tv = ContentManager::toTvItem($item);
        $tv['duration'] = 0;
        $lang = (string) ($content['guest']['language'] ?? Settings::get('default_language', 'en'));
        $title = trim((string) Settings::get('local_guide_title', ''));
        $menu = array_values(array_filter((array) ($content['guest_menu'] ?? []), fn ($m) => is_array($m) && ($m['id'] ?? '') !== 'guide'));
        $menu[] = [
            'id' => 'guide',
            'type' => 'content',
            'title' => $title !== '' ? $title : I18n::translate('Local guide', in_array($lang, ['en', 'gu', 'hi'], true) ? $lang : 'en'),
            'icon' => 'map',
            'content' => $tv,
        ];
        $content['guest_menu'] = $menu;
    }
}
