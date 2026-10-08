# Sistem Informasi Desa — perintah pengembangan
.DEFAULT_GOAL := help
.PHONY: help setup serve build test test-wajib lint analyse check fresh seed harden

help: ## Tampilkan daftar perintah
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2}'

setup: ## Pasang dependensi & siapkan basis data pengembangan
	composer install
	npm ci
	@test -f .env || cp .env.example .env
	php artisan key:generate
	@echo "Isi PII_ENCRYPTION_KEY & PII_HASH_PEPPER di .env:"
	php artisan sid:generate-pii-keys
	php artisan migrate --seed
	npm run build

serve: ## Jalankan server pengembangan (PHP + Vite)
	npx concurrently -k -n php,vite -c green,magenta \
		"php artisan serve --host=127.0.0.1 --port=8000" "npm run dev"

build: ## Bangun aset produksi
	npm run build

fresh: ## Bangun ulang basis data + data contoh
	php artisan migrate:fresh --seed

test: ## Jalankan seluruh pengujian
	./vendor/bin/phpunit

test-wajib: ## Jalankan HANYA lapis wajib (batas repository + otorisasi)
	./vendor/bin/phpunit --testsuite Architecture,Authorization

lint: ## Rapikan format kode
	./vendor/bin/pint

analyse: ## Analisis statis (PHPStan level 6 + TypeScript)
	./vendor/bin/phpstan analyse --memory-limit=1G
	npx vue-tsc --noEmit

check: lint analyse test ## Jalankan seluruh gerbang kualitas (sama dengan CI)

harden: ## Jadikan audit log append-only di tingkat MySQL (WAJIB di produksi)
	php artisan sid:harden-audit-log
	php artisan sid:verify-audit-chain --check-grants
