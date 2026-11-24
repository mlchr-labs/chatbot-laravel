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

The project folder is mounted at `/var/www/html` inside the container, so `vendor` and `node_modules` end up in your local folder, created by the commands below.

### 1. Create the `.env`

```sh
cp .env.example .env
```

### 2. Set the variables

Edit `.env`. The `change-me` placeholders must be replaced.

| Variable | Purpose |
| --- | --- |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_ROOT_PASSWORD` | Used by the MariaDB container to create the database and by the app to connect |
| `REDIS_PASSWORD` | Redis password (queue, cache) |
| `QDRANT_API_KEY` | Qdrant API key. The app and the container read the same variable |
| `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` | Empty in `.env.example`. Fill them with your own values, otherwise the widget WebSocket will not work |
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

### 3. Start the containers

```sh
docker compose up -d --build
```

The embeddings service reads `CONTAINER_PLATFORM` (default `linux/amd64`) and `EMBEDDINGS_IMAGE` (default `ghcr.io/huggingface/text-embeddings-inference:cpu-1.7`). Set them in `.env` if you need a different platform or image.

### 4. Install dependencies and prepare the app

Run these inside the `webapp` container:

```sh
docker compose exec webapp composer install
docker compose exec webapp php artisan key:generate
docker compose exec webapp php artisan migrate
docker compose exec webapp php artisan knowledge:setup
```

`knowledge:setup` creates the vector collection in Qdrant if it does not exist yet. The embedding job also ensures the collection, so the command just gets it ready before the first upload.

### 5. Build the front end

The build produces the panel assets (`public/build`) and the widget (`public/widget.js`). The project `.npmrc` sets `ignore-scripts=true`.

```sh
docker compose exec webapp pnpm install
docker compose exec webapp pnpm build
```

Scripts in `package.json`:

| Script | What it does |
| --- | --- |
| `pnpm build` | Builds the panel and the widget |
| `pnpm build:widget` | Widget only (`vite.widget.config.js`) |
| `pnpm dev` | Vite dev server |

`public/widget.js` is in `.gitignore`. It is a build artifact, so generate it after cloning and whenever you change `resources/widget`.

### 6. Open it

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

`composer.json` has a `setup` script that installs dependencies, creates `.env`, generates the key, runs migrations and builds the front end. It calls `npm install` and `npm run build`, so if you use pnpm, run the steps one by one instead.

`composer dev` runs `php artisan dev`.

Processes the app needs for full functionality (the same ones supervisor runs in the container): `php artisan horizon`, `php artisan reverb:start` and `php artisan schedule:work`.

## Tests

```sh
docker compose exec webapp composer test
```

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
