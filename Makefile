.PHONY: all init install update

# Thin aliases kept for muscle memory — ./setup does the real work and only
# needs Docker on the host.

all: init

# Prepare the project (tooling, .env, folders) without starting containers
init:
	@./setup --no-start

# Prepare, start and install Magento in one go
install:
	@./setup

# Re-install tooling after a git pull
update:
	@./setup --update
