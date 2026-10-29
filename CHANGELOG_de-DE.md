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
