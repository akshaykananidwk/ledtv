# HotelCast bulk TV setup (Windows)

Sets up many TVs from one PC: install the app, device owner, auto-start, room number and registration.

## Steps

1. Copy this folder to a Windows PC on the same network as the TVs.
2. Put the newest `HotelCast-TV-x.y.z.apk` in the folder.
3. Admin panel → **Rooms → Download setup file** → save it here as `tvs.csv`.
   (Or copy `tvs.sample.csv` to `tvs.csv` and fill in server, key and `tv_address,room` lines.)
4. On every TV: Settings → About → press **Build** 7× → Developer options → turn on
   **USB debugging** and (Android 11+) **Wireless debugging**. Note the IP:port.
   For full control remove the Google account (or set the TV up without one).
5. Double-click **HotelCast-Setup.bat**. adb is downloaded automatically if missing.
   For Android 11+ TVs the tool asks for the pairing IP:port and the 6-digit code shown on the TV.
6. Read the summary. `setup_result_*.csv` and `setup_log_*.txt` are written next to the script.
7. Turn **Wireless debugging off** on every TV afterwards.

Re-running is safe: already-done steps are skipped. Use `-SkipDeviceOwner` to only install/register.

## ગુજરાતી

1. આ folder PC માં copy કરો. તેમાં નવી APK ફાઇલ મૂકો.
2. Admin → Rooms → **Download setup file** થી `tvs.csv` અહીં સેવ કરો.
3. દરેક TV માં Developer options → USB debugging અને Wireless debugging ચાલુ કરો. Google account કાઢો.
4. **HotelCast-Setup.bat** પર double-click કરો. Android 11+ TV માટે TV પર દેખાતો pairing code પૂછશે.
5. છેલ્લે પરિણામ દેખાશે. પછી દરેક TV માં Wireless debugging બંધ કરો.
