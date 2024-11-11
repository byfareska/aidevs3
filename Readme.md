# AiDevs3
AiDevs3 solutions written using PHP, Symfony framework and Modelflow library.

## Quickstart

### Requirements
 - Docker

### Running the project
```bash
cp .env .env.local # Copy and fill environment variables
docker compose up -d # Start the project
docker compose exec ai ollama pull llama3.2 # Pull the Llama3.2 image
docker compose run --rm php bin/console task # Run the task
```