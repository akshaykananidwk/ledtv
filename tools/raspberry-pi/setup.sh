#!/usr/bin/env bash
# Krishna Cloud LED TV (HotelCast 2.4) — Raspberry Pi kiosk for the web player.
#
#   sudo ./setup.sh https://ledtv.example.com/player/ [options]
#
# What it does (Raspberry Pi OS Bookworm or newer; Bullseye works too):
#   * installs Chromium, cec-utils (HDMI-CEC) and unclutter (X11 only)
#   * enables desktop auto-login (raspi-config) and starts Chromium in kiosk mode, full screen, on the web
#     player URL — for the session in use: labwc (Bookworm 2024+), wayfire (Bookworm 2023) or X11 (LXDE)
#   * disables screen blanking (raspi-config, X11 xset / wayfire idle, consoleblank=0)
#   * installs hotelcast-cec.service: a tiny local HTTP bridge on 127.0.0.1:8765 that the web player calls
#     on SCREEN_OFF / SCREEN_ON (and room "off" schedules) to put the TV in standby / switch it on via CEC
#   * a daily cron job switches the TV on via CEC at 06:00 (optional, --cec-morning HH:MM, "off" = none)
#
# Options:
#   --user NAME          desktop user that runs the kiosk (default: the user who called sudo, else "pi")
#   --session S          force the session: labwc | wayfire | x11 (default: detected)
#   --no-cec             do not install the HDMI-CEC bridge
#   --cec-port N         port of the CEC bridge (default 8765)
#   --cec-morning HH:MM  daily CEC "TV on" time (default 06:00; "off" disables the cron job)
#   --no-reboot          do not offer the reboot at the end
#   -h, --help           this help
#
# Safe to run again (it rewrites its own files only). Remove everything with uninstall notes in README.md.
set -euo pipefail

PROG=$(basename "$0")
log() { printf '==> %s\n' "$*"; }
warn() { printf 'WARNING: %s\n' "$*" >&2; }
die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
usage() { sed -n '2,/^set -euo pipefail/{/^#/p}' "$0" | sed 's/^# \{0,1\}//'; exit "${1:-0}"; }

URL=""
KIOSK_USER="${SUDO_USER:-pi}"
SESSION=""
CEC=1
CEC_PORT=8765
CEC_MORNING="06:00"
OFFER_REBOOT=1

while [ $# -gt 0 ]; do
  case "$1" in
    -h|--help) usage 0 ;;
    --user) [ $# -ge 2 ] || die "--user needs a value"; KIOSK_USER="$2"; shift 2 ;;
    --session) [ $# -ge 2 ] || die "--session needs a value"; SESSION="$2"; shift 2 ;;
    --no-cec) CEC=0; shift ;;
    --cec-port) [ $# -ge 2 ] || die "--cec-port needs a value"; CEC_PORT="$2"; shift 2 ;;
    --cec-morning) [ $# -ge 2 ] || die "--cec-morning needs a value"; CEC_MORNING="$2"; shift 2 ;;
    --no-reboot) OFFER_REBOOT=0; shift ;;
    -*) die "unknown option $1 (see $PROG --help)" ;;
    *) [ -z "$URL" ] || die "only one URL please"; URL="$1"; shift ;;
  esac
done

# ------------------------------------------------------------------ checks
[ -n "$URL" ] || usage 1
[ "$(id -u)" -eq 0 ] || die "run as root: sudo $PROG $URL"
case "$URL" in
  http://*|https://*) ;;
  *) die "the URL must start with http:// or https:// (e.g. https://ledtv.example.com/player/)" ;;
esac
case "$URL" in
  *[[:space:]\"\'\`\$\\]*) die "the URL contains characters that are not allowed" ;;
esac
# A server address without /player/ gets it appended.
case "$URL" in
  */player|*/player/*|*/player\?*) ;;
  *) URL="${URL%/}/player/" ;;
esac
[[ "$CEC_PORT" =~ ^[0-9]{2,5}$ ]] && [ "$CEC_PORT" -ge 1024 ] && [ "$CEC_PORT" -le 65535 ] || die "--cec-port must be 1024-65535"
if [ "$CEC_MORNING" != "off" ]; then
  [[ "$CEC_MORNING" =~ ^([01][0-9]|2[0-3]):[0-5][0-9]$ ]] || die "--cec-morning must be HH:MM or off"
