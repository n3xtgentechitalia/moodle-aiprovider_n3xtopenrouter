# OpenRouter AI Provider per Moodle

[![CI](https://github.com/n3xtgentech/moodle-aiprovider_n3xtopenrouter/actions/workflows/ci.yml/badge.svg)](https://github.com/n3xtgentech/moodle-aiprovider_n3xtopenrouter/actions/workflows/ci.yml)

Componente Moodle: **`aiprovider_n3xtopenrouter`** · Cartella di installazione: **`ai/provider/n3xtopenrouter`**

> 🇮🇹 Documentazione italiana qui sotto · 🇬🇧 [English documentation below](#-english)

---

# 🇮🇹 Italiano

## Cos'è

Collega Moodle a [OpenRouter](https://openrouter.ai) e abilita tre azioni del
sottosistema IA di Moodle: **Genera testo**, **Riassumi testo** e **Genera immagine**.

Con una sola chiave API il sito accede a centinaia di modelli — Anthropic, Google,
OpenAI, Meta, Mistral, DeepSeek, Qwen per il testo; Google, OpenAI, Black Forest
Labs, Recraft, Qwen e altri per le immagini — scegliendoli da un elenco letto in
tempo reale dal catalogo di OpenRouter.

## Requisiti

| Requisito | Note |
|---|---|
| Moodle **5.0** o superiore | Serve il sottosistema IA, introdotto in 4.5 e per-istanza in 5.0 |
| PHP **8.2+** | Come richiesto da Moodle 5.0 |
| Estensione **GD** | Moodle applica una filigrana alle immagini generate |
| Accesso HTTPS in uscita verso `openrouter.ai` | Chiamate API e cataloghi modelli |
| Una chiave API OpenRouter | <https://openrouter.ai/keys> |

## Installazione

### Metodo 1 — dallo ZIP tramite interfaccia web (consigliato)

È il modo che userà la maggior parte delle persone, e non richiede accesso alla shell.

**1. Procurati il pacchetto ZIP corretto.**

Scarica l'allegato **`n3xtopenrouter-vX.Y.Z.zip`** dalla pagina
[Releases](https://github.com/n3xtgentech/moodle-aiprovider_n3xtopenrouter/releases).

> ⚠️ **Non usare il link "Source code (zip)" di GitHub.** Quel file ha come cartella
> radice `moodle-aiprovider_n3xtopenrouter-2.0.0`, mentre Moodle pretende che si
> chiami esattamente `n3xtopenrouter`. Il validatore lo rifiuta con l'errore
> *"Il nome del componente non corrisponde alla cartella"*. L'allegato della release
> è costruito con la cartella giusta.

Se preferisci costruirlo tu dal codice sorgente:

```bash
git clone https://github.com/n3xtgentech/moodle-aiprovider_n3xtopenrouter.git
cd moodle-aiprovider_n3xtopenrouter
git archive --format=zip --prefix=n3xtopenrouter/ -o n3xtopenrouter-v2.0.0.zip HEAD
```

**2. Carica lo ZIP in Moodle.**

1. Accedi come amministratore
2. *Amministrazione del sito → Plugin → Installa plugin*
3. Trascina lo ZIP nel campo **Pacchetto ZIP del plugin**
4. Il tipo di plugin viene riconosciuto da solo: lascia **Rileva automaticamente**
5. Clic su **Installa plugin dal file ZIP**

**3. Leggi il rapporto di validazione.** Moodle mostra i controlli che ha eseguito:

```
info     rootdir            n3xtopenrouter
info     componentmatch     aiprovider_n3xtopenrouter
info     pluginversion      2026081402
info     requiresmoodle     2025041400
info     maturity           MATURITY_STABLE
info     release            v2.0.0
info     pathwritable       /percorso/moodle/ai/provider
```

Deve concludersi con **Validazione superata**, senza errori. Se compare un errore,
vedi [Problemi di installazione](#problemi-di-installazione).

**4. Continua** e poi **Aggiorna la base dati di Moodle adesso**. Fine.

### Metodo 2 — copia manuale della cartella

Serve accesso alla shell o FTP. La cartella **deve** chiamarsi `n3xtopenrouter`:

```bash
cd /percorso/moodle/ai/provider          # in Moodle 5 con layout public/: public/ai/provider
git clone https://github.com/n3xtgentech/moodle-aiprovider_n3xtopenrouter.git n3xtopenrouter
chown -R root:www-data n3xtopenrouter
```

Poi visita l'area di amministrazione, oppure da riga di comando:

```bash
php admin/cli/upgrade.php --non-interactive
php admin/cli/purge_caches.php
```

### Metodo 3 — script di deploy incluso

Per chi amministra il server, il repository include un orchestratore che gestisce
manutenzione, backup, installazione, permessi, upgrade e verifica:

```bash
sudo tools/deploy.sh --moodle=/percorso/moodle --dry-run   # mostra tutto, non cambia nulla
sudo tools/deploy.sh --moodle=/percorso/moodle
```

Dettagli in [`docs/OPERATIONS.md`](docs/OPERATIONS.md).

### Problemi di installazione

| Messaggio di Moodle | Causa e soluzione |
|---|---|
| Il nome del componente non corrisponde | Stai usando il "Source code (zip)" di GitHub. Usa l'allegato della release, o costruiscilo con `--prefix=n3xtopenrouter/` |
| L'archivio deve contenere una sola cartella | Hai compresso il contenuto invece della cartella, oppure lo ZIP contiene file extra in radice |
| Manca il file di lingua atteso | Lo ZIP non contiene `lang/en/aiprovider_n3xtopenrouter.php` |
| Versione del plugin troppo bassa | È già installata una versione più recente: non si può retrocedere |
| Richiede una versione di Moodle superiore | Il sito è precedente a Moodle 5.0 |
| La cartella non è scrivibile (`pathwritable`) | **Non dipende dal pacchetto.** Il server tiene l'albero del codice di proprietà di `root`, quindi il web server non può creare cartelle: blocca l'installazione da UI di *qualunque* plugin. Installa dalla shell (Metodo 2 o 3), oppure concedi la scrittura su `ai/provider` al web server per il tempo dell'upload e poi revocala |

Vuoi verificare un pacchetto **prima** di caricarlo? Il repository include lo stesso
validatore che usa Moodle:

```bash
php tools/validate_zip.php --moodle=/percorso/moodle --zip=pacchetto.zip
```

## Configurazione

La configurazione sta in **due posti distinti**, ed è il punto in cui tutti si perdono.

### 1. Istanza del provider — solo credenziali

*Amministrazione del sito → IA → Provider IA → Aggiungi provider → OpenRouter AI Provider (Next Gen Technologies)*

Qui inserisci la **chiave API OpenRouter** e, se vuoi, i limiti di frequenza per
utente e per sito che Moodle offre a ogni provider. **Il modello non è in questa
pagina**, di proposito: un avviso te lo ricorda e ti manda dove serve.

### 2. Azioni — modello e comportamento

*Amministrazione del sito → IA → Provider IA → (la tua istanza) → Azioni →* icona
dell'ingranaggio su **Genera testo**, **Riassumi testo** o **Genera immagine**

Ogni azione si configura da sola, quindi puoi usare modelli diversi per compiti diversi.

Per le due azioni di testo:

| Impostazione | Default | Note |
|---|---|---|
| Modello IA | `google/gemini-3.7-flash` | Dal catalogo live di OpenRouter, oppure digitato con *Altro modello* |
| Endpoint API | `https://openrouter.ai/api/v1/chat/completions` | Da cambiare solo con un proxy |
| Temperatura | `0.2` | Da 0 a 2, validata al salvataggio |
| Istruzione di sistema | Quella di Moodle per l'azione | Inviata prima del prompt dell'utente |
| Numero massimo di parole | `500` | Solo *Riassumi testo*. `0` rimuove il limite |

Per **Genera immagine**:

| Impostazione | Default | Note |
|---|---|---|
| Modello IA | `google/gemini-3.1-flash-image` | Dal catalogo immagini di OpenRouter (43 modelli) |
| Endpoint API | `https://openrouter.ai/api/v1/images` | Endpoint diverso da quello del testo |
| Risoluzione | `1K` | `512`, `1K`, `2K`, `4K`. Inviata solo se il modello accetta quel livello |

Una nuova istanza nasce con questi valori già compilati, così il modello in uso è
visibile da subito invece di essere un default invisibile.

> **Aggiorni da una versione precedente?** L'azione *Genera immagine* arriva
> **disabilitata** sulle istanze già esistenti, perché la loro configurazione è
> anteriore. Abilitala nella tabella Azioni, poi scegli il modello.

### Scegliere il modello

L'elenco è costruito dal catalogo live di OpenRouter (circa 350 modelli di testo,
43 di immagini), messo in cache per 24 ore: svuota le cache del sito per aggiornarlo
prima. Se il catalogo non è raggiungibile viene mostrato un elenco ridotto integrato,
e il form lo dichiara. *Altro modello* accetta qualunque identificativo, quindi
nessun modello è mai inaccessibile.

`openrouter/auto` è disponibile ma non è il default: lascia scegliere a OpenRouter a
ogni richiesta, quindi né il costo né la qualità sono prevedibili. Quando è in uso,
il modello che ha effettivamente risposto viene riportato a Moodle, così resta
verificabile.

## Generazione immagini

Usa l'endpoint immagini unificato di OpenRouter, che accetta un `aspect_ratio`
semantico invece delle dimensioni in pixel — più adatto al quadrato, orizzontale e
verticale che chiede Moodle.

Ciò che un modello accetta varia molto: dei 43 modelli immagine, `aspect_ratio` è
accettato da 40, `resolution` da 19, `quality` da soli 7. Inviare un parametro non
supportato produce un rifiuto immediato, quindi la richiesta porta **solo** ciò che
il modello scelto dichiara di accettare, e le impostazioni non applicabili vengono
omesse invece di essere indovinate. Il rapporto d'aspetto degrada anziché fallire:
una richiesta orizzontale diventa `3:2`, o `16:9`, o `4:3`, il primo che il modello
accetta.

Tre comportamenti da conoscere:

- **Una sola immagine per richiesta.** La risposta di Moodle porta un solo file,
  quindi chiederne di più significherebbe pagare immagini che vengono scartate. I
  modelli immagine di Google hanno comunque un massimo di uno.
- **Lo stile va nel prompt.** L'endpoint non ha un parametro per lo stile e lo
  scarta in silenzio, quindi *vivid* o *natural* vengono aggiunti al testo del prompt.
- **Le immagini hanno la filigrana**, come richiede Moodle, e finiscono nell'area
  bozze dell'utente. L'output vettoriale (per esempio `recraft-v4-vector`) non può
  averla e viene salvato così com'è.

I modelli immagine si pagano **a immagine**, non a token. Conviene impostare il
limite di frequenza per utente prima di abilitare questa azione.

## Verificare che funzioni

Come amministratore, apri:

```
https://tuo-sito/ai/provider/n3xtopenrouter/test_connection.php
```

Chiede conferma, perché è una richiesta reale a pagamento, poi invia una *Genera
testo* e riporta **quale modello ha risposto**, i token consumati e il testo.
Trattala come una pagina diagnostica per amministratori: non collegarla pubblicamente.

Da riga di comando esiste l'equivalente, più altri strumenti di verifica che non
costano nulla: vedi [`tools/`](tools) e [`docs/OPERATIONS.md`](docs/OPERATIONS.md).

## Privacy

Quando un'azione viene eseguita, il plugin invia a OpenRouter:

- il **testo del prompt** di quell'azione — per un riassunto, il testo da riassumere;
  per un'immagine, la descrizione più lo stile richiesto
- il **modello** configurato e i parametri di generazione, incluso rapporto
  d'aspetto e qualità per le immagini

Gli utenti sono identificati solo da un hash del codice del sito e dell'ID utente,
quindi OpenRouter può raggruppare le richieste senza ricevere un'identità. Vengono
inviate due intestazioni facoltative di attribuzione, `HTTP-Referer` (l'URL del
sito) e `X-Title` (il nome del sito). Il plugin non memorizza dati personali in Moodle.

Per quanto tempo OpenRouter conserva i dati dipende dalle impostazioni del tuo
account OpenRouter, non da Moodle.

## Librerie di terze parti

Nessuna. Usa solo le API del core di Moodle e il client HTTP già incluso.

> I collegamenti a `tools/` e `docs/` in questa pagina puntano al repository. Non
> sono inclusi nel pacchetto di installazione, perché servono a chi sviluppa o
> amministra, non al sito che esegue il plugin.

## Crediti

Sviluppato e mantenuto da **Alessio Giustini** — [Next Gen Technologies Italia](https://n3xtgentech.it)

Questo plugin discende dal lavoro di altri, e vale la pena dirlo:

- **Marcus Green** — autore originale di [`moodle-aiprovider_groq`](https://github.com/marcusgreen/moodle-aiprovider_groq),
  da cui deriva la struttura del provider
- **Raymond Baguio / Schoolees** — [`aiprovider_schooleesopenrouter`](https://moodle.org/plugins/aiprovider_schooleesopenrouter),
  il fork OpenRouter da cui questa versione è partita

Le attribuzioni originali sono conservate negli header dei file da cui il codice deriva,
come richiede la licenza.

## Licenza

GPL v3 o successiva, come Moodle. Vedi [`LICENSE`](LICENSE).

---

# 🇬🇧 English

## What it is

Connects Moodle to [OpenRouter](https://openrouter.ai) and provides three actions of
Moodle's AI subsystem: **Generate text**, **Summarise text** and **Generate image**.

One API key gives the site access to hundreds of models — Anthropic, Google, OpenAI,
Meta, Mistral, DeepSeek and Qwen for text; Google, OpenAI, Black Forest Labs, Recraft,
Qwen and others for images — chosen from a list read live from OpenRouter's catalogue.

## Requirements

| Requirement | Notes |
|---|---|
| Moodle **5.0** or later | Needs the AI subsystem with per-instance provider config |
| PHP **8.2+** | As required by Moodle 5.0 |
| **GD** extension | Core watermarks generated images |
| Outbound HTTPS to `openrouter.ai` | API calls and the model catalogues |
| An OpenRouter API key | <https://openrouter.ai/keys> |

## Installation

### Method 1 — from a ZIP through the web interface (recommended)

This is how most people will install it, and it needs no shell access.

**1. Get the right ZIP.**

Download the **`n3xtopenrouter-vX.Y.Z.zip`** asset from the
[Releases page](https://github.com/n3xtgentech/moodle-aiprovider_n3xtopenrouter/releases).

> ⚠️ **Do not use GitHub's "Source code (zip)" link.** Its root directory is
> `moodle-aiprovider_n3xtopenrouter-2.0.0`, but Moodle requires it to be exactly
> `n3xtopenrouter`. The validator rejects it with *"component mismatch name"*. The
> release asset is built with the correct directory.

To build it yourself from source:

```bash
git clone https://github.com/n3xtgentech/moodle-aiprovider_n3xtopenrouter.git
cd moodle-aiprovider_n3xtopenrouter
git archive --format=zip --prefix=n3xtopenrouter/ -o n3xtopenrouter-v2.0.0.zip HEAD
```

**2. Upload it.**

1. Sign in as an administrator
2. *Site administration → Plugins → Install plugins*
3. Drop the ZIP into **ZIP package**
4. Leave the plugin type as **Detect automatically**
5. Click **Install plugin from the ZIP file**

**3. Read the validation report.** Moodle shows what it checked:

```
info     rootdir            n3xtopenrouter
info     componentmatch     aiprovider_n3xtopenrouter
info     pluginversion      2026081402
info     requiresmoodle     2025041400
info     maturity           MATURITY_STABLE
info     release            v2.0.0
info     pathwritable       /path/to/moodle/ai/provider
```

It must finish with **Validation passed** and no errors. If it does not, see
[Installation problems](#installation-problems).

**4. Continue**, then **Upgrade Moodle database now**. Done.

### Method 2 — copy the directory manually

Needs shell or FTP access. The directory **must** be named `n3xtopenrouter`:

```bash
cd /path/to/moodle/ai/provider          # on Moodle 5 with the public/ layout: public/ai/provider
git clone https://github.com/n3xtgentech/moodle-aiprovider_n3xtopenrouter.git n3xtopenrouter
chown -R root:www-data n3xtopenrouter
php admin/cli/upgrade.php --non-interactive
php admin/cli/purge_caches.php
```

### Method 3 — the bundled deploy script

For server administrators, the repository includes an orchestrator that handles
maintenance mode, backup, install, permissions, upgrade and verification:

```bash
sudo tools/deploy.sh --moodle=/path/to/moodle --dry-run   # shows everything, changes nothing
sudo tools/deploy.sh --moodle=/path/to/moodle
```

See [`docs/OPERATIONS.md`](docs/OPERATIONS.md).

### Installation problems

| Message from Moodle | Cause and fix |
|---|---|
| Component name mismatch | You used GitHub's "Source code (zip)". Use the release asset, or build with `--prefix=n3xtopenrouter/` |
| The archive must contain one directory | You zipped the contents instead of the directory, or there are stray files at the root |
| Missing expected language file | The ZIP has no `lang/en/aiprovider_n3xtopenrouter.php` |
| Plugin version too low | A newer version is already installed; downgrading is refused |
| Requires a higher Moodle version | The site is older than Moodle 5.0 |
| Directory not writable (`pathwritable`) | **Not about the package.** The server keeps the code tree owned by `root`, so the web server cannot create directories: this blocks web-interface installs of *every* plugin. Install from the shell (Method 2 or 3), or grant write access to `ai/provider` for the duration of the upload and remove it afterwards |

To check a package **before** uploading it, the repository ships the same validator
Moodle uses:

```bash
php tools/validate_zip.php --moodle=/path/to/moodle --zip=package.zip
```

## Configuration

Configuration lives in **two separate places**, and this is where everyone gets lost.

### 1. The provider instance — credentials only

*Site administration → AI → AI providers → Add provider → OpenRouter AI Provider (Next Gen Technologies)*

Here you set the **OpenRouter API key** and, optionally, the per-user and site-wide
rate limits Moodle offers for every provider. **The model is deliberately not on this
page**; a notice says so and points you to where it is.

### 2. The actions — model and behaviour

*Site administration → AI → AI providers → (your instance) → Actions →* gear icon on
**Generate text**, **Summarise text** or **Generate image**

Each action is configured independently, so different tasks can use different models.

For the two text actions:

| Setting | Default | Notes |
|---|---|---|
| AI model | `google/gemini-3.7-flash` | From the live catalogue, or typed via *Other model* |
| API endpoint | `https://openrouter.ai/api/v1/chat/completions` | Change only when proxying |
| Temperature | `0.2` | 0–2, validated on save |
| System instruction | Moodle's default for the action | Sent ahead of the user prompt |
| Maximum words | `500` | *Summarise text* only. `0` removes the cap |

For **Generate image**:

| Setting | Default | Notes |
|---|---|---|
| AI model | `google/gemini-3.1-flash-image` | From OpenRouter's image catalogue (43 models) |
| API endpoint | `https://openrouter.ai/api/v1/images` | A different endpoint from the text actions |
| Resolution | `1K` | `512`, `1K`, `2K`, `4K`. Sent only when the model accepts that tier |

A new instance is created with these already filled in, so the model in use is visible
from the start rather than being an invisible fallback.

> **Upgrading from an earlier version?** *Generate image* arrives **disabled** on
> existing instances, because their stored action config predates it. Enable it in the
> Actions table, then configure its model.

### Choosing a model

The list is built from OpenRouter's live catalogue (about 350 text models, 43 image
models), cached for 24 hours — purge the site caches to refresh it sooner. If the
catalogue cannot be reached, a short built-in list is shown and the form says so.
*Other model* accepts any model ID, so nothing is ever unreachable.

`openrouter/auto` is offered but is not the default: it lets OpenRouter pick per
request, so neither cost nor quality is predictable. When used, the model that actually
answered is reported back to Moodle, so it stays auditable.

## Generating images

Uses OpenRouter's unified image endpoint, which takes a semantic `aspect_ratio` rather
than pixel dimensions — a better fit for the square, landscape and portrait Moodle asks
for.

What a model accepts varies sharply: of the 43 image models, `aspect_ratio` is accepted
by 40, `resolution` by 19, and `quality` by only 7. Sending an unsupported parameter is
rejected outright, so the request carries **only** what the chosen model publishes
support for, and inapplicable settings are left off rather than guessed at. Aspect ratio
degrades instead of failing: a landscape request becomes `3:2`, or `16:9`, or `4:3`,
whichever the model accepts first.

Three behaviours worth knowing:

- **One image per request.** Moodle's response carries exactly one file, so asking for
  more would bill for images it discards. Google's image models cap it at one anyway.
- **Style goes into the prompt.** The endpoint has no style parameter and drops one
  silently, so *vivid* or *natural* is appended to the prompt text.
- **Images are watermarked**, as core requires, and land in the requesting user's draft
  area. Vector output (for example `recraft-v4-vector`) cannot be watermarked and is
  stored as-is.

Image models are billed **per image**, not per token. Set the per-user rate limit before
enabling this action.

## Verifying it works

Signed in as an administrator, open:

```
https://your-site/ai/provider/n3xtopenrouter/test_connection.php
```

It asks for confirmation, because it is a real billable request, then sends one
*Generate text* and reports **which model answered**, the token counts and the response.
Treat it as an admin-only diagnostic page; do not link it publicly.

There is a command-line equivalent, plus verification tools that cost nothing: see
[`tools/`](tools) and [`docs/OPERATIONS.md`](docs/OPERATIONS.md).

## Privacy

When an action runs, this plugin sends OpenRouter:

- the **prompt text** for that action — for a summary, the text being summarised; for
  an image, the description plus the requested style
- the configured **model** and generation parameters, including the aspect ratio and
  quality for image requests

Users are identified only by a hash of the site ID and user ID, so OpenRouter can group
requests without receiving an identity. Two optional attribution headers are sent,
`HTTP-Referer` (the site URL) and `X-Title` (the site name). No personal data is stored
in Moodle by this plugin.

How long OpenRouter retains request data is governed by your OpenRouter account
settings, not by Moodle.

## Third-party libraries

None. It uses Moodle core APIs and the HTTP client Moodle already bundles.

> Links to `tools/` and `docs/` on this page point at the repository. They are not
> in the installation package, because they are for developing and operating the
> plugin rather than for the site running it.

## Credits

Developed and maintained by **Alessio Giustini** — [Next Gen Technologies Italia](https://n3xtgentech.it)

This plugin stands on other people's work, and that is worth stating:

- **Marcus Green** — original author of [`moodle-aiprovider_groq`](https://github.com/marcusgreen/moodle-aiprovider_groq),
  from which the provider structure derives
- **Raymond Baguio / Schoolees** — [`aiprovider_schooleesopenrouter`](https://moodle.org/plugins/aiprovider_schooleesopenrouter),
  the OpenRouter fork this version started from

Original attributions are preserved in the headers of the files the code derives from,
as the licence requires.

## Licence

GPL v3 or later, the same as Moodle. See [`LICENSE`](LICENSE).
