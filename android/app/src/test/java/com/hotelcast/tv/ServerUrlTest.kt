package com.hotelcast.tv

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

class ServerUrlTest {

    @Test fun addsApiSuffixToInstallPath() =
        assertEquals("https://hotel.com/hotelcast/api/", ServerUrl.normalize("https://hotel.com/hotelcast"))

    @Test fun handlesTrailingSlashAndWhitespace() =
        assertEquals("https://hotel.com/hotelcast/api/", ServerUrl.normalize("  https://hotel.com/hotelcast/  "))

    @Test fun rootInstall() =
        assertEquals("https://hotel.com/api/", ServerUrl.normalize("https://hotel.com"))

    @Test fun doesNotDuplicateApi() {
        assertEquals("https://hotel.com/hotelcast/api/", ServerUrl.normalize("https://hotel.com/hotelcast/api"))
        assertEquals("https://hotel.com/hotelcast/api/", ServerUrl.normalize("https://hotel.com/hotelcast/api/"))
        assertEquals("https://hotel.com/hotelcast/api/", ServerUrl.normalize("https://hotel.com/hotelcast/api/index.php?r=health"))
    }

    @Test fun stripsAdminAndQuery() =
        assertEquals("http://192.168.1.10/hotelcast/api/", ServerUrl.normalize("http://192.168.1.10/hotelcast/admin/?x=1#frag"))

    @Test fun lanIpWithoutSchemeUsesHttp() =
        assertEquals("http://192.168.1.10:8080/hotelcast/api/", ServerUrl.normalize("192.168.1.10:8080/hotelcast"))

    @Test fun domainWithoutSchemeUsesHttps() =
        assertEquals("https://hotel.com/hotelcast/api/", ServerUrl.normalize("hotel.com/hotelcast"))

    @Test fun keepsPortAndCase() =
        assertEquals("http://server.local:81/HotelCast/api/", ServerUrl.normalize("http://Server.local:81/HotelCast"))

    @Test fun rejectsGarbage() {
        assertNull(ServerUrl.normalize(""))
        assertNull(ServerUrl.normalize("   "))
        assertNull(ServerUrl.normalize(null))
        assertNull(ServerUrl.normalize("ftp://hotel.com/x"))
        assertNull(ServerUrl.normalize("http://"))
    }
}
