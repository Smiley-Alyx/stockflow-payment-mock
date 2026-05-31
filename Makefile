.PHONY: consume docker-down docker-up install migrate serve test

install:
	composer install
	composer install-git-hooks

migrate:
	php artisan migrate
	php artisan db:seed --class=Database\\Seeders\\SandboxCardSeeder --force

serve:
	php artisan serve --host=0.0.0.0 --port=8081

consume:
	php artisan payment-mock:consume-requests

test:
	php artisan test

docker-up:
	docker compose up --build -d

docker-down:
	docker compose down
