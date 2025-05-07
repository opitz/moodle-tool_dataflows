# Upgrading
There has been a `Dockerfile` and `docker-compose.yaml` added to allow running composer update consistently without relying on local configuration of php.

To run an upgrade running `docker compose up` will start the container and run `composer update --no-dev`. You can then commit the result with the updated `vendor` folder and updated `composer.lock` file.

