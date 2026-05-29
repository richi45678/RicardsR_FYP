/*
 * ESP32 — soil moisture, HC-SR04, pump relay, BOOT = manual pump.
 * WiFi failover, Azure config pull, offline auto-irrigate.
 * Pump OFF when water tank empty (>= tankEmptyCm, default 18.5 cm).
 */

#include <WiFi.h>
#include <HTTPClient.h>
#include <Preferences.h>

const char *WIFI_SSIDS[] = { "SSID", "YOUR_BACKUP_SSID" };
const char *WIFI_PASSWORDS[] = { "PASSWORD", "YOUR_BACKUP_PASS" };
const int WIFI_COUNT = 2;

const char *SENSOR_URL = "http://4.233.212.124/sensor_api.php";
const char *CONFIG_URL = "http://4.233.212.124/sensor_config.php?id=";

const unsigned long UPLOAD_MS = 10000;
const unsigned long CONFIG_MS = 2000;
const unsigned long WIFI_TRY_MS = 12000;
const unsigned long DEBUG_MS = 2000;
const unsigned long PUMP_MANUAL_MS = 10000;

#define SOIL_PIN 34
#define TRIG_PIN 5
#define ECHO_PIN 13
#define USOUND_TIMEOUT_US 30000UL
#define RELAY_PIN 14
#define LED_PIN 2
#define BTN_PIN 0

Preferences prefs;
String chipId;

int soilRaw = 0;
float distanceCm = -1.0f;
float tankEmptyCm = 18.5f;

bool pumpOn = false;
unsigned long pumpUntil = 0;
unsigned long lastUpload, lastConfig, lastDebug, lastAutoPump, configRev;
unsigned long pumpMs = 5000, cooldownMs = 300000;
float threshPct = 40.0f;
bool autoOn = false;

int lastBtn = HIGH;
unsigned long debounceAt = 0;

float soilPercent() {
  return ((4095.0f - soilRaw) / 4095.0f) * 100.0f;
}

bool tankIsEmpty() {
  return distanceCm >= 0.0f && distanceCm >= tankEmptyCm;
}

bool pumpAllowed() {
  if (distanceCm < 0.0f) return true;
  return distanceCm < tankEmptyCm;
}

void readDistance() {
  digitalWrite(TRIG_PIN, LOW);
  delayMicroseconds(2);
  digitalWrite(TRIG_PIN, HIGH);
  delayMicroseconds(10);
  digitalWrite(TRIG_PIN, LOW);
  long us = pulseIn(ECHO_PIN, HIGH, USOUND_TIMEOUT_US);
  distanceCm = (us > 0) ? (us * 0.0343f) / 2.0f : -1.0f;
}

void loadPrefs() {
  prefs.begin("irrigate", true);
  threshPct = prefs.getFloat("thresh", 40.0f);
  pumpMs = prefs.getULong("pumpMs", 5000);
  cooldownMs = prefs.getULong("coolMs", 300000);
  autoOn = prefs.getBool("auto", false);
  lastAutoPump = prefs.getULong("lastPump", 0);
  configRev = prefs.getULong("cfgRev", 0);
  tankEmptyCm = prefs.getFloat("tankEmpty", 18.5f);
  prefs.end();
}

void savePrefs() {
  prefs.begin("irrigate", false);
  prefs.putFloat("thresh", threshPct);
  prefs.putULong("pumpMs", pumpMs);
  prefs.putULong("coolMs", cooldownMs);
  prefs.putBool("auto", autoOn);
  prefs.putULong("lastPump", lastAutoPump);
  prefs.putULong("cfgRev", configRev);
  prefs.putFloat("tankEmpty", tankEmptyCm);
  prefs.end();
}

int jInt(const String &b, const char *k, int def) {
  String n = String("\"") + k + "\":";
  int i = b.indexOf(n);
  return (i < 0) ? def : b.substring(i + n.length()).toInt();
}

