# Analýza a modernizace icalparser

Analýza větve `main`, implementace první etapy ve větvi `next`, 16. 9. 2026.

## Výchozí stav

Knihovna již vyžaduje PHP `^8.4`, používá Composer, PSR-4 a Nette Tester.
Produkční závislosti kromě PHP nemá. Samotné zvýšení minimální verze PHP tedy
není potřebné. Výchozích 9 testovacích souborů prošlo na lokálním PHP 8.5.10.
Nebylo provedeno měření výkonu ani úplný audit shody se specifikací.

`IcalParser` současně načítá vstup, rozebírá řádky, uchovává stav, řeší pásma,
expanduje opakování a slučuje modifikované instance. `Freq` počítá pomocí
globálního časového pásma a rekurze. `Recurrence` duplikuje pravidla mezi polem
a sadou převážně netypovaných hodnot. Tyto vazby komplikují samostatné testování.

## Provedená první etapa

| Oblast | Nález | Změna ve větvi `next` |
| --- | --- | --- |
| Načtení souboru | `fopen()` a následné `file_get_contents()` otevíraly zdroj dvakrát, včetně URL | Jediné načtení; neúspěch převeden na `RuntimeException` |
| Stav komponent | Po `END:VALARM` se další vlastnosti ukládaly do alarmu; obdobně po konci události | Zásobník nadřazených sekcí při zachování plochého veřejného výstupu |
| Řádky a callback | Chybělo rozbalení tabulátoru, prázdné řádky vytvářely neplatné klíče, callback četl neexistující čítač | Podpora mezery i tabulátoru, přeskočení neplatných řádků, definovaný čítač a přímé volání callbacku |
| Chybné vstupy | Kontrola formátu probíhala až po smazání předchozího výsledku | Vstup bez `BEGIN:VCALENDAR` je odmítnut před resetem dat |
| Organizátor | `ORGANIZER` bez parametrů vyvolával varování při iteraci | Iterace pouze existujících parametrů |
| Globální pásmo | Výjimka ve `Freq` přeskočila obnovení pásma procesu | `try/finally` omezené na samotný výpočet |
| Opakování | `array|string` slibovalo nefunkční textový vstup; přímé použití bez `UNTIL` četlo neexistující klíč | Rozklad textových pravidel, bezpečný přístup a podpora `DateTimeInterface` pro `UNTIL` |
| Validace | Neplatná frekvence nebo nekladný interval vstupovaly do výpočtu | Včasné výjimky; `COUNT` a `INTERVAL` musí být kladná celá čísla |
| Vlastnosti objektů | Neznámé části RRULE vytvářely dynamické vlastnosti | Rozšíření zůstávají v poli `rrule`, ale nevytvářejí vlastnosti objektu |
| Typy a čitelnost | Chybějící typy u interního parseru a vstupu opakování, obrácený popis řazení | Doplněné typy, `strict_types` v `ParserOptions`, porovnání `<=>` a opravené komentáře |
| CI | Instalace ignorovala všechny požadavky platformy | Instalace kontroluje platformu a Composer validace je striktní |

Změny doprovází 9 nových regresních scénářů ve dvou testovacích souborech.
Zůstávají veřejná pole, pole událostí, mutabilní `DateTime`, existující gettery
i původní výchozí horizont opakování.

Záměrné změny chování: callback již neobdrží `BEGIN:VCALENDAR` ani prázdné či
nerozpoznané řádky; ve výsledku nevzniká pomocný klíč `BEGIN` ani klíč z prázdného
řádku. Neplatná pravidla nyní vyvolají výjimku. `SECONDLY` není implementováno
a je výslovně odmítnuto. To je potřeba uvést při vydání verze `next`.

## Navazující implementace: množina výskytů a omezení výpočtu

Ve větvi `next` jsou dále provedeny tyto změny:

- Výjimky jsou indexované jako `_RECURRENCE_IDS[UID][RECURRENCE-ID]`.
  UTC i lokální identifikátor ve stejném pásmu jako série nahradí pouze výskyt
  příslušné série. Tvar tohoto pomocného pole ve veřejných datech se změnil.
- `RDATE` funguje bez `RRULE`; sjednocení a odstranění duplicit následuje až
  po výpočtu RRULE a filtrování týdenního intervalu. `EXDATE` má poslední slovo
  a odstraňuje i explicitní přidané datum. `COUNT` počítá výskyty před vyloučením.
- Prázdná série má `RECURRENCES: []` a nevrátí původní událost. Každá instance
  dostává skutečné datum výskytu a odpovídající konec, včetně prvního výskytu.
- `getEvents()` už nemění data parseru. Odpadlo slučování první instance,
  které mohlo přepsat jiný platný termín. Modifikované události jsou vráceny
  s jejich vlastními poli; chybějící metadata se z hlavní události nedoplňují.
- Cache rozlišuje nevypočtený stav a prázdné pole. Timestamp nula je platný;
  dotazy na první, poslední, další a předchozí výskyt respektují hotovou cache.
  `lastOccurrence()` nově vrací `false` pro prázdnou sérii.
- `Freq` má nastavitelný limit `maxOccurrences` (výchozí 100 000). Omezuje
  velikost expanze a počet kroků hledání dalšího výskytu; rekurzivní hledání má
  navíc strop 256 zanoření. Překročení limitu nebo nepostupující čas vyvolá
  `RuntimeException`. Nejde o celkový limit velikosti kalendáře.
- Číselná porovnání BYMONTH, BYMONTHDAY, BYWEEKNO a BYHOUR používají čísla.
  Testy pokrývají nulové prefixy, kombinaci filtrů a poslední den měsíce.
- Neimplementované BYSETPOS a BYSECOND i neplatné UNTIL jsou explicitně odmítnuty.

Přidáno 11 scénářů v `tests/recurrence.set.phpt`. Původní test velké série nyní
používá pevný horizont a úplný seznam očekávaných termínů. Opravená očekávání
zachovávají zároveň běžný výskyt 6. 11. 2012 v 10:00 a událost přesunutou
z 5. 11. na 6. 11. ve 20:00; stará implementace běžný výskyt ztrácela.

## Plán etap a zbývající práce

Následující seznam zachovává původní nálezy. První čtyři body etapy 1 jsou
implementované výše, číselná porovnání a explicitní odmítnutí dvou chybějících
pravidel také. Celá etapa 1 však ještě není uzavřena: zbývá ověřit a vymezit
podporované kombinace BYxxx, neexistující lokální časy, neplatné dny měsíce,
týdenní interval s COUNT a více BYDAY, identifikátory výjimek v odlišném TZID
a výběr mezi více revizemi stejné výjimky podle SEQUENCE. Strop rekurze může
odmítnout i platné řídké pravidlo; odstranění rekurze je navazující změna.
Z etapy 2 je již hotová neměnnost interních dat při `getEvents()`.

### 1. Správnost opakování a omezení práce — nejvyšší priorita

- `_RECURRENCE_IDS` indexuje pouze datum, nikoli dvojici UID a datum. Výjimka
  jedné události tak může ovlivnit jinou událost ve stejný čas. Oddělit indexy
  podle UID; ověřit dvě souběžné série i výjimky s UTC a lokálním časem.
- `parseRecurrences()` vyžaduje `RRULE`, přesto je volána také pro samotné `RDATE`.
  Oddělit sjednocení DTSTART/RDATE/EXDATE od expanze pravidla.
- `getEvents()` při první expandované instanci ponechává původní DTSTART.
  Ověřit vyloučení prvního výskytu, RDATE před začátkem a úplně prázdnou sérii.
