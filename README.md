# Timetables

`ilbronza/timetables` aggiunge orari ricorrenti a modelli Eloquent e consente di cercare nel database i proprietari attivi in un istante o in una finestra temporale. Supporta regole settimanali, periodi con date di validità, più fasce al giorno, turni oltre mezzanotte, eccezioni per data e fusi orari IANA.

Il pacchetto è un nucleo Laravel senza interfaccia CRUD. Un orario descrive quando un soggetto è attivo; lezioni, prenotazioni, presenze e impegni restano responsabilità dei rispettivi modelli di dominio.

## Requisiti e stato

- PHP 8.2 o successivo, a 64 bit.
- Laravel 11, tramite i componenti Illuminate richiesti dal pacchetto.
- SQLite è verificato con 121 test e 950 asserzioni nella suite del pacchetto. MySQL 8.0.17 o successivo è il database di produzione previsto, ma le prove di integrazione, concorrenza e prestazioni su MySQL sono ancora da eseguire.
- L'esecuzione della suite con Testbench su PHP 8.5 mostra due deprecazioni provenienti dall'ambiente di test; non sono errori del pacchetto.

## Installazione locale durante lo sviluppo

Il pacchetto si trova in `Timetables/` nel repository `pacchetti`. Per usarlo da un'applicazione vicina, aggiungi un repository Composer di tipo `path` e richiedi la versione di sviluppo:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../pacchetti/Timetables",
      "options": { "symlink": true }
    }
  ],
  "require": {
    "ilbronza/timetables": "@dev"
  }
}
```

Adatta `url` alla posizione dell'applicazione. Poi esegui `composer update ilbronza/timetables` e pubblica il file di configurazione prima di applicare le migrazioni:

```bash
php artisan vendor:publish --tag=timetables-config
php artisan migrate
```

Il provider è registrato tramite l'auto-discovery di Laravel. Carica le migrazioni del pacchetto e registra il comando di verifica. Se vuoi copiare le migrazioni nell'applicazione per gestirle lì, usa `php artisan vendor:publish --tag=timetables-migrations`; in quel caso assicurati che la migrazione copiata venga eseguita una sola volta.

## Collegare un modello proprietario

Aggiungi `HasTimetables` al modello che possiede l'orario. Questo esempio usa `App\Models\Company` come nome generico dell'applicazione:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use IlBronza\Timetables\Traits\HasTimetables;

class Company extends Model
{
    use HasTimetables;
}
```

Il modello esistente `IlBronza\Clients\Models\Client` è un'altra possibile classe proprietaria. Per usarlo, aggiungi il trait nel modello applicativo che lo estende, senza modificare il pacchetto `Clients`. Anche un `IlBronza\Operators\Models\Operator` può adottare il trait dall'applicazione.

L'identità polimorfica usa `getMorphClass()`: alias e morph map restano sotto il controllo dell'applicazione. Dopo aver scritto un orario, il relativo tipo deve rimanere stabile o va migrato nei dati.

## Scrivere e aggiornare un orario

Le modifiche dell'aggregato passano da `TimetableWriter`. La prima chiamata crea l'intestazione; una chiamata successiva la sostituisce interamente e deve indicare la revisione letta in precedenza.

```php
use IlBronza\Timetables\Services\TimetableWriter;

$writer = app(TimetableWriter::class);

$timetable = $writer->replace($company, 'opening', [
    'timezone' => 'Europe/Rome',
    'name' => 'Orari di apertura',
    'enabled' => true,
    'periods' => [
        [
            'valid_from' => null,
            'valid_to' => null,
            'weekly' => [
                1 => [[540, 780], [840, 1080]], // lunedì: 09:00–13:00 e 14:00–18:00
                2 => [[540, 780], [840, 1080]],
                3 => [[540, 780], [840, 1080]],
                4 => [[540, 780], [840, 1080]],
                5 => [[540, 780], [840, 1080]],
                6 => [[540, 720]],             // sabato: 09:00–12:00
            ],
            'exceptions' => [
                '2026-12-24' => [[540, 780]], // sostituisce il giovedì ordinario
                '2026-12-25' => [],          // chiusura completa
            ],
        ],
    ],
]);

// Una sostituzione completa successiva richiede la revisione corrente.
$timetable = $writer->replace($company, 'opening', $newDefinition, expectedRevision: $timetable->revision);
```

