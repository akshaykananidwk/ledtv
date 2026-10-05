package com.hotelcast.tv

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class WifiQrTest {

    @Test fun plainNetwork() =
        assertEquals("WIFI:T:WPA;S:Hotel-Guest;P:secret123;;", WifiQr.build("Hotel-Guest", "secret123"))

    @Test fun escapesSpecialCharacters() {
        assertEquals("""a\;b\,c\:d\\e\"f""", WifiQr.escape("""a;b,c:d\e"f"""))
        assertEquals("""WIFI:T:WPA;S:My\;Net;P:p\:w\,\\\";;""", WifiQr.build("My;Net", """p:w,\""""))
    }

    @Test fun unicodeAndSpacesUntouched() =
        assertEquals("WIFI:T:WPA;S:હોટેલ Wi-Fi;P:પાસ word;;", WifiQr.build("હોટેલ Wi-Fi", "પાસ word"))

    @Test fun openNetwork() {
        assertEquals("WIFI:T:nopass;S:Lobby;;", WifiQr.build("Lobby", null))
        assertEquals("WIFI:T:nopass;S:Lobby;;", WifiQr.build("Lobby", ""))
    }

    @Test fun noSsid() {
        assertNull(WifiQr.build(null, "x"))
        assertNull(WifiQr.build("", "x"))
    }

    @Test fun qrMatrixEncodesWifiString() {
        val m = QrCodes.matrix(WifiQr.build("Hotel;Guest", "pä:ss")!!, 300)
        assertEquals(300, m.width)
        assertEquals(300, m.height)
        var dark = 0
        for (y in 0 until m.height) for (x in 0 until m.width) if (m.get(x, y)) dark++
        assertTrue("QR must contain dark modules", dark > 1000)
    }
}
