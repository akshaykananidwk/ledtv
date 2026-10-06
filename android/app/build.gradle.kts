import java.util.Properties

plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

// Signing: keystore.properties (project root) if present, otherwise environment variables
// HOTELCAST_KEYSTORE, HOTELCAST_KEYSTORE_PASSWORD, HOTELCAST_KEY_ALIAS, HOTELCAST_KEY_PASSWORD.
val keystorePropsFile = rootProject.file("keystore.properties")
val keystoreProps = Properties().apply {
    if (keystorePropsFile.exists()) keystorePropsFile.inputStream().use { load(it) }
}
fun signingValue(propKey: String, envKey: String): String? =
    keystoreProps.getProperty(propKey)?.takeIf { it.isNotBlank() } ?: System.getenv(envKey)?.takeIf { it.isNotBlank() }

val releaseStoreFile = signingValue("storeFile", "HOTELCAST_KEYSTORE")
val hasReleaseSigning = releaseStoreFile != null && rootProject.file(releaseStoreFile).exists()

// Built-in server used by QR setup when nothing is configured yet. Override per build with
// `-PhotelcastDefaultServer=https://hotel.example.com/hotelcast/` or in gradle.properties.
val defaultServerUrl: String = (findProperty("hotelcastDefaultServer") as String?)
    ?.trim()?.takeIf { it.isNotEmpty() } ?: "https://ledtv.akdwk.in/"

android {
    namespace = "com.hotelcast.tv"
    compileSdk = 34

    defaultConfig {
        applicationId = "com.hotelcast.tv"
        minSdk = 21
        targetSdk = 34
        versionCode = 5
        versionName = "2.1.0"
        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"
        vectorDrawables.useSupportLibrary = true
        buildConfigField(
            "String",
            "DEFAULT_SERVER_URL",
            "\"" + defaultServerUrl.replace("\\", "\\\\").replace("\"", "\\\"") + "\"",
        )
    }

    signingConfigs {
        if (hasReleaseSigning) {
            create("release") {
                storeFile = rootProject.file(releaseStoreFile!!)
                storePassword = signingValue("storePassword", "HOTELCAST_KEYSTORE_PASSWORD")
                keyAlias = signingValue("keyAlias", "HOTELCAST_KEY_ALIAS") ?: "hotelcast"
                keyPassword = signingValue("keyPassword", "HOTELCAST_KEY_PASSWORD")
                enableV1Signing = true // required for Android 5/6 TVs
                enableV2Signing = true
            }
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = false
            isShrinkResources = false
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
            if (hasReleaseSigning) signingConfig = signingConfigs.getByName("release")
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions {
        jvmTarget = "17"
    }
    buildFeatures {
        buildConfig = true
    }
    testOptions {
        unitTests.isReturnDefaultValues = true
    }
    lint {
        abortOnError = false
        checkReleaseBuilds = false
    }
    packaging {
        resources.excludes += setOf("META-INF/AL2.0", "META-INF/LGPL2.1", "META-INF/*.kotlin_module")
    }
}

dependencies {
    val exoVersion = "2.19.1"
    implementation("com.google.android.exoplayer:exoplayer-core:$exoVersion")
    implementation("com.google.android.exoplayer:exoplayer-ui:$exoVersion")
    implementation("com.google.android.exoplayer:exoplayer-hls:$exoVersion")
    implementation("com.google.android.exoplayer:exoplayer-rtsp:$exoVersion")
    implementation("com.google.android.exoplayer:exoplayer-dash:$exoVersion")

    implementation("com.squareup.retrofit2:retrofit:2.11.0")
    implementation("com.squareup.retrofit2:converter-gson:2.11.0")
    implementation("com.squareup.okhttp3:okhttp:4.12.0")
    implementation("com.squareup.okhttp3:logging-interceptor:4.12.0")
    implementation("com.google.code.gson:gson:2.10.1")
    implementation("com.github.bumptech.glide:glide:4.16.0")
    implementation("androidx.work:work-runtime-ktx:2.9.1")
    // On-device QR codes (Wi-Fi join, room services) — Apache 2.0, pure Java, no Android deps.
    implementation("com.google.zxing:core:3.5.3")

    implementation("androidx.core:core-ktx:1.13.1")
    implementation("androidx.appcompat:appcompat:1.7.0")
    implementation("androidx.constraintlayout:constraintlayout:2.1.4")
    implementation("androidx.lifecycle:lifecycle-runtime-ktx:2.7.0")
    implementation("androidx.lifecycle:lifecycle-service:2.7.0")
    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-android:1.8.1")

    testImplementation("junit:junit:4.13.2")
    testImplementation("org.jetbrains.kotlinx:kotlinx-coroutines-test:1.8.1")

    androidTestImplementation("androidx.test:core-ktx:1.5.0")
    androidTestImplementation("androidx.test:runner:1.5.2")
    androidTestImplementation("androidx.test:rules:1.5.0")
    androidTestImplementation("androidx.test.ext:junit-ktx:1.1.5")
    androidTestImplementation("androidx.test.espresso:espresso-core:3.5.1")
}