Le chiavi di `weekly` sono giorni ISO: 1 è lunedì e 7 domenica. Ogni fascia è una coppia di minuti interi `[inizio, fine]` rispetto alla mezzanotte del giorno di origine: 09:00–13:00 è `[540, 780]`; una fascia 22:00–02:00 è `[1320, 1560]`. L'inizio deve essere fra 0 e 1439, la fine deve essere successiva e la durata non può superare 24 ore. Sono ammesse fino a 32 fasce per giorno per impostazione iniziale; le fasce duplicate vengono eliminate e le altre non vengono fuse.

`valid_from` e `valid_to` sono date `YYYY-MM-DD`, incluse entrambe; `null` significa senza limite. I periodi della stessa intestazione non possono sovrapporsi. Le date in `exceptions` devono ricadere nel periodo. Un'eccezione vuota chiude la data intera, mentre la sua rimozione ripristina la regola settimanale.

La sostituzione ricrea i periodi, i giorni e le fasce figlie: gli identificativi delle righe figlie non sono stabili. La revisione protegge l'aggiornamento concorrente; se manca o è superata, il writer lancia `TimetableConflict`. La creazione concorrente della stessa finalità è protetta dal vincolo univoco. Il writer verifica il modello proprietario e opera sulla sua connessione.

Le altre operazioni richiedono anch'esse la revisione corrente:

```php
$timetable = $writer->setEnabled($company, 'opening', false, $timetable->revision);
$timetable = $writer->delete($company, 'opening', $timetable->revision); // cancellazione logica
$timetable = $writer->restore($company, 'opening', $timetable->revision);
$writer->forceDelete($company, 'opening', $timetable->revision); // elimina anche i figli
```

Ogni mutazione incrementa la revisione. Recupera l'intestazione aggiornata prima di invocare l'operazione successiva.

## Leggere un orario

`$company->timetable($kind)` restituisce un lettore legato al proprietario e alla finalità. Se manca un'intestazione abilitata o un periodo applicabile, `stateAt()` restituisce `TimetableState::Unconfigured`; se esiste una configurazione ma l'istante è fuori fascia, restituisce `Inactive`.

```php
use IlBronza\Timetables\Domain\CivilDate;
use IlBronza\Timetables\Domain\TimetableState;

$opening = $company->timetable('opening');

$state = $opening->stateAt('2026-10-12T10:30:00+02:00');
$isOpen = $opening->isActiveAt($instant);
$utcIntervals = $opening->intervalsOn(CivilDate::from('2026-10-12'));
$isContinuouslyCovered = $opening->covers($from, $to);

if ($state === TimetableState::Active) {
    // Il soggetto è attivo in quell'istante.
}
```

`intervalsOn()` riceve una data civile nel fuso dell'orario e restituisce intervalli assoluti in UTC. `covers()` verifica che l'unione degli intervalli copra tutta la finestra richiesta, senza interruzioni.

## Cercare i proprietari nel database

Il trait aggiunge gli scope `whereTimetableActiveAt()` e `whereTimetableOverlaps()` al builder Eloquent del proprietario:

```php
$activeCompanies = Company::query()
    ->where('tenant_id', $tenantId)
    ->whereTimetableActiveAt('2026-10-12T10:30:00+02:00', 'opening')
    ->count();

$operators = Operator::query()
    ->whereTimetableOverlaps($from, $to, kind: 'working')
    ->orderBy('id')
    ->paginate();
```

La ricerca per sovrapposizione significa che esiste almeno un istante attivo nell'intervallo semiaperto `[from, to)`. Non richiede copertura continua: per quella condizione usa `covers()` sul singolo orario. Gli scope usano `EXISTS` nella query SQL del proprietario, quindi si combinano con i filtri tenant, `count()`, ordinamento e paginazione senza duplicare i risultati. Le finestre temporali devono essere valide e non invertite.

Il limite iniziale per una singola ricerca è 31 giorni, 64 finestre locali e 900 parametri, contando anche quelli già presenti nel builder. Sono configurabili sotto `timetables.query`. Se il limite viene superato, la query lancia `QueryTooBroad` senza tagliare i risultati. Eventuali filtri aggiunti successivamente rimangono soggetti ai limiti del driver. L'origine del builder deve essere una tabella nominata; se usi un alias, anche gli scope globali del proprietario devono riferirsi correttamente all'alias.

## Date, fusi e transizioni dell'ora

Le date di validità e le fasce sono orari civili nel fuso memorizzato sull'intestazione. Le API che ricevono un istante accettano `DateTimeInterface` (incluso Carbon) oppure una stringa ISO con offset esplicito, ad esempio `2026-10-12T10:30:00+02:00` o `2026-10-12T08:30:00Z`. Una stringa locale senza offset è rifiutata. Gli istanti mantengono precisione al microsecondo; la configurazione delle fasce ha precisione al minuto.

