# Chatbot Laravel

A platform for building chatbots that answer from your own documents and can be dropped into any website through a widget.

Each user signs up in the panel, creates one or more chatbots, picks an LLM provider, uploads documents to a knowledge base and copies a `<script>` snippet into the site where the chat should appear. Replies reach the visitor as a stream, token by token.

## What it does

- **Authenticated panel** (Laravel Fortify): registration, login, email verification and password reset. Inside it, users manage chatbots, knowledge sources and conversations.
- **Chatbots**: each one has a name, system prompt, provider, model, temperature, welcome message, theme and a list of allowed domains. A chatbot can also carry its own provider API key, and its public widget key can be rotated.
- **LLM providers** (`app/Services/LLM`): OpenAI, Anthropic, Google Gemini, Groq and DeepSeek. Keys and default models are set in `config/llm.php`. A chatbot without its own key uses the platform key and is limited to the models listed in `*_PLATFORM_MODELS`.
- **Knowledge base (RAG)**: uploaded documents go through text extraction, chunking, embedding and storage in Qdrant. When a visitor asks something, the closest chunks are added to the prompt. These jobs run on the `knowledge` queue. PDF extraction uses `pdftotext` (poppler).
- **Embeddings**: Hugging Face Text Embeddings Inference serving `intfloat/multilingual-e5-small` (384 dimensions).
- **Public widget**: a single `public/widget.js` file produced by the build, rendered inside a Shadow DOM. It opens an anonymous session, checks the request `Origin` against the chatbot's allowed domains and receives replies over WebSocket (Laravel Reverb).
- **Limits**: per-user caps (`config/plans.php`), upload and ingestion limits (`config/knowledge.php`), and widget rate limits and daily quotas (`config/widget.php`).
- **Observability**: Horizon for queues and Telescope.

## Stack and requirements

| Item | Version or source |
| --- | --- |
| PHP | `^8.3` in `composer.json`. The Docker image installs 8.4 |
| Laravel | `^13.17` |
| Fortify, Horizon, Reverb | `^1.43`, `^5.52`, `^1.14` |
| Node | 22 in the Docker image (`NODE_VERSION=22`) |
| Vite | `^8.0.0` |
| Tailwind CSS | `^4.0.0` |
| JS package manager | pnpm (there is a `pnpm-lock.yaml`). `package.json` does not pin a pnpm version |
| Database | MariaDB 11 (`mariadb:11`) |
| Queue and cache | Redis (`redis:alpine`) |
| Vector store | Qdrant `v1.20.2` |
| Embeddings | `ghcr.io/huggingface/text-embeddings-inference:cpu-1.7` |
| Dev mail | Mailpit |

For the Docker path you only need Docker with Compose. PHP, Node and `pdftotext` come with the image.

## Running with Docker

`docker-compose.yml` starts these services: `webapp` (nginx, php-fpm 8.4, Horizon, Reverb and the scheduler, all under supervisor), `webapp_mariadb`, `webapp_redis`, `webapp_qdrant`, `webapp_embeddings` and `webapp_mailpit`.

The project folder is mounted at `/var/www/html` inside the container, so `vendor` and `node_modules` end up in your local folder.

You need Docker with Compose, `make` and `openssl` on the host.

### Quick start

```sh
make setup
```

`make setup` runs `bin/setup`, a wizard that:

1. Checks that Docker, Docker Compose and `openssl` are available and that the Docker daemon responds.
2. Creates `.env` from `.env.example` if it does not exist yet.
3. Replaces every `change-me` placeholder with a random value and fills `REVERB_APP_ID`, `REVERB_APP_KEY` and `REVERB_APP_SECRET` when they are empty. Values already filled are never overwritten, so running it again is safe.
4. When run in a terminal, asks for the LLM provider keys, `APP_PORT` (updating `APP_URL`) and the Reverb port (keeping `REVERB_PORT`, `REVERB_SERVER_PORT` and `REVERB_EXPOSED_PORT` equal). Enter keeps the current value.
5. Runs `docker compose up -d --build`, waits for MariaDB to be healthy, then runs `composer setup` (dependencies, app key, migrations, front end build) and `php artisan knowledge:setup` inside the `webapp` container.
6. Prints the URLs and the next steps.

`bin/setup --env-only` does only steps 1 to 4 (without the Docker checks) and does not start anything.

### Make targets

