# Live darshan library (2.9.3)

Live streams the Super Admin sets up once for the whole platform — first of all **Shri Dwarkadhish Live
Darshan**. Code: `core/LiveChannels.php`, console page `admin/platform_live.php`, customer block on
`admin/templates.php` (Templates → Live darshan).

## How it works

* Channels are stored in the platform setting `platform_live_channels` (JSON list: id, title, subtitle,
  type `stream` | `youtube`, url, auto, mute). Before anything is saved the list holds the Dwarkadhish channel
  without a link.
* A channel reaches a customer as a normal content item (type stream / youtube, `settings.platform_live` =
  channel id, duration 0). The customer assigns it to screens / playlists like any other item.
* **Add to every customer automatically** (auto): saving adds one copy to every customer that is not archived
  and does not have it yet; `Hotels::create()` adds it to new customers.
* **On request**: customers add it from Templates → Live darshan (one click, never twice).
* **Changing the link** updates the url / type / mute of every copy of every customer and bumps their content
  version, so all TVs play the new link after their next poll. Titles a customer changed are kept.
* **Delete** removes the channel from the library; customers keep their copy (no longer linked / updated).
* The link can be typed, or copied from an existing stream / YouTube item of any customer ("…or copy the
  link from a customer's content").

Super Admin only (`platform.manage`); customers get 403 on the console page. Strings: `lang/gu_live_channels.php`,
`lang/hi_live_channels.php`. Tests: `tests/Integration/Apps/LiveChannelsTest.php`.
