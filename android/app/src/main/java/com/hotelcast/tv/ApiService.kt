package com.hotelcast.tv

import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.POST
import retrofit2.http.Path
import retrofit2.http.Query

/**
 * HotelCast device API (see docs/API.md). Paths are relative (no leading slash) so they resolve
 * against the configured base URL, e.g. https://hotel.com/hotelcast/api/.
 * Authorization / X-Device-Id headers are added by [ApiClient]'s interceptor.
 */
interface ApiService {

    @POST("device/register")
    suspend fun register(@Body body: RegisterRequest): Response<ApiEnvelope<RegisterResponse>>

    @GET("device/command/{device_id}")
    suspend fun poll(
        @Path("device_id") deviceId: String,
        @Query("hash") hash: String,
        @Query("wait") wait: Int? = null,
    ): Response<ApiEnvelope<PollResponse>>

    @POST("device/ack")
    suspend fun ack(@Body body: AckRequest): Response<ApiEnvelope<AckResponse>>

    @POST("device/heartbeat")
    suspend fun heartbeat(@Body body: HeartbeatRequest): Response<ApiEnvelope<HeartbeatResponse>>

    @POST("device/played")
    suspend fun played(@Body body: PlayedRequest): Response<ApiEnvelope<Any>>

    @GET("content/{room_id}")
    suspend fun content(@Path("room_id") roomId: Long): Response<ApiEnvelope<Content>>

    @GET("health")
    suspend fun health(): Response<ApiEnvelope<HealthResponse>>
}
