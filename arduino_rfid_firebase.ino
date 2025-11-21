#include <WiFi.h>
#include <Firebase_ESP_Client.h>
#include <SPI.h>
#include <MFRC522.h>
#include <time.h>

#define WIFI_SSID "PLDTHOMEFIBReJ5ez"
#define WIFI_PASSWORD "PLDTWIFIEV4p3"
#define API_KEY "AIzaSyBkw1nKnl06PJ80FCU14gYBbx08M0PG5BE"
#define DATABASE_URL "https://iattendance-backup-115dc-default-rtdb.asia-southeast1.firebasedatabase.app"

#define SS_PIN 5
#define RST_PIN 27

MFRC522 rfid(SS_PIN, RST_PIN);
FirebaseData fbdo;
FirebaseAuth auth;
FirebaseConfig config;

bool signupOK = false;
unsigned long lastSend = 0;
const unsigned long DEBOUNCE_DELAY = 2000; // 2 seconds between scans
unsigned long scanCounter = 0; // Unique scan counter

String getFormattedTime() {
  time_t now;
  struct tm timeinfo;
  time(&now);
  localtime_r(&now, &timeinfo);
  char buffer[30];
  strftime(buffer, sizeof(buffer), "%Y-%m-%d %H:%M:%S", &timeinfo);
  return String(buffer);
}

void setup() {
  Serial.begin(115200);
  
  SPI.begin();
  rfid.PCD_Init();
  
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
  Serial.print("Connecting to WiFi");
  while (WiFi.status() != WL_CONNECTED) {
    Serial.print(".");
    delay(300);
  }
  Serial.println("\nWiFi Connected!");
  Serial.print("IP Address: ");
  Serial.println(WiFi.localIP());
  
  // Configure time
  configTime(28800, 0, "pool.ntp.org", "time.nist.gov");
  Serial.print("Syncing time");
  while (time(nullptr) < 100000) {
    Serial.print(".");
    delay(500);
  }
  Serial.println("\nTime synced!");
  
  // Firebase configuration
  config.api_key = API_KEY;
  config.database_url = DATABASE_URL;
  
  if (Firebase.signUp(&config, &auth, "", "")) {
    Serial.println("Firebase SignUp success!");
    signupOK = true;
  } else {
    Serial.printf("SignUp Error: %s\n", config.signer.signupError.message.c_str());
  }
  
  Firebase.begin(&config, &auth);
  Firebase.reconnectWiFi(true);
  
  Serial.println("RFID Reader Ready...");
  Serial.println("Waiting for RFID card...");
}

void loop() {
  // Check if new card is present
  if (!rfid.PICC_IsNewCardPresent()) {
    return;
  }
  
  // Check if card can be read
  if (!rfid.PICC_ReadCardSerial()) {
    return;
  }
  
  // Debounce: prevent multiple scans of same card
  unsigned long currentTime = millis();
  if (currentTime - lastSend < DEBOUNCE_DELAY) {
    rfid.PICC_HaltA();
    rfid.PCD_StopCrypto1();
    return;
  }
  
  // Extract UID
  String uid = "";
  for (byte i = 0; i < rfid.uid.size; i++) {
    if (rfid.uid.uidByte[i] < 0x10) {
      uid += "0";
    }
    uid += String(rfid.uid.uidByte[i], HEX);
  }
  uid.toUpperCase();
  
  Serial.println("=================================");
  Serial.println("Card detected!");
  Serial.println("UID: " + uid);
  
  // Send to Firebase
  if (Firebase.ready() && signupOK) {
    // IMPORTANT: Write to the path that web interface expects
    String path = "/attendance_system/rfid_scans/latest";
    
    // Increment scan counter for unique identification
    scanCounter++;
    
    // Method 1: Try using setJSON first (most reliable)
    FirebaseJson json;
    json.set("uid", uid);
    json.set("card", uid);
    json.set("tag", uid);
    json.set("value", uid);
    json.set("rfid", uid);
    json.set("scanned_at", getFormattedTime());
    json.set("timestamp", time(nullptr));
    json.set("server_time", time(nullptr));
    json.set("scan_id", scanCounter); // Unique scan ID
    json.set("scan_time", millis()); // Millis for precise timing
    
    Serial.println("Attempting to write to Firebase...");
    Serial.println("  Path: " + path);
    Serial.println("  UID: " + uid);
    
    // Write the entire JSON object to the path
    if (Firebase.RTDB.setJSON(&fbdo, path, &json)) {
      Serial.println("✓ RFID data sent to Firebase successfully!");
      Serial.println("  Path: " + path);
      Serial.println("  UID: " + uid);
      Serial.println("  Time: " + getFormattedTime());
      Serial.println("  Full JSON: " + fbdo.payload());
      lastSend = currentTime;
    } else {
      Serial.println("✗ Firebase setJSON Error: " + fbdo.errorReason());
      Serial.println("  Error Code: " + String(fbdo.errorCode()));
      Serial.println("  Error Path: " + fbdo.dataPath());
      
      // Fallback: Try setting individual fields
      Serial.println("Trying fallback method (setString)...");
      if (Firebase.RTDB.setString(&fbdo, path + "/uid", uid)) {
        Serial.println("✓ Fallback method successful!");
        Firebase.RTDB.setString(&fbdo, path + "/scanned_at", getFormattedTime());
        Firebase.RTDB.setInt(&fbdo, path + "/timestamp", time(nullptr));
        lastSend = currentTime;
      } else {
        Serial.println("✗ Fallback also failed: " + fbdo.errorReason());
      }
    }
  } else {
    Serial.println("✗ Firebase not ready or signup failed");
    if (!Firebase.ready()) {
      Serial.println("  Firebase.ready() = false");
    }
    if (!signupOK) {
      Serial.println("  Signup status: FAILED");
    }
  }
  
  // Halt card and stop crypto
  rfid.PICC_HaltA();
  rfid.PCD_StopCrypto1();
  
  Serial.println("Waiting for next card...");
  Serial.println("=================================");
  delay(500); // Small delay before next scan
}

