.PHONY: docker-down docker-up install migrate serve test

install:
	composer install

migrate:
	php artisan migrate

serve:
	php artisan serve --host=0.0.0.0 --port=8081

test:
	php artisan test

docker-up:
	docker compose up --build -d

docker-down:
	docker compose down