- `Freq` používá `empty($cache)` jako indikátor neprovedeného výpočtu; prázdný
  výsledek se nerozlišuje od chybějící cache. Zavést explicitní stav výpočtu,
  kontrolu postupu času a rozpočet počtu instancí. Ověřit i timestamp nula.
- `validDate()` porovnává nulami doplněné hodnoty z `date()` s texty BYxxx
  striktně; například `01` a `1` nejsou totožné. Normalizovat číselná pravidla
  a doplnit testy BYMONTH, BYMONTHDAY včetně záporných hodnot a kombinací pravidel.
- `BYSETPOS` nemá implementaci, další části pravidel nejsou plně pokryté.
  Definovat podporovaný rozsah; nepodporované kombinace explicitně odmítat.

Podmínkou dokončení této etapy je sada pevných očekávaných dat včetně přechodů
letního času, COUNT/UNTIL, modifikací první instance a prázdných výsledků.
Nejprve vytvořit charakterizační testy, potom měnit algoritmus.

### 2. Oddělení parseru, konfigurace a dat

- Vyčlenit rozbor content line od převodu hodnot. Současné regulární výrazy
  nejsou úplným tokenizerem parametrů v uvozovkách. Kategorie se navíc
  od-escapují před rozdělením, takže escapovaná čárka může změnit počet kategorií.
- `ParserOptions` není zapojena do konstruktoru parseru. Zapojit ji až se
  stanovenou sémantikou časového okna a testovatelným zdrojem aktuálního času.
  Posunutí DTSTART nesmí změnit fázi opakování; pro omezení historie preferovat
  filtrování výskytů. Nynější `shiftEventDates` nemá v parseru účinek.
- Definovat, jak se resetuje pásmo mezi dvěma kalendáři na stejné instanci,
  včetně uživatelem nastaveného veřejného `$timezone` a režimu `add`.
- Nabídnout typovaný výsledek s `DateTimeImmutable` a rozhraní generující výskyty
  v časovém okně. Zachovat adapter na stávající pole; neměnit potichu jejich typy.
- Zajistit, aby opakované `getEvents()` neměnilo interní data při slučování
  modifikací. Výsledek má být opakovatelný bez závislosti na pořadí volání.

### 3. Nástroje a údržba

- Zavést statickou analýzu, nejprve typové popisy polí a postupné zpřísňování.
  Konkrétní nástroj a verzi zvolit při implementaci podle podpory cílových PHP.
- Testy odvodit od pevného času místo výpočtů vůči `now`; výkon měřit na velkém
  kalendáři a starých denních sériích. Podle výsledků rozhodnout o streamování.
- Opravit generátor `bin/timezones.php`: generuje přiřazení proměnné, ale parser
  očekává návratovou hodnotu z `require`. Přidat validaci stažení a výsledku
  před přepsáním mapy; zdroj mapy verzovat kvůli reprodukovatelnosti.
- V příkladu escapovat hodnoty kalendáře před vložením do HTML.
- Před případnou náhradou recurrence enginu zkontrolovat původ a licenční
  hlavičky převzatých souborů; analýza zde neposuzuje licenční slučitelnost.

## Ověření a zdroje

Lokálně: PHP 8.5.10, `composer test` (12 testovacích souborů),
`composer validate --strict`, kontrola syntaxe změněných PHP souborů a
`git diff --check`. PHP 8.4 ani ostatní verze z CI nebyly lokálně spuštěny.
Závislosti ani lockfile nebyly aktualizovány.

Rozbalování řádků a návrhy rozboru vycházejí z
[RFC 5545, §3.1](https://www.rfc-editor.org/rfc/rfc5545#section-3.1),
návrhy opakování z [§3.3.10](https://www.rfc-editor.org/rfc/rfc5545#section-3.3.10).
Omezení dynamických vlastností odpovídá
[dokumentaci PHP](https://www.php.net/manual/en/language.oop5.properties.php#language.oop5.properties.dynamic-properties).
