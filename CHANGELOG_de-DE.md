# 1.3.1
- Neue Karte "Warenkorb" im Printess-Tab, für Produkte, deren Anzahl im Template gewählt wird (z. B. eine Anzahl Fliesen, die auf eine Variante abgebildet ist):
  - "Mengenauswahl ausblenden" blendet die Mengenauswahl auf der Produktseite sowie die Menge im Warenkorb, Offcanvas-Warenkorb, Checkout und in den Bestellungen aus und hält die Warenkorbposition bei Menge 1.
  - "Formularfeldwerte anzeigen" listet Formularfelder, deren Werte unter der Position angezeigt werden, z. B. "Anzahl Fliesen: 10", mit einer Bezeichnung pro Sprache. Das Formularfeld wird aus den Formularfeldern des Templates gewählt und muss als preisrelevant (Price Relevant) markiert sein; die Template-Prüfung warnt bei Feldern, die das nicht sind oder die das Template nicht hat. Die Werte folgen jeder Änderung: beim Bearbeiten im Warenkorb, beim erneuten Bestellen und beim Bearbeiten des Designs einer Bestellposition in der Administration.
- Beim Bearbeiten eines SlimUi-Warenkorbartikels oder beim erneuten Bestellen gehen die Werte preisrelevanter Formularfelder, die im Editor nicht geändert wurden, nicht mehr verloren; ihre Preisaufschläge bleiben erhalten.
- Die Printess-Zusatzfelder werden im Tab "Spezifikationen" eines Produkts in der Administration wieder ausgeblendet (seit Shopware 6.7 wurden sie dort zusätzlich zum Printess-Tab angezeigt). Sie werden nur im Printess-Tab verwaltet.

# 1.3.0
- Neue Einstellung "PDF-Dokumenteigenschaften": Titel, Thema und Autor der produzierten PDFs (übergeben als Formularfelder PDF_Title, PDF_Subject und PDF_Author) lassen sich in den Plugin-Einstellungen festlegen und pro Produkt im Printess-Tab überschreiben. Die Texte können Platzhalter für Bestell-, Positions-, Produkt-, Variantenoptions-, Kunden- und Lieferdaten enthalten, die beim Start der Produktion ausgefüllt werden. Der Editor erklärt die Schreibweise, fügt Platzhalter aus einer durchsuchbaren Liste ein und zeigt eine Vorschau. Das Template braucht Formularfelder mit genau diesen Namen.
- Behoben: Ab Shopware 6.7.16 ließ sich das Theme nicht mehr kompilieren, weil die config.xml des Plugins einen Kommentar enthielt.

# 1.2.8
- Behoben: Die Installation oder Neuinstallation des Plugins schlug mit einem Fehler wegen doppelter Einträge fehl, wenn noch Zusatzfelder einer früheren Installation in der Datenbank vorhanden waren. Bestehende Printess-Zusatzfelder, -Zusatzfeld-Sets und deren Zuordnungen werden jetzt weiterverwendet.

# 1.2.7
- Abgeschlossene Printess-Artikel in der Bestellung haben jetzt die Schaltfläche "Formularfeldwerte anzeigen". Sie listet alle Formularfelder der Vorlage mit dem Wert, mit dem das Design produziert wurde, sowie die vom Kunden hochgeladenen Bilder. Die Werte werden nach Abschluss der Produktion mit der Bestellung gespeichert, da Printess die Datei nur 30 Tage aufbewahrt. Bei früher produzierten Artikeln werden sie beim ersten Anzeigen von Printess geladen.

# 1.2.6
- SlimUi: Vorschau-Dokumente, die durch ihre Dokumentbedingung ausgeblendet sind, werden nicht mehr in der Produktgalerie angezeigt. Die Galerie zeigt nur die sichtbaren Seiten, ein Bild, dessen Seite ausgeblendet wird, zeigt wieder das Produktfoto, und die Galerie wechselt mit, wenn die SlimUi auf eine andere Seite springt.
- Die konfigurierten Printess-API- und Editor-URLs werden jetzt vom vollen Editor und der SlimUi im Storefront verwendet; bisher wurden immer die Standard-Domains von Printess genutzt.

