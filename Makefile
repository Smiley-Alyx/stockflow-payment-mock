.PHONY: consume docker-down docker-up install migrate serve test test-integration

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

requeue-dlq:
	php artisan payment-mock:requeue-dlq

test:
	php artisan test

test-integration:
	RABBITMQ_PORT=5673 php artisan test --configuration=phpunit.integration.xml

docker-up:
	docker compose up --build -d

docker-down:
	docker compose down