fi
case "$SESSION" in ""|labwc|wayfire|x11) ;; *) die "--session must be labwc, wayfire or x11" ;; esac
id "$KIOSK_USER" >/dev/null 2>&1 || die "user '$KIOSK_USER' does not exist (use --user NAME)"
[ "$KIOSK_USER" != "root" ] || die "the kiosk must not run as root (use --user NAME)"
HOME_DIR=$(getent passwd "$KIOSK_USER" | cut -d: -f6)
[ -d "$HOME_DIR" ] || die "home directory of $KIOSK_USER not found"
command -v apt-get >/dev/null 2>&1 || die "this script needs Raspberry Pi OS / Debian (apt-get)"

MODEL="unknown"
if [ -r /proc/device-tree/model ]; then MODEL=$(tr -d '\0' < /proc/device-tree/model); fi
case "$MODEL" in *"Raspberry Pi"*) ;; *) warn "this does not look like a Raspberry Pi ($MODEL) — continuing anyway" ;; esac
CODENAME="unknown"
if [ -r /etc/os-release ]; then CODENAME=$(. /etc/os-release && echo "${VERSION_CODENAME:-unknown}"); fi
log "Device: $MODEL · OS: $CODENAME · kiosk user: $KIOSK_USER"
log "Web player: $URL"

# ------------------------------------------------------------------ packages
log "Installing packages (Chromium, cec-utils, unclutter)…"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq || warn "apt-get update failed — trying with the current package lists"
CHROMIUM_PKG=chromium
if apt-cache show chromium-browser >/dev/null 2>&1 && ! apt-cache show chromium >/dev/null 2>&1; then CHROMIUM_PKG=chromium-browser; fi
PKGS=("$CHROMIUM_PKG" python3 curl)
[ "$CEC" -eq 1 ] && PKGS+=(cec-utils)
apt-cache show unclutter >/dev/null 2>&1 && PKGS+=(unclutter)
apt-get install -y -qq "${PKGS[@]}" || die "package installation failed: ${PKGS[*]}"
CHROMIUM_BIN=$(command -v chromium-browser || command -v chromium || true)
[ -n "$CHROMIUM_BIN" ] || die "Chromium was not found after installation"