bool jBool(const String &b, const char *k, bool def) {
  String n = String("\"") + k + "\":";
  int i = b.indexOf(n);
  if (i < 0) return def;
  String t = b.substring(i + n.length());
  if (t.startsWith("true")) return true;
  if (t.startsWith("false")) return false;
  return def;
}

float jFloat(const String &b, const char *k, float def) {
  String n = String("\"") + k + "\":";
  int i = b.indexOf(n);
  if (i < 0) return def;
  return b.substring(i + n.length()).toFloat();
}

void applyConfig(const String &body) {
  bool assigned = jBool(body, "assigned", false);
  int eff = jInt(body, "effective_threshold_percent", 40);
  int pMs = jInt(body, "pump_duration_ms", 5000);
  int cMs = jInt(body, "cooldown_ms", 300000);
  unsigned long rev = (unsigned long)jInt(body, "config_revision", 0);
  float te = jFloat(body, "tank_empty_cm", tankEmptyCm);

  eff = constrain(eff, 0, 100);
  pMs = max(pMs, 1000);
  cMs = max(cMs, 60000);
  if (te >= 5.0f && te <= 50.0f) tankEmptyCm = te;

  autoOn = assigned && jBool(body, "auto_irrigate_enabled", assigned);
  threshPct = (float)eff;
  pumpMs = (unsigned long)pMs;
  cooldownMs = (unsigned long)cMs;
  configRev = rev;
  savePrefs();

  Serial.printf("Config #%lu: auto=%s thresh=%.0f%% pump=%lums tankEmpty=%.1fcm\n",
                rev, autoOn ? "ON" : "OFF", threshPct, pumpMs, tankEmptyCm);
}

void applyConfigIfNew(const String &body) {
  unsigned long rev = (unsigned long)jInt(body, "config_revision", configRev);
  if (rev != configRev) applyConfig(body);
}

bool connectWifi() {
  WiFi.mode(WIFI_STA);
  for (int i = 0; i < WIFI_COUNT; i++) {
    if (!WIFI_SSIDS[i][0] || !strcmp(WIFI_SSIDS[i], "YOUR_BACKUP_SSID")) continue;
    Serial.printf("WiFi: %s ", WIFI_SSIDS[i]);
    WiFi.begin(WIFI_SSIDS[i], WIFI_PASSWORDS[i]);
    unsigned long t0 = millis();
    while (WiFi.status() != WL_CONNECTED && millis() - t0 < WIFI_TRY_MS) {
      delay(300);
      Serial.print('.');
    }
    Serial.println();
    if (WiFi.status() == WL_CONNECTED) {
      Serial.println(WiFi.localIP());
      return true;
    }
    WiFi.disconnect(true);
    delay(200);
  }
  Serial.println("WiFi offline — using saved config");
  return false;
}

void ensureWifi() {
  if (WiFi.status() != WL_CONNECTED) connectWifi();
}

void fetchConfig() {
  if (WiFi.status() != WL_CONNECTED) return;
  HTTPClient http;
  http.begin(String(CONFIG_URL) + chipId);
  http.setTimeout(8000);
  if (http.GET() == 200) applyConfigIfNew(http.getString());
  http.end();
}

void uploadSensor() {
  if (WiFi.status() != WL_CONNECTED) return;
  HTTPClient http;
  http.begin(SENSOR_URL);
  http.addHeader("Content-Type", "application/json");
  http.setTimeout(12000);

  String j = "{\"id\":\"" + chipId + "\"";
  j += ",\"soil_raw\":" + String(soilRaw);
  j += ",\"distance_cm\":" + String(distanceCm, 2);
  j += ",\"pump_running\":" + String(pumpOn ? "true" : "false");
  j += ",\"rssi\":" + String(WiFi.RSSI()) + "}";

  int code = http.POST(j);
  String resp = http.getString();
  http.end();

  if (code == 200) {
    Serial.println("Upload OK");
    applyConfigIfNew(resp);
  } else {
    Serial.printf("Upload HTTP %d\n", code);
  }
}

