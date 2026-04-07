.PHONY: all init update uservolumes dot-env elasticsearch-data-folder

# ─── Version ──────────────────────────────────────────────────────────────────
VERSION := $(shell cat .version 2>/dev/null || echo "0.1")

# ─── Resolve composer binary: system → local download ────────────────────────
COMPOSER := $(shell which composer 2>/dev/null || echo "./bin/composer")

# ─── Default: full first-time setup ──────────────────────────────────────────
all: init

init: ensure-composer uservolumes dot-env elasticsearch-data-folder robo-bin update-made-file
	@echo ""
	@echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
	@echo "  Project initialised — version $(VERSION)"
	@echo "  Next steps:"
	@echo "    1.  Add your repo keys to .env"
	@echo "        MAGENTO_REPO_PUBLIC_KEY and MAGENTO_REPO_PRIVATE_KEY"
	@echo "        https://commercemarketplace.adobe.com/customer/accessKeys/"
	@echo "    2.  ./robo up  (auto-installs Magento when keys are set)"
	@echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

# Re-run composer install and bump .made (run after git pull)
update: ensure-composer composer-install update-made-file
	@echo "Dependencies updated."

# ─── Ensure composer is available (system or local fallback) ─────────────────
ensure-composer:
	@if which composer > /dev/null 2>&1; then \
		echo "  Composer: $$(which composer)"; \
	elif [ -f ./bin/composer ]; then \
		echo "  Composer: ./bin/composer (local)"; \
	else \
		echo "  Composer not found — downloading to ./bin/composer..."; \
		mkdir -p bin volumes/.composer; \
		curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php || \
			(echo "ERROR: failed to download Composer installer" && exit 1); \
		COMPOSER_HOME=./volumes/.composer \
			php /tmp/composer-setup.php --install-dir=bin --filename=composer --quiet; \
		rm -f /tmp/composer-setup.php; \
		[ -f ./bin/composer ] || (echo "ERROR: Composer download failed" && exit 1); \
		echo "  Composer installed to ./bin/composer"; \
	fi

# ─── Composer ─────────────────────────────────────────────────────────────────
composer-install: ensure-composer
	@COMPOSER_HOME=./volumes/.composer \
		$(COMPOSER) install --no-interaction --prefer-dist --optimize-autoloader

# ─── Robo wrapper script ──────────────────────────────────────────────────────
robo-bin: composer-install
	@if [ ! -f ./robo ]; then \
		printf '#!/usr/bin/env bash\nexec "$$(dirname "$$0")/bin/robo" "$$@"\n' > ./robo; \
		chmod +x ./robo; \
		echo "  Created: ./robo wrapper"; \
	fi

# ─── User volumes (mirrors carhartt-b2b pattern) ──────────────────────────────
uservolumes:
	@mkdir -p volumes/.composer volumes/.ssh
	@if [ ! -f volumes/.ssh/known_hosts ]; then \
		touch volumes/.ssh/known_hosts; \
		chmod 644 volumes/.ssh/known_hosts; \
	fi
	@# Symlink host .ssh keys (read-only, never copy private keys into the repo)
	@if [ -f "$$HOME/.ssh/id_rsa" ] && [ ! -L volumes/.ssh/id_rsa ]; then \
		ln -s "$$HOME/.ssh/id_rsa" volumes/.ssh/id_rsa; \
		echo "  Linked: $$HOME/.ssh/id_rsa → volumes/.ssh/id_rsa"; \
	fi
	@if [ -f "$$HOME/.ssh/id_ed25519" ] && [ ! -L volumes/.ssh/id_ed25519 ]; then \
		ln -s "$$HOME/.ssh/id_ed25519" volumes/.ssh/id_ed25519; \
		echo "  Linked: $$HOME/.ssh/id_ed25519 → volumes/.ssh/id_ed25519"; \
	fi
	@echo "  Volumes ready."

# ─── Environment file ─────────────────────────────────────────────────────────
dot-env:
	@if [ ! -f .env ]; then \
		cp .env.example .env; \
		echo "  Created: .env (from .env.example — edit before first ./robo install)"; \
	else \
		echo "  Exists:  .env"; \
	fi
	@if [ ! -d src ]; then mkdir -p src/app/code; fi

# ─── OpenSearch data folder ───────────────────────────────────────────────────
elasticsearch-data-folder:
	@mkdir -p elasticsearch/data
	@echo "  Created: elasticsearch/data"

# ─── Version tracking (.made integrity check) ────────────────────────────────
update-made-file:
	@echo "$(VERSION)" > .made
	@echo "  .made set to $(VERSION)"
