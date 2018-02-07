build:
	docker build -t darkilliant/php-docker:`git describe --tags --abbrev=0` ./ -f ./DockerBuild
	docker push darkilliant/php-docker:`git describe --tags --abbrev=0`