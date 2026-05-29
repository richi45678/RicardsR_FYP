/*
 * ESP32-CAM — WiFi, /capture API, BOOT button = capture + upload + server AI.
 */

#include <WiFi.h>
#include <WiFiClient.h>
#include <WebServer.h>
#include "esp_camera.h"
#include <HTTPClient.h>
#include "camera_pins.h"

const char* ssid = "SSID";
const char* password = "PASSWORD";
const char* serverBase = "http://4.233.212.124";
const char* uploadPath = "/upload.php";

#define BTN_PIN 0  // BOOT button (active LOW)

WebServer server(80);
String espId;

bool btnLast = HIGH;
unsigned long btnDebounce = 0;
bool busy = false;

bool connectWifi() {
  if (WiFi.status() == WL_CONNECTED) return true;
  WiFi.mode(WIFI_STA);
  WiFi.begin(ssid, password);
  Serial.print("WiFi");
  for (int i = 0; i < 40 && WiFi.status() != WL_CONNECTED; i++) {
    delay(500);
    Serial.print('.');
  }
  Serial.println(WiFi.status() == WL_CONNECTED ? WiFi.localIP() : " failed");
  return WiFi.status() == WL_CONNECTED;
}

void setupCamera() {
  camera_config_t c;
  c.ledc_channel = LEDC_CHANNEL_0;
  c.ledc_timer = LEDC_TIMER_0;
  c.pin_d0 = Y2_GPIO_NUM;
  c.pin_d1 = Y3_GPIO_NUM;
  c.pin_d2 = Y4_GPIO_NUM;
  c.pin_d3 = Y5_GPIO_NUM;
  c.pin_d4 = Y6_GPIO_NUM;
  c.pin_d5 = Y7_GPIO_NUM;
  c.pin_d6 = Y8_GPIO_NUM;
  c.pin_d7 = Y9_GPIO_NUM;
  c.pin_xclk = XCLK_GPIO_NUM;
  c.pin_pclk = PCLK_GPIO_NUM;
  c.pin_vsync = VSYNC_GPIO_NUM;
  c.pin_href = HREF_GPIO_NUM;
  c.pin_sccb_sda = SIOD_GPIO_NUM;
  c.pin_sccb_scl = SIOC_GPIO_NUM;
  c.pin_pwdn = PWDN_GPIO_NUM;
  c.pin_reset = RESET_GPIO_NUM;
  c.xclk_freq_hz = 20000000;
  c.pixel_format = PIXFORMAT_JPEG;
  c.frame_size = FRAMESIZE_SVGA;
  c.jpeg_quality = 12;
  c.fb_count = 2;
  c.grab_mode = CAMERA_GRAB_LATEST;
  if (psramFound()) c.fb_location = CAMERA_FB_IN_PSRAM;

  if (esp_camera_init(&c) != ESP_OK) {
    Serial.println("Camera init failed");
    return;
  }
  sensor_t* s = esp_camera_sensor_get();
  if (s) {
    s->set_brightness(s, -1);
    s->set_contrast(s, 1);
    s->set_saturation(s, 2);
    s->set_sharpness(s, 1);
    s->set_denoise(s, 1);
    s->set_whitebal(s, 1);
    s->set_awb_gain(s, 1);
    s->set_exposure_ctrl(s, 1);
    s->set_gain_ctrl(s, 1);
    s->set_vflip(s, 1);
  }
  Serial.println("Camera ready");
}

String captureAndUpload() {
  if (!connectWifi()) return "WiFi not connected";

  camera_fb_t* fb = esp_camera_fb_get();
  if (fb) { esp_camera_fb_return(fb); delay(100); }

  fb = esp_camera_fb_get();
  if (!fb) return "Capture failed";

  Serial.printf("Captured %u bytes\n", (unsigned)fb->len);

  WiFiClient client;
  HTTPClient http;
  http.begin(client, String(serverBase) + uploadPath);
  http.addHeader("Content-Type", "image/jpeg");
  http.addHeader("X-ESP32-ID", espId);
  http.setTimeout(60000);

  int code = http.POST(fb->buf, fb->len);
  String body = http.getString();
  http.end();
  esp_camera_fb_return(fb);

  if (code == 200) {
    Serial.println("Upload OK — server runs plant AI");
    return "Upload OK: " + body;
  }
  return "Upload failed HTTP " + String(code);
}

void sendCors() {
  server.sendHeader("Access-Control-Allow-Origin", "*");
  server.sendHeader("Access-Control-Allow-Methods", "GET, OPTIONS");
  server.sendHeader("Access-Control-Allow-Headers", "Content-Type");
}

void handleCapture() {
  sendCors();
  busy = true;
  String result = captureAndUpload();
  busy = false;
  server.send(200, "text/plain", result);
}

void handleStatus() {
  sendCors();
  String j = "{\"id\":\"" + espId + "\",\"ip\":\"" + WiFi.localIP().toString() +
             "\",\"rssi\":" + String(WiFi.RSSI()) +
             ",\"heap\":" + String(ESP.getFreeHeap()) +
             ",\"psram\":" + String(ESP.getFreePsram()) +
             ",\"camera\":\"OV3660\"}";
  server.send(200, "application/json", j);
}

void handleOptions() {
  sendCors();
  server.send(204);
}

void checkButton() {
  if (busy) return;
  int st = digitalRead(BTN_PIN);
  if (st != btnLast) btnDebounce = millis();
  if (millis() - btnDebounce > 50 && st == LOW && btnLast == HIGH) {
    Serial.println("Button — capture");
    busy = true;
    Serial.println(captureAndUpload());
    busy = false;
  }
  btnLast = st;
}

void setup() {
  Serial.begin(115200);
  delay(300);
  espId = String((uint32_t)ESP.getEfuseMac(), HEX);
  pinMode(BTN_PIN, INPUT_PULLUP);

  connectWifi();
  setupCamera();

  server.on("/capture", HTTP_OPTIONS, handleOptions);
  server.on("/capture", handleCapture);
  server.on("/status", HTTP_OPTIONS, handleOptions);
  server.on("/status", handleStatus);
  server.begin();

  Serial.println("ESP32-CAM ready");
  Serial.println("BOOT button = capture + AI on server");
  Serial.print("ID: ");
  Serial.println(espId);
}

void loop() {
  server.handleClient();
  checkButton();
  delay(5);
}