# 1.2.5
- Behoben: Die SlimUi hat die Standardvariante des Produkts in den Warenkorb gelegt statt der im Editor gewählten Variante.
- Der volle Editor legt nicht mehr die zuvor gewählte Variante in den Warenkorb, wenn "In den Warenkorb" geklickt wird, während ein Variantenwechsel noch lädt oder fehlgeschlagen ist.
- Die Liste der Produktdefinitionen in der Dropshipping-Einstellung wird nicht mehr von der umgebenden Karte abgeschnitten und öffnet sich nach oben, wenn darunter nicht genug Platz ist.
- Beim Bearbeiten eines Warenkorbartikels im vollen Editor wird jetzt die Variante gespeichert, deren Preis der Editor anzeigt.

# 1.2.4
- Produktionsaufträge enthalten jetzt die Bestellnummer des Shops als externe Bestell-ID, wodurch sich Bestellungen in Printess und beim Dropshipper leichter wiederfinden lassen.
- Bestell-, Kunden- und Adressdaten werden an die Formularfelder des Templates übergeben (Bestellnummer, Bestelldatum, Menge, Produktname und -nummer, Liefer- und Rechnungsadresse, Versandart, Kundenname und E-Mail-Adresse) – mit denselben Feldnamen wie im bisherigen Printess-Plugin.

# 1.2.3
- Dropshipping-Artikel einer Bestellung, die an dieselbe Adresse gehen, werden jetzt als eine Dropshipping-Bestellung produziert und dadurch gemeinsam versendet.
- Das Freigeben oder der Neustart eines Dropshipping-Artikels umfasst jetzt alle Artikel seiner Sendung – nach einem Dialog, der jedes Design mit Vorschau zeigt und zum Bearbeiten im Editor öffnen lässt.
- Vom Dropshipper gemeldete Sendungen werden in die Bestellung zurückgeschrieben: Die Lieferung wird auf versendet bzw. teilweise versendet gesetzt, Sendungsnummer und Link erscheinen in der Administration und im Kundenkonto.
- Erneut produzierte Artikel werden unter der Bestellnummer mit einem Zusatz gesendet – für Dropshipper, die eine bereits erhaltene Bestellnummer ablehnen.
- Alternativ kann für eine erneute Produktion die Bestellung dupliziert werden: Die Kopie behält ihre Preise, ist für den Kunden durch eine Rabattzeile kostenlos und enthält eine Nachricht, warum sie angelegt wurde.
- Ein Produkt mit „Kein Dropshipping“ wird nicht mehr per Dropshipping produziert, wenn in den Plugin-Einstellungen ein Standard konfiguriert ist. Die neue Option „Plugin-Standard verwenden“ übernimmt den Standard.
- „Dropshipping mit Template-Einstellungen verwenden“ kommt jetzt korrekt bei Printess an; solche Produkte wurden zuvor nicht per Dropshipping produziert.
- Neue Einstellung für eine öffentliche Shop-URL, damit Printess einen Shop erreichen kann, der nur über einen Tunnel verfügbar ist.
- Auf der Bestellbestätigung und der Abschlussseite der Bestellung wird kein „Design bearbeiten“ mehr angezeigt.
- Die Bestellansicht weist jetzt darauf hin, wenn die Produktion auf die Zahlung wartet, und bietet einen Neustart erst an, nachdem ein Artikel in Produktion war.

# 1.2.0
- Neue Karte „Template-Prüfung“ im Printess-Tab des Produkts: Sie vergleicht die Varianten-Eigenschaften des Produkts mit den Formularfeldern des Templates und meldet, was fehlt.
- Varianten-Eigenschaften und Varianten lassen sich aus den Formularfeldern des Templates anlegen.

# 1.1.5
- Fortschrittsanzeige korrigiert, die beim Bearbeiten von Texten über der SlimUi stehen blieb.

# 1.1.4
- SlimUi bei Produkten mit mehreren Vorschaubildern korrigiert.

# 1.1.0
- Unterstützung für die SlimUi ergänzt, die kompakte Käufer-Oberfläche für einfache Personalisierungen.

# 1.0.0
- Vollständige Neuentwicklung des Printess-Shopware-Plugins für Shopware 6.7.
