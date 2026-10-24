# Przejście z oryginalnego CtrlPanel na ten fork

Fork jest zgodny ze schematem bazy CtrlPanela 1.2.x. Nie dokłada żadnej
zależności Composera ani npm, nie wymaga nowych zmiennych w `.env` i nie
zmienia istniejących tabel — dopisuje tylko własne oraz kilka kolumn
dopuszczających `NULL`. Dzięki temu przeniesienie sprowadza się do podmiany
plików i uruchomienia migracji.

## Zanim zaczniesz

* **Baza musi być MariaDB** (10.2+). Pięć migracji CtrlPanela
  (`2026_04_10_*change_*_to_string`) używa składni `DROP FOREIGN KEY IF EXISTS`,
  której MySQL nie zna. Dotyczy to również oryginalnego CtrlPanela — na MySQL 8
  te migracje wywalą się tak samo. Jeśli siedzisz na MySQL, trzeba je wykonać
  ręcznie w SQL-u albo przejść na MariaDB.
* PHP 8.2, 8.3 lub 8.4 z tymi samymi rozszerzeniami co dotychczas.
* Zrób kopię bazy i katalogu. Bez tego ani kroku dalej.

```bash
mysqldump -u USER -p NAZWA_BAZY > ~/ctrlpanel-backup-$(date +%F).sql
tar czf ~/ctrlpanel-files-$(date +%F).tar.gz /var/www/ctrlpanel
```

## Przeniesienie

1. **Wyłącz ruch na czas aktualizacji.**

   ```bash
   cd /var/www/ctrlpanel
   php artisan down
   ```

2. **Podmień pliki.** Najprościej sklonować forka obok i przenieść do niego
   stan instalacji, zamiast nadpisywać działający katalog.

   ```bash
   git clone https://github.com/Basket5125/panel-truehosting.git /var/www/panel-new
   cd /var/www/panel-new
   ```

3. **Przenieś `.env` bez zmian.** Żadna nowa zmienna nie jest potrzebna —
   ustawienia panelu gier i pakietów trzymane są w bazie, nie w `.env`.

   ```bash
   cp /var/www/ctrlpanel/.env /var/www/panel-new/.env
   ```

4. **Przenieś pliki wgrane przez użytkowników** (logo, favicon, awatary):

   ```bash
   cp -a /var/www/ctrlpanel/storage/app/public/. /var/www/panel-new/storage/app/public/
   ```

5. **Zainstaluj zależności i podlinkuj storage.**

   ```bash
   composer install --no-dev --optimize-autoloader
   php artisan storage:link
   ```

6. **Uruchom migracje.** Dołożą cztery tabele i kilka kolumn `NULL`-owalnych.
   Istniejące dane zostają nietknięte.

   ```bash
   php artisan migrate --force
   ```

7. **Uprawnienia i przełączenie katalogu.**

   ```bash
   chown -R www-data:www-data /var/www/panel-new
   chmod -R 755 /var/www/panel-new/storage /var/www/panel-new/bootstrap/cache
   mv /var/www/ctrlpanel /var/www/ctrlpanel-old
   mv /var/www/panel-new /var/www/ctrlpanel
   ```

8. **Wyczyść cache i wróć online.**

   ```bash
   cd /var/www/ctrlpanel
   php artisan config:clear && php artisan view:clear && php artisan cache:clear
   php artisan up
   ```

Na tym etapie panel działa dokładnie jak wcześniej: stary motyw, Pterodactyl
jako panel gier, rozliczanie z kredytów. Nic się nie zmieniło poza tym, że
dostępne są nowe funkcje.

## Włączanie nowych funkcji

Każdą z nich włączasz osobno i każdą można wyłączyć z powrotem.

### Motyw TrueHosting

Ustawienia → Ogólne → **Theme** → `TrueHosting`. Powrót do `default` przywraca
poprzedni wygląd.

### FeatherPanel zamiast Pterodactyla

1. Ustawienia → **FeatherPanel**: wpisz adres panelu i klucz API konta
   administracyjnego (potrzebuje węzłów uprawnień do users, servers, nodes,
   locations, realms, spells i allocations).
2. Zostaw na razie **Game panel: Pterodactyl**.
3. Przemapuj identyfikatory, póki API Pterodactyla jeszcze działa — komenda
   najpierw pokazuje, co by zmieniła:

   ```bash
   php artisan cp:featherpanel:map          # podgląd, nic nie zapisuje
   php artisan cp:featherpanel:map --write  # zapis
   ```

   Użytkownicy dopasowywani są po adresie e-mail, serwery po `external_id`.
   Niedopasowane rekordy komenda wypisuje zamiast zgadywać.
4. Dopiero teraz przestaw **Game panel** na `FeatherPanel`.
5. Zsynchronizuj katalog: Przegląd → przycisk synchronizacji. Powinny zaciągnąć
   się lokalizacje, węzły, realmy i spelle.

Przełącznik działa w obie strony — jeśli coś nie zagra, wracasz na Pterodactyla
jednym ustawieniem.

### Pakiety konfigurowalne

Administracja → **Pakiety konfigurowalne** → *Utwórz pakiet*. Ustawiasz cenę za
jednostkę, zakresy do wyboru, rabat ilościowy, węzły z priorytetem i próg
dużych zamówień. Zakładka „Zbuduj serwer" pojawia się klientom dopiero po
opublikowaniu pierwszego pakietu.

Jeśli chcesz płatność pieniędzmi zamiast kredytów, w sekcji *Sposób płatności*
ustaw **Kwota za 1 kredyt** i walutę. Cena w walucie wyliczana jest z ceny w
kredytach przez ten przelicznik.

## Wycofanie zmian

Przywrócenie poprzedniej wersji to podmiana katalogu z powrotem i cofnięcie
migracji:

```bash
cd /var/www/ctrlpanel
php artisan migrate:rollback --step=4
```

Cofnięcie usuwa tabele `custom_plans`, `custom_plan_node`, `custom_plan_egg`
i `custom_orders` oraz dodane kolumny. Dane CtrlPanela pozostają nienaruszone,
bo żadna migracja ich nie modyfikuje. Jeśli zdążyłeś już sprzedać serwery z
pakietu konfigurowalnego, najpierw zrób kopię tych tabel — razem z nimi
znikną informacje o konfiguracji i opłaconych okresach.