# ------------------------------------------------------------------ session detection
detect_session() {
  local conf val=""
  for conf in /etc/lightdm/lightdm.conf /etc/lightdm/lightdm.conf.d/*.conf; do
    [ -r "$conf" ] || continue
    val=$(grep -E '^\s*(autologin-session|user-session)\s*=' "$conf" 2>/dev/null | tail -n1 | cut -d= -f2 | tr -d '[:space:]' || true)
    [ -n "$val" ] && break
  done
  case "$val" in
    *labwc*) echo labwc; return ;;
    *wayfire*|rpd-wayland) echo wayfire; return ;;
    *x*|LXDE*) echo x11; return ;;
  esac
  if command -v labwc >/dev/null 2>&1; then echo labwc
  elif command -v wayfire >/dev/null 2>&1; then echo wayfire
  else echo x11
  fi
}
[ -n "$SESSION" ] || SESSION=$(detect_session)
log "Desktop session: $SESSION"

# ------------------------------------------------------------------ auto-login + no blanking
if command -v raspi-config >/dev/null 2>&1; then
  log "Enabling desktop auto-login and disabling screen blanking (raspi-config)…"
  raspi-config nonint do_boot_behaviour B4 || warn "could not enable desktop auto-login"
  raspi-config nonint do_blanking 1 || warn "could not disable screen blanking"
else
  warn "raspi-config not found: enable desktop auto-login yourself"
fi
CMDLINE=""
for f in /boot/firmware/cmdline.txt /boot/cmdline.txt; do [ -f "$f" ] && { CMDLINE="$f"; break; }; done
if [ -n "$CMDLINE" ] && ! grep -q 'consoleblank=0' "$CMDLINE"; then
  cp "$CMDLINE" "$CMDLINE.hotelcast.bak"
  sed -i '1 s/$/ consoleblank=0/' "$CMDLINE"
  log "Added consoleblank=0 to $CMDLINE (backup: $CMDLINE.hotelcast.bak)"
fi

# ------------------------------------------------------------------ kiosk launcher
PLAYER_URL="$URL"
if [ "$CEC" -eq 1 ]; then
  case "$PLAYER_URL" in *\?*) PLAYER_URL="$PLAYER_URL&cec=$CEC_PORT" ;; *) PLAYER_URL="$PLAYER_URL?cec=$CEC_PORT" ;; esac
fi
log "Writing /usr/local/bin/hotelcast-kiosk…"
cat > /usr/local/bin/hotelcast-kiosk <<EOF
#!/bin/sh
# HotelCast web player kiosk (written by tools/raspberry-pi/setup.sh). Restarts Chromium if it exits.
URL='$PLAYER_URL'
CHROMIUM='$CHROMIUM_BIN'
PREFS="\$HOME/.config/chromium/Default/Preferences"
if [ -n "\${DISPLAY:-}" ] && [ -z "\${WAYLAND_DISPLAY:-}" ] && command -v xset >/dev/null 2>&1; then
  xset s off; xset s noblank; xset -dpms
  command -v unclutter >/dev/null 2>&1 && unclutter -idle 1 -root &
fi
# Wait up to 60 s for the network (the player also shows its cached content while offline).
i=0
while [ \$i -lt 30 ] && ! ip route 2>/dev/null | grep -q '^default'; do sleep 2; i=\$((i + 1)); done
while true; do
  # No "Chromium didn't shut down correctly" bar after a power cut.
  [ -f "\$PREFS" ] && sed -i 's/"exited_cleanly":false/"exited_cleanly":true/; s/"exit_type":"[^"]*"/"exit_type":"Normal"/' "\$PREFS"
  "\$CHROMIUM" --kiosk --start-fullscreen --noerrdialogs --disable-infobars --no-first-run --no-default-browser-check \\
    --disable-session-crashed-bubble --disable-translate --password-store=basic --check-for-update-interval=31536000 \\
    --autoplay-policy=no-user-gesture-required --ozone-platform-hint=auto --enable-features=OverlayScrollbar \\
    --disable-features=Translate,TranslateUI,LocalNetworkAccessChecks,PrivateNetworkAccessRespectPreflightResults,PrivateNetworkAccessSendPreflights \\
    "\$URL" >/dev/null 2>&1
  sleep 5
done
EOF
chmod 755 /usr/local/bin/hotelcast-kiosk

# Chromium policy: allow the player page to reach the local CEC bridge without a permission prompt.
SERVER_ORIGIN=$(printf '%s' "$URL" | sed -E 's#^(https?://[^/]+).*#\1#')
for d in /etc/chromium/policies/managed /etc/chromium-browser/policies/managed; do
  mkdir -p "$d"
  cat > "$d/hotelcast.json" <<EOF
{
  "AutoplayAllowed": true,
  "TranslateEnabled": false,
  "PasswordManagerEnabled": false,
  "LocalNetworkAccessAllowedForUrls": ["$SERVER_ORIGIN"],
  "InsecurePrivateNetworkRequestsAllowedForUrls": ["$SERVER_ORIGIN"]
}
EOF
done

# ------------------------------------------------------------------ autostart for the session
as_user() { install -d -o "$KIOSK_USER" -g "$(id -gn "$KIOSK_USER")" "$1"; }
case "$SESSION" in
  labwc)
    as_user "$HOME_DIR/.config/labwc"
    AUTOSTART="$HOME_DIR/.config/labwc/autostart"
    touch "$AUTOSTART"
    sed -i '/hotelcast-kiosk/d' "$AUTOSTART"
    echo '/usr/local/bin/hotelcast-kiosk &' >> "$AUTOSTART"
    chown "$KIOSK_USER:" "$AUTOSTART"
    log "Autostart: $AUTOSTART"
    ;;
  wayfire)
    as_user "$HOME_DIR/.config"
    INI="$HOME_DIR/.config/wayfire.ini"
    touch "$INI"
    sed -i '/hotelcast-kiosk/d' "$INI"
    if grep -q '^\[autostart\]' "$INI"; then
      sed -i '/^\[autostart\]/a hotelcast = /usr/local/bin/hotelcast-kiosk' "$INI"
    else
      printf '\n[autostart]\nhotelcast = /usr/local/bin/hotelcast-kiosk\n' >> "$INI"
    fi
    if ! grep -q '^\[idle\]' "$INI"; then
      printf '\n[idle]\ndpms_timeout = -1\nscreensaver_timeout = -1\n' >> "$INI"
    fi
    chown "$KIOSK_USER:" "$INI"
    log "Autostart: $INI"
    ;;
  x11)
    DIR="$HOME_DIR/.config/lxsession/LXDE-pi"
    as_user "$HOME_DIR/.config"
    as_user "$HOME_DIR/.config/lxsession"
    as_user "$DIR"
    AUTOSTART="$DIR/autostart"
    if [ ! -f "$AUTOSTART" ] && [ -f /etc/xdg/lxsession/LXDE-pi/autostart ]; then cp /etc/xdg/lxsession/LXDE-pi/autostart "$AUTOSTART"; fi
    touch "$AUTOSTART"
    sed -i '/hotelcast-kiosk/d; /xscreensaver/d; /^@xset/d' "$AUTOSTART"
    printf '@xset s off\n@xset -dpms\n@xset s noblank\n@/usr/local/bin/hotelcast-kiosk\n' >> "$AUTOSTART"
    chown "$KIOSK_USER:" "$AUTOSTART"
    # Plain X sessions without LXDE: an XDG autostart entry as well.
    as_user "$HOME_DIR/.config/autostart"
    cat > "$HOME_DIR/.config/autostart/hotelcast-kiosk.desktop" <<'EOF'
[Desktop Entry]
Type=Application
Name=HotelCast web player
Exec=/usr/local/bin/hotelcast-kiosk
X-GNOME-Autostart-enabled=true
EOF
    chown "$KIOSK_USER:" "$HOME_DIR/.config/autostart/hotelcast-kiosk.desktop"
    log "Autostart: $AUTOSTART"
    ;;
esac

# ------------------------------------------------------------------ HDMI-CEC bridge
if [ "$CEC" -eq 1 ]; then
  SCRIPT_DIR=$(cd "$(dirname "$0")" && pwd)
  [ -f "$SCRIPT_DIR/hotelcast-cec-bridge.py" ] || die "hotelcast-cec-bridge.py is missing next to $PROG"
  install -m 755 "$SCRIPT_DIR/hotelcast-cec-bridge.py" /usr/local/bin/hotelcast-cec-bridge
  usermod -a -G video "$KIOSK_USER" || warn "could not add $KIOSK_USER to the video group (CEC device access)"
  cat > /etc/systemd/system/hotelcast-cec.service <<EOF
[Unit]
Description=HotelCast web player HDMI-CEC bridge (127.0.0.1:$CEC_PORT)
After=network.target

[Service]
Type=simple
User=$KIOSK_USER
SupplementaryGroups=video
Environment=HC_CEC_PORT=$CEC_PORT
ExecStart=/usr/bin/python3 /usr/local/bin/hotelcast-cec-bridge
Restart=always
RestartSec=5
NoNewPrivileges=true

[Install]
WantedBy=multi-user.target
EOF
  systemctl daemon-reload
  systemctl enable --now hotelcast-cec.service || warn "could not start hotelcast-cec.service (see: journalctl -u hotelcast-cec)"
  if [ "$CEC_MORNING" != "off" ]; then
    H=${CEC_MORNING%%:*}; M=${CEC_MORNING##*:}
    printf '# HotelCast: switch the TV on every morning via HDMI-CEC (tools/raspberry-pi/setup.sh)\n%d %d * * * root /usr/bin/curl -fsS -m 30 http://127.0.0.1:%s/on >/dev/null 2>&1 || true\n' \
      "$((10#$M))" "$((10#$H))" "$CEC_PORT" > /etc/cron.d/hotelcast-cec
    chmod 644 /etc/cron.d/hotelcast-cec
    log "Cron: TV on at $CEC_MORNING (/etc/cron.d/hotelcast-cec)"
  else
    rm -f /etc/cron.d/hotelcast-cec
  fi
  if command -v cec-client >/dev/null 2>&1; then
    if echo 'scan' | timeout 25 cec-client -s -d 1 2>/dev/null | grep -qi 'device #0'; then
      log "HDMI-CEC: the TV answers."
    else
      warn "HDMI-CEC: no TV found. Enable CEC on the TV (Anynet+ / SimpLink / Bravia Sync …) and use the HDMI port next to the USB-C power on a Pi 4/5 (HDMI0)."
    fi
  fi
else
  systemctl disable --now hotelcast-cec.service >/dev/null 2>&1 || true
  rm -f /etc/systemd/system/hotelcast-cec.service /etc/cron.d/hotelcast-cec
fi

log "Done. The Pi opens $PLAYER_URL in full screen after the next boot."
log "On first start the screen shows a QR code: scan it with your phone and choose the room."
if [ "$OFFER_REBOOT" -eq 1 ] && [ -t 0 ]; then
  read -r -p "Reboot now? [y/N] " ans
  case "$ans" in y|Y|yes|YES) reboot ;; esac
fi
