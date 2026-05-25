# Symcon Samsung SmartThings AC

IP-Symcon Modul zur Steuerung von Samsung Klimaanlagen über die SmartThings REST API.

## Funktionen

- Ein-/Ausschalten
- Solltemperatur setzen
- Isttemperatur anzeigen
- Betriebsmodus setzen
  - Cool
  - Heat
  - Dry
  - Fan Only
  - Auto
- Lüftermodus setzen
  - Auto
  - Low
  - Medium
  - High
  - Turbo
- Optional Mode, sofern vom Gerät unterstützt
  - Off
  - Quiet
  - WindFree
  - Sleep
- zyklisches Polling
- Pending-Logik gegen Rückkopplungen bei träger SmartThings-Cloud
- Status-Dump ins Symcon-Meldungsfenster zur Capability-Analyse
- Rohbefehl-Funktion für Tests weiterer SmartThings-Capabilities

## Warum Pending-Logik?

SmartThings meldet Zustände oft verzögert zurück. Ohne Schutz kann folgendes passieren:

1. Symcon setzt Solltemperatur 21 °C.
2. SmartThings meldet kurz danach noch den alten Wert 22 °C.
3. Symcon schreibt wieder 22 °C zurück.
4. Das Gerät springt zwischen 21 und 22 °C.

Dieses Modul setzt nach jedem eigenen Befehl eine kurze Pending-Sperre pro Variable. Während dieser Zeit werden alte Cloud-Werte ignoriert. Sobald SmartThings den erwarteten Wert zurückmeldet, wird Pending beendet. Läuft Pending ab, wird wieder der echte Cloud-Zustand übernommen.

## Installation in IP-Symcon

1. Repository nach GitHub hochladen.
2. In IP-Symcon unter **Kerninstanzen > Modules** das Repository hinzufügen.
3. Neue Instanz **Samsung SmartThings AC** anlegen.
4. Folgende Werte eintragen:
   - SmartThings Device ID
   - SmartThings Personal Access Token
   - Component, normalerweise `main`
   - Polling-Intervall, z. B. 60 Sekunden
   - Pending-Zeit, z. B. 25 Sekunden
5. **Verbindung testen** klicken.
6. **Jetzt aktualisieren** klicken.

## SmartThings Token

Benötigt wird ein SmartThings Personal Access Token mit Berechtigung zum Lesen und Steuern der Geräte.

Typischerweise benötigt:

- Devices lesen
- Devices steuern

## Capability-Dump

Da Samsung Klimaanlagen je nach Modell unterschiedliche Capabilities verwenden, zuerst in der Instanz auf:

**Status-Dump ins Meldungsfenster schreiben**

klicken. Im Symcon-Meldungsfenster wird dann der JSON-Status des Geräts ausgegeben.

Typische Capabilities:

```text
switch.switch.value
thermostatCoolingSetpoint.coolingSetpoint.value
temperatureMeasurement.temperature.value
airConditionerMode.airConditionerMode.value
airConditionerFanMode.fanMode.value
relativeHumidityMeasurement.humidity.value
custom.airConditionerOptionalMode.acOptionalMode.value
```

## Öffentliche Funktionen

Die öffentlichen Funktionen können aus Skripten aufgerufen werden.

```php
STAC_Refresh($instanceId);
STAC_TestConnection($instanceId);
STAC_DumpStatus($instanceId);

STAC_SetPower($instanceId, true);
STAC_SetTargetTemperature($instanceId, 22.0);
STAC_SetMode($instanceId, 0);      // 0 Cool, 1 Heat, 2 Dry, 3 Fan Only, 4 Auto
STAC_SetFanMode($instanceId, 2);   // 0 Auto, 1 Low, 2 Medium, 3 High, 4 Turbo
STAC_SetOptionalMode($instanceId, 1); // 0 Off, 1 Quiet, 2 WindFree, 3 Sleep
```

Rohbefehl für Tests:

```php
STAC_SendRawCommand(
    $instanceId,
    'airConditionerFanMode',
    'setFanMode',
    '["low"]'
);
```

## Hinweise

- Nicht jedes Samsung-Klimagerät unterstützt alle hier angelegten Capabilities.
- Wenn eine Funktion nicht unterstützt wird, meldet SmartThings üblicherweise einen HTTP-Fehler. Der Fehler wird in der Variable **Letzter Fehler** und im Symcon-Log sichtbar.
- Das Modul ist bewusst cloudbasiert. Die SmartThings-Cloud bleibt träge; das Modul verhindert aber Rückkopplungen und Endlosschleifen.
- Für mehrere Klimaanlagen je Gerät eine eigene Instanz anlegen.

## Repository-Struktur

```text
SymconSamsungSmartThingsAC/
├── library.json
├── README.md
├── CHANGELOG.md
├── .gitignore
└── SamsungSmartThingsAC/
    ├── module.json
    ├── module.php
    └── form.json
```

## Roadmap

- Gateway-/Splitter-Instanz für zentralen Token
- Configurator zur automatischen Gerätesuche
- dynamische Profile anhand tatsächlich unterstützter Modes
- weitere Samsung-Custom-Capabilities, z. B. Swing/WindFree je nach Modell
- optionales Health-Polling
