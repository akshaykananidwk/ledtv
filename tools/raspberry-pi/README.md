# Raspberry Pi as a hotel TV player (web player kiosk)

Turns a Raspberry Pi into a Krishna Cloud LED TV screen: on boot it opens the **web player**
(`https://<your server>/player/`) full screen in Chromium. The screen shows a QR code; scan it with a phone,
choose the room, done. The Pi then plays the room's content, follows the admin panel's commands and can switch
the TV on / to standby over **HDMI-CEC**. See `docs/modules/web_player.md` for the web player itself.

## Requirements

* Raspberry Pi 4 or 5 (a Pi 3B+ works for images, announcements and apps; 1080p video is choppy), 2 GB RAM or more.
* **Raspberry Pi OS with desktop**, Bookworm (or newer). Bullseye also works (X11 session).
* The Pi on the network (Ethernet recommended) and connected to the TV's HDMI input — on a Pi 4/5 use **HDMI0**
  (next to the USB-C power port): CEC works on that port.
* For CEC: switch it on in the TV menu (Samsung *Anynet+*, LG *SimpLink*, Sony *Bravia Sync*, Philips *EasyLink*,
  Panasonic *VIERA Link*, TCL / Mi *HDMI CEC*).

## Install

```sh
# on the Pi (copy the two files from tools/raspberry-pi/ of the release, or the whole folder)
chmod +x setup.sh
sudo ./setup.sh https://ledtv.example.com/player/
sudo reboot
```

Options: `--user NAME` (desktop user, default the user who ran sudo), `--session labwc|wayfire|x11` (default:
detected), `--no-cec`, `--cec-port 8765`, `--cec-morning 06:00|off`, `--no-reboot`. Run `./setup.sh --help`.
The script is safe to run again, e.g. with a new server address.

## What it changes

| What | Where |
|---|---|
| Packages: `chromium` (or `chromium-browser`), `cec-utils`, `unclutter`, `curl`, `python3` | apt |
| Desktop auto-login, screen blanking off | `raspi-config nonint do_boot_behaviour B4`, `do_blanking 1` |
| No console blanking | `consoleblank=0` in `/boot/firmware/cmdline.txt` (backup `.hotelcast.bak`) |
| Kiosk launcher (waits for the network, restarts Chromium if it closes, hides the "did not shut down correctly" bar) | `/usr/local/bin/hotelcast-kiosk` |
| Autostart, depending on the session | labwc: `~/.config/labwc/autostart` · wayfire: `~/.config/wayfire.ini` (`[autostart]`, `[idle]`) · X11: `~/.config/lxsession/LXDE-pi/autostart` + `~/.config/autostart/hotelcast-kiosk.desktop` |
| Chromium policy (autoplay, no translate bar, local CEC bridge allowed) | `/etc/chromium/policies/managed/hotelcast.json` |
| HDMI-CEC bridge (127.0.0.1:8765 only) | `/usr/local/bin/hotelcast-cec-bridge`, `hotelcast-cec.service` |
| TV on every morning (CEC) | `/etc/cron.d/hotelcast-cec` |

The session is detected from LightDM's `autologin-session` / `user-session` (`rpd-labwc` → labwc,
`rpd-wayland` / `wayfire` → wayfire, `rpd-x` / `LXDE-pi-x` → X11), otherwise from the installed compositor.

## HDMI-CEC: SCREEN_OFF / SCREEN_ON

Chromium is started with `…/player/?cec=8765`. When the player's screen goes off — the **SCREEN_OFF** command,
a room switched off in the admin panel or a "TV off" power schedule (unless the hotel uses *black screen* power-off
mode) — it calls `http://127.0.0.1:8765/off`; when it comes back (SCREEN_ON, the schedule ends, an emergency) it
calls `/on`. `hotelcast-cec.service` turns that into `cec-client` commands (`standby 0`, `on 0` + `as`).

Test by hand on the Pi:

```sh
curl http://127.0.0.1:8765/off      # TV goes to standby
curl http://127.0.0.1:8765/on       # TV on, Pi becomes the active input
curl http://127.0.0.1:8765/status   # on | standby | unknown
echo scan | cec-client -s -d 1      # list the CEC devices
journalctl -u hotelcast-cec -f      # bridge log
```

A TV in standby cannot show content, so the bridge is only used for "off" states; emergencies always switch the
TV on. If the TV does not react, CEC is off in the TV menu, the cable / port does not carry CEC, or the TV only
accepts CEC from the input that is currently selected.

## Troubleshooting

* **Black screen / no browser after boot**: `cat ~/.config/labwc/autostart` (or the file of your session) must
  contain `/usr/local/bin/hotelcast-kiosk`; start it by hand from a terminal to see errors.
* **Setup screen again after a reboot**: the browser profile was reset (SD card full? `df -h`). The player keeps
  its token in Chromium's storage under `~/.config/chromium`.
* **No sound**: `--autoplay-policy=no-user-gesture-required` is set by the launcher; check the audio output
  (`raspi-config` → System → Audio → HDMI).
* **Hidden settings** on the screen: press `1 2 3 4` on a keyboard (or hold OK / Enter for 3 s); the room PIN is
  asked. From there: reload, re-pair, reset.
* **Exit the kiosk**: `Ctrl+Alt+T` opens a terminal on most sessions; `pkill -f hotelcast-kiosk; pkill chromium`.

## Uninstall

```sh
sudo systemctl disable --now hotelcast-cec.service
sudo rm -f /etc/systemd/system/hotelcast-cec.service /etc/cron.d/hotelcast-cec /usr/local/bin/hotelcast-kiosk \
  /usr/local/bin/hotelcast-cec-bridge /etc/chromium/policies/managed/hotelcast.json /etc/chromium-browser/policies/managed/hotelcast.json
sed -i '/hotelcast-kiosk/d' ~/.config/labwc/autostart ~/.config/wayfire.ini ~/.config/lxsession/LXDE-pi/autostart 2>/dev/null
rm -f ~/.config/autostart/hotelcast-kiosk.desktop
```

## Notes

The script was checked with `shellcheck` and its argument checks were tested, but it was **not run on a real Pi**
by the developers of this release. It stops on the first error (`set -euo pipefail`) and prints what it changes.
Please report problems with the output of `./setup.sh` and `journalctl -b -u hotelcast-cec`.