| Command | What it does |
| --- | --- |
| `make` or `make help` | Lists the targets |
| `make setup` | Runs the setup wizard described above |
| `make setup FORCE=1` | Asks for confirmation, saves the current `.env` to `.env.backup` and recreates it from `.env.example` |
| `make up` | Starts the stack, waits for MariaDB and prints the URLs. Fails if `.env` does not exist |
| `make down` | Stops the stack. Volumes are kept |
| `make logs` | Follows the logs of all services. `make logs s=webapp` follows one service |
| `make shell` | Opens bash in the `webapp` container |
| `make test` | Runs `composer test` in the `webapp` container |
| `make destroy` | Asks for confirmation and runs `docker compose down -v`, deleting the volumes (MariaDB data, Qdrant vectors, embeddings cache) |

MariaDB only reads `DB_PASSWORD` and `DB_ROOT_PASSWORD` when its volume is created. If you recreate `.env` with `make setup FORCE=1` while the old volume exists, the database keeps the old passwords and the app cannot connect. In that case run `make destroy` and then `make setup`. The setup script warns about this but never deletes anything on its own.

### Variables

The wizard fills the secrets for you. The table below is a reference for editing `.env` by hand.

| Variable | Purpose |
| --- | --- |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_ROOT_PASSWORD` | Used by the MariaDB container to create the database and by the app to connect |
| `REDIS_PASSWORD` | Redis password (queue, cache) |
| `QDRANT_API_KEY` | Qdrant API key. The app and the container read the same variable |
| `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` | Empty in `.env.example`. Without values the widget WebSocket will not work |
| `OPENAI_API_KEY`, `ANTHROPIC_API_KEY`, `GEMINI_API_KEY`, `GROQ_API_KEY`, `DEEPSEEK_API_KEY` | Platform keys. Fill in the providers you want to offer. Chatbots with their own key do not depend on them |
| `OPENAI_MODEL`, `ANTHROPIC_MODEL`, `GEMINI_MODEL`, `GROQ_MODEL`, `DEEPSEEK_MODEL` | Default model per provider |
| `*_PLATFORM_MODELS` | Comma separated models allowed while a chatbot uses the platform key. Empty means only the default model |

Host ports, all optional and commented out in `.env.example`:

| Variable | Default | What it publishes |
| --- | --- | --- |
| `APP_PORT` | `8080` | App nginx |
| `REVERB_EXPOSED_PORT` | `8090` | Reverb WebSocket |
| `FORWARD_DB_PORT` | `3306` | MariaDB (127.0.0.1 only) |
| `FORWARD_REDIS_PORT` | `6379` | Redis (127.0.0.1 only) |

If you change the Reverb port in development, keep `REVERB_PORT`, `REVERB_SERVER_PORT` and `REVERB_EXPOSED_PORT` equal, as `.env.example` warns. If you change `APP_PORT`, update `APP_URL` too.

Qdrant (`6333`), embeddings (`8081`) and Mailpit (`1025` and `8025`) are published on 127.0.0.1 only, with fixed ports in the compose file.

The embeddings service reads `CONTAINER_PLATFORM` (default `linux/amd64`) and `EMBEDDINGS_IMAGE` (default `ghcr.io/huggingface/text-embeddings-inference:cpu-1.7`). Set them in `.env` if you need a different platform or image.

### Manual setup

If you prefer not to use `make`:

1. `cp .env.example .env` and replace the `change-me` placeholders and the empty `REVERB_APP_*` values.
2. `docker compose up -d --build` and wait until `webapp_mariadb` is healthy (`docker compose ps`).
3. `docker compose exec webapp composer setup`, which installs dependencies, generates the key, runs migrations and builds the front end with pnpm.
4. `docker compose exec webapp php artisan knowledge:setup`, which creates the vector collection in Qdrant if it does not exist yet. The embedding job also ensures the collection, so the command just gets it ready before the first upload.

Run `composer setup` inside the container, not on the host: `DB_HOST=webapp_mariadb` only resolves inside the Docker network.

### Front end

The build produces the panel assets (`public/build`) and the widget (`public/widget.js`). The project `.npmrc` sets `ignore-scripts=true`. `composer setup` already runs `pnpm install` and `pnpm build`. To rebuild:

```sh
docker compose exec webapp pnpm build
```

Scripts in `package.json`:

| Script | What it does |
| --- | --- |
| `pnpm build` | Builds the panel and the widget |
| `pnpm build:widget` | Widget only (`vite.widget.config.js`) |
| `pnpm dev` | Vite dev server |

`public/widget.js` is in `.gitignore`. It is a build artifact, so generate it after cloning and whenever you change `resources/widget`.

### Open it

| What | Address |
| --- | --- |
| App and panel | http://localhost:8080 (`APP_PORT`) |
| Mailpit (verification and password reset emails) | http://localhost:8025 |
| Horizon | http://localhost:8080/horizon |

`/` redirects to the login page, or to the dashboard if you are signed in. Create your account at `/register`. Email verification is enabled, so open Mailpit to get the confirmation link.

Horizon and Telescope are open in the `local` environment. Elsewhere, the gate in `HorizonServiceProvider` and `TelescopeServiceProvider` holds the list of authorized emails, which is empty by default.

## Using the widget

1. In the panel, create a chatbot and choose its provider and model.
2. In the knowledge tab, upload documents and wait for processing.
3. On the chatbot edit page, add the allowed domains. With no domain registered, the widget stays blocked.
4. In the Widget tab, copy the embed snippet and paste it before `</body>` on your site.

The snippet format, as shown in `resources/widget/demo.html`:

```html
<script src="/widget.js" data-chatbot="pk_REPLACE_ME" async></script>
```

`pk_REPLACE_ME` is the chatbot public key, shown in the Widget tab.

### Local demo page

With `APP_ENV=local` the `/widget-demo` route serves `resources/widget/demo.html`. To try it:

1. Build the widget with `pnpm build:widget`.
2. Replace `pk_REPLACE_ME` in `resources/widget/demo.html` with your chatbot public key.
3. Add `localhost:8080` (your `APP_PORT`) to the chatbot allowed domains.
4. Open http://localhost:8080/widget-demo.

The route does not exist in other environments.

### Widget endpoints

Defined in `routes/api.php`, under the `/api/widget` prefix:

| Method | Route |
| --- | --- |
| GET | `/api/widget/config/{public_key}` |
| POST | `/api/widget/sessions` |
| POST | `/api/widget/broadcasting/auth` |
| POST | `/api/widget/conversations/{conversation}/messages` |

The last two require the widget session token.

## Running without Docker

The repository has no documented Docker-free path. The app needs MariaDB, Redis, Qdrant and the embeddings server. To run PHP on your host, start those services however you prefer and change the hosts in `.env` that currently point to compose service names: `DB_HOST`, `REDIS_HOST`, `MAIL_HOST`, `QDRANT_URL` and `EMBEDDING_URL`.

Local requirements: PHP 8.3 or newer, Composer, Node, pnpm and poppler `pdftotext` (path in `PDFTOTEXT_PATH`).

`composer setup` installs dependencies, creates `.env`, generates the key, runs migrations and builds the front end with pnpm.

`composer dev` runs `php artisan dev`.

Processes the app needs for full functionality (the same ones supervisor runs in the container): `php artisan horizon`, `php artisan reverb:start` and `php artisan schedule:work`.

## Tests

```sh
make test
```

This is the same as `docker compose exec webapp composer test`.

The script clears the config cache and runs `php artisan test`. In `phpunit.xml` the tests use in-memory SQLite, the `sync` queue, the `array` cache and a `null` broadcaster. There are `Unit` (`tests/Unit`) and `Feature` (`tests/Feature`) suites.

To run one suite or one file:

```sh
docker compose exec webapp php artisan test --testsuite=Unit
docker compose exec webapp php artisan test tests/Unit/TextChunkerTest.php
```

## Folder layout

```
app/
  Actions/Knowledge/        Starts and fails document ingestion
  Contracts/                EmbeddingClient and VectorStore interfaces
  Jobs/Chat/                GenerateReply, which produces the chatbot reply
  Jobs/Knowledge/           Extraction, chunking, embedding and removal
  Services/LLM/             Provider clients and factory
  Services/Chat/            Prompt building and reply generation
  Services/Embeddings/      TEI client
  Services/VectorStore/     Qdrant client
  Services/Widget/          Token, Origin validation and widget access
  Http/Controllers/Widget/  Public API used by the widget
config/                     llm, knowledge, widget, plans, reverb, horizon
resources/views/            Blade panel
resources/widget/           Widget source (builds public/widget.js)
.docker/app/                Dockerfile, nginx, php-fpm and supervisor config
tests/                      Unit and Feature
```

## License

`composer.json` declares the MIT license. There is no `LICENSE` file at the repository root.