Al cambio dell'ora, i minuti locali inesistenti in primavera non producono tempo attivo; quelli ripetuti in autunno sono attivi entrambe le volte. Una fascia 02:15–02:45 a Roma il giorno del ritorno all'ora solare, per esempio, genera due intervalli UTC distinti. Le fasce sono semiaperte `[inizio, fine)`: un istante esattamente uguale alla fine è inattivo.

Una regola notturna conserva la coda sul giorno d'origine. Il giorno seguente eredita quella coda, salvo che abbia un'eccezione: l'eccezione sostituisce la data intera e sopprime anche la coda di ieri. Le code vengono inoltre tagliate al confine del periodo e non attraversano periodi o giorni non configurati.

## Configurazione e migrazioni

Il file `config/timetables.php` contiene:

- classi e tabelle dei quattro modelli sotto `models.{timetable,period,day,interval}.class` e `.table`;
- l'elenco `timezones`, inizializzato con `app.timezone`;
- `max_intervals_per_day` e i limiti della ricerca sotto `query`;
- `validation_connections`, elenco di connessioni aggiuntive da controllare.

Configura tutti i fusi usati dagli orari. Se il fuso dell'applicazione non è sufficiente, pubblica il file e aggiungi i fusi necessari. Imposta i nomi delle tabelle prima della prima migrazione; cambiare la configurazione dopo l'installazione richiede anche una migrazione esplicita di rinomina delle tabelle esistenti. Le classi personalizzate devono estendere i modelli del pacchetto e conservarne relazioni e invarianti. Tutte le tabelle dell'aggregato devono essere disponibili sulla connessione del proprietario. Su SQLite devono essere attivi i vincoli di chiave esterna (`foreign_key_constraints` nella connessione Laravel).

Dopo un cambio dell'elenco dei fusi, controlla ogni connessione che ospita dati, comprese le intestazioni disabilitate o cancellate logicamente:

```bash
php artisan timetables:validate
php artisan timetables:validate --connection=main --connection=tenant
```

Senza opzioni il comando controlla `validation_connections`; se l'elenco è vuoto, controlla la connessione predefinita. Le intestazioni che usano un fuso non più ammesso devono essere aggiornate prima del rilascio della nuova configurazione.

## Cancellazione del proprietario

La cancellazione logica del proprietario conserva gli orari. La cancellazione fisica attiva il cleanup dei relativi orari e figli tramite il trait. Se la cancellazione deve essere atomica rispetto alle scritture concorrenti, usa il metodo lifecycle del trait:

```php
$company->deleteWithTimetables(force: true);
```

Il metodo apre una transazione e acquisisce il lock del proprietario prima della pulizia. Se un evento Eloquent annulla la cancellazione, la transazione ripristina anche gli orari e il metodo restituisce `false`. Usa questo metodo per rendere atomico il lifecycle; una cancellazione diretta fuori transazione non offre la stessa garanzia. Le cancellazioni SQL dirette aggirano gli eventi Eloquent e richiedono pulizia esplicita con `TimetableOwnerLifecycle::cleanup($owner)` su un'istanza che conservi identità e connessione del proprietario.

## Test e verifiche

Nel repository `pacchetti`, esegui la suite con l'ambiente già pronto:

```bash
python3 .ibtest/bin/ibtest.py Timetables
```

Per indicare un'installazione PHP-WASM già presente, imposta `IBPHPDIR`:

```bash
IBPHPDIR=/Volumes/Lavoro/pacchetti/.ibtest/phpdir python3 .ibtest/bin/ibtest.py Timetables
```

Il secondo percorso è quello utilizzato in questa sessione: il runtime predefinito in `/tmp/ibphp` aveva una dipendenza Node mancante. Con dipendenze Composer installate localmente, il comando della suite è `composer test` (PHPUnit 11 richiede PHP 8.3 o successivo per lo sviluppo). Le verifiche attuali coprono SQLite; prima del rilascio restano da misurare query e concorrenza su MySQL 8.0.17 o successivo.

Per le decisioni temporali, lo schema, i limiti e i dettagli dell'implementazione consulta [l'architettura](docs/architecture.md) e il [prototipo SQL SQLite](docs/prototypes/verify_queries.py). Il motore implementa le regole specificate qui e non copia codice da Spatie.
