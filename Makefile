.PHONY: all pull build run

all: pull build run

pull:
	git pull

build:
	docker-compose build

run:
	docker-compose up -d

