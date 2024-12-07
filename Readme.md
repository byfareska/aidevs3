# AiDevs3
AiDevs3 solutions written using PHP, Symfony framework and Modelflow library.

## Quickstart

### Requirements
 - Docker

### Running the project
```bash
cp .env .env.local # Copy and fill environment variables
docker compose up -d # Start the project
docker compose exec ollama ollama pull gemma:2b # Pull the gemma image
docker compose exec ollama ollama pull llava # Pull the llava image
docker compose run --publish 8080:8080 --rm php php -S 0.0.0.0:8080 -t public # Some tasks require a web server + you'll be able to access profiler
docker compose run --rm php bin/console task # Run the task
```