void startPump(unsigned long ms) {
  if (pumpOn) return;
  if (!pumpAllowed()) {
    Serial.printf("Pump BLOCKED — tank empty (%.1f cm >= %.1f cm)\n", distanceCm, tankEmptyCm);
    return;
  }
  digitalWrite(RELAY_PIN, HIGH);
  pumpOn = true;
  pumpUntil = millis() + ms;
}

void servicePump() {
  if (pumpOn && tankIsEmpty()) {
    digitalWrite(RELAY_PIN, LOW);
    pumpOn = false;
    Serial.println("Pump stopped — tank empty");
    return;
  }
  if (pumpOn && millis() >= pumpUntil) {
    digitalWrite(RELAY_PIN, LOW);
    pumpOn = false;
    Serial.println("Pump stopped");
  }
}

void checkAutoIrrigate() {
  if (pumpOn || !autoOn || !pumpAllowed()) return;
  float pct = soilPercent();
  if (pct >= threshPct) return;
  if (millis() - lastAutoPump < cooldownMs) return;

  startPump(pumpMs);
  lastAutoPump = millis();
  savePrefs();
  Serial.printf("AUTO pump: %.1f%% < %.0f%% (%lums)\n", pct, threshPct, pumpMs);
}

void debugLine() {
  Serial.printf("soil raw=%d (%.1f%%)  thresh=%.0f%%  ", soilRaw, soilPercent(), threshPct);
  if (distanceCm < 0) Serial.print("dist=no echo  ");
  else Serial.printf("dist=%.1f cm  ", distanceCm);
  Serial.printf("auto=%s pump=%s", autoOn ? "ON" : "OFF", pumpOn ? "RUN" : "off");
  if (!pumpAllowed()) Serial.print("  PUMP_BLOCKED");
  Serial.printf("  cfg#%lu\n", configRev);
}

void handleButton() {
  int btn = digitalRead(BTN_PIN);
  if (btn != lastBtn) debounceAt = millis();
  if ((millis() - debounceAt) > 50 && btn == LOW && !pumpOn) {
    if (!pumpAllowed()) {
      Serial.println("Manual pump blocked — tank empty");
    } else {
      startPump(PUMP_MANUAL_MS);
      Serial.println("Manual pump 10s");
    }
  }
  lastBtn = btn;
}

void setup() {
  Serial.begin(115200);
  chipId = String((uint32_t)ESP.getEfuseMac(), HEX);

  pinMode(LED_PIN, OUTPUT);
  pinMode(TRIG_PIN, OUTPUT);
  pinMode(ECHO_PIN, INPUT);
  pinMode(SOIL_PIN, INPUT);
  pinMode(RELAY_PIN, OUTPUT);
  pinMode(BTN_PIN, INPUT_PULLUP);
  digitalWrite(RELAY_PIN, LOW);

  loadPrefs();
  connectWifi();
  fetchConfig();

  Serial.println("Smart garden sensor");
  Serial.print("Chip ID: ");
  Serial.println(chipId);
}

void loop() {
  soilRaw = analogRead(SOIL_PIN);
  readDistance();

  handleButton();
  servicePump();
  checkAutoIrrigate();

  if (millis() - lastDebug >= DEBUG_MS) {
    lastDebug = millis();
    debugLine();
  }

  if (millis() - lastConfig >= CONFIG_MS) {
    lastConfig = millis();
    ensureWifi();
    fetchConfig();
  }

  if (millis() - lastUpload >= UPLOAD_MS) {
    lastUpload = millis();
    ensureWifi();
    uploadSensor();
  }

  digitalWrite(LED_PIN, pumpOn ? HIGH : LOW);
  delay(pumpOn ? 100 : 200);
}
