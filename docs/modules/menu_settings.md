# Menu settings: hide and reorder sidebar items (2.9)

Customers can choose the order of the sidebar and hide modules they do not use today, then show them again later. Every user can also arrange their own menu, including the Super Admin and resellers in their consoles.

| Where | Page | Who | Stored in |
|---|---|---|---|
| Settings → **Menu** (button next to "Your plan") | `admin/menu_settings.php?scope=business` | `settings.manage` (customer Admin; Super Admin inside the customer) | `system_settings` key `nav_menu` of the customer |
| Profile → **My menu** (also in the user drop-down) | `admin/menu_settings.php?scope=me` | every logged-in user, in every panel | `users.menu_prefs` (migration `036_menu_prefs.sql`), one entry per panel |

Without `scope`, the page opens the business menu for users with `settings.manage` in the customer workspace. Everyone else gets their own menu. The page is a core page, registered under the `profile` feature in `core/Features.php`.

## Data

```json
{"order": ["broadcast", "index", "rooms", "..."], "hidden": ["logs", "apk", "power"], "more": 1}
```

`users.menu_prefs` holds `{"customer": {...}, "platform": {...}, "reseller": {...}, "chain": {...}}`. A missing panel entry, or a NULL column, means "use the business default, or the built-in menu". `core/NavMenu.php` normalizes all input: keys must match `[a-z0-9_]{1,60}`, duplicates are removed, there are at most 200 keys, and broken JSON is treated as "no customisation".

## Which menu applies (`Panel::navMenu()`, `NavMenu::effective()`)

1. The user's **personal menu** for the current panel.
2. Otherwise, in the customer workspace, the customer's **business default**.
3. Otherwise, the **built-in order**: `nav.d` file order plus `after`, or `Panel::ORDER` in the consoles.

The personal menu replaces the business default completely; the two are not merged. When a user first opens "My menu", it starts from the business default. **"Use my business's default"** deletes the personal entry.

A Super Admin, reseller or chain admin who has **opened a customer** sees that customer's business menu, not their own personal menu, so support sees what the customer sees. Their personal menu applies only in their own console. In that situation, "My menu" explains this and links to the customer's menu.

## Safety rules

- **Access is never widened.** The menu settings run after `hc_nav_sections()` has already filtered items by permission (`Auth::can`), plan (`Features::pageVisible`), SaaS mode and hotel context. A saved key that the user cannot see is ignored: modules outside the plan, platform items, items the user lacks permission for, and unknown or junk keys.
- **Saving keeps only what the editor can see.** Keys outside the editor's own sidebar are dropped. Hidden keys that the editor cannot see are kept from the previous config, so a restricted editor never un-hides them by accident.
- **Hiding is visual only.** A hidden page still opens by its URL for users with permission. Permissions are not changed.
- **Required items** can be moved but not hidden: `index` and `settings` (customer workspace), `platform_overview` and `platform_settings` (console), `reseller_overview` (reseller panel) and `chain` (chain dashboard). Profile, My menu and Log out are in the top-bar user menu, which is never customised. Required keys are removed from `hidden` both when saving and when applying, so a hand-edited config cannot hide them either.
- **"Hidden items (n)" group.** This option is on by default. Hidden items appear in a collapsible `<details>` group at the bottom of the sidebar, so they stay easy to find. The group opens automatically when the current page is one of them. If the option is off, hidden items are not shown at all.
- Saving uses CSRF on the form POST. Business changes are written to the activity log (`menu_settings`). Read-only demo users and suspended customers cannot save (global guards).

## New modules (merge logic, `NavMenu::order()`)

Saved keys come first, in their saved order. A key that is missing from the saved order, such as a module added by a later update, is inserted right after its nearest built-in predecessor that is already placed, or at the top if it has none. For example, a menu saved before "Ticker bar" existed shows Ticker bar directly after Broadcast. Unknown saved keys are skipped.

## Groups

The customer workspace sidebar has no headings, so its order is one flat list. The **Super Admin console and the reseller panel** have headings (`Panel::GROUPS`). **Items are reordered only within their heading, and the headings keep their order.** The editor shows one sortable list per heading, and drag and drop or Up/Down never moves an item into another heading. `Panel::navGroups()` keeps the item order it is given within each heading, so a hand-edited cross-group order cannot break the headings. Chain users in a customer workspace have a second section ("Chain") with its own list.

## UI

Each row has the following controls:

- A drag handle that uses HTML5 drag and drop with the mouse, plus a pointer-events fallback for touch and pen.
- **Up/Down buttons** for keyboard, screen-reader and phone users. Moves are announced in an `aria-live` region.
- A **Show** switch. Required items show a lock badge ("Always shown") instead.

Other parts of the page:

- The "Hidden items group" checkbox, **Save menu** and **Reset to default**. In the customer workspace, "My menu" has **Use my business's default** instead of Reset to default.
- A badge that shows the menu's source: Default menu, Custom menu, Using your business's menu, or Your own menu.

There is no new library or CDN. The page script is `assets/js/menu_settings.js`, and the styles are at the end of `assets/css/admin.css`. Phone layout: the actions wrap under the label, and the page has no horizontal scroll.

Translations are in `lang/gu_menu_settings.php` and `lang/hi_menu_settings.php`.

## Tests

- `tests/Integration/Apps/MenuSettingsTest.php` covers:
  - CSRF, `settings.manage` for the business menu, and staff limited to their personal menu.
  - Sidebar order and the More group, the more-off option, and the user override winning over the business default and going back.
  - Reset, required items, a hidden page opening by URL, items outside the plan or permission never appearing, unknown keys and broken JSON, and new-item merge (unit and HTTP).
  - The Super Admin's console personal menu (within headings), the impersonation rule, and the reseller's personal menu.
- `tests/browser/menu_settings_qa.js` runs headless Chromium against `panels_server.php` and writes screenshots to `docs/screenshots/2.9/`.
