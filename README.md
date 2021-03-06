# holistic-herd-management

Web-based client and PostgreSQL server for managing herd rotations on a ranch
following holistic guidelines.

## Deprecated

While this project works as is, it has been deprecated and the technology has
been reintegrated into Durpal project FarmOS as the Grazing Module and all
future development will be done there.

https://farmos.org/
https://github.com/farmOS/farm_grazing

This provides user management, detailed records keeping, and integration
with other FarmOS modules.

## Included Software

This repository contains snapshots of
* fullcalendar-2.3.1
* TableTools-2.2.3
* jQuery
* and potentially others

This software is Copyrighted and licensed by their respective owners and are
only included here because these are the versions the we have built and tested
against.

## Installation

* create a PostgreSQL database in pgAdmin or on the command line
```
# commandline
createdb -U postgres -h localhost holistic_herd
psql -U postgres -h localhost holistic_herd -c "create extension postgis"
psql -U postgres -h localhost holistic_herd -c "create schema data"
psql -U postgres -h localhost holistic_herd -c "alter database holistic_herd SET search_path = data, pg_catalog"

# create the tables in an empty database
psql -U postgres -h localhost holistic_herd -f database-schema.sql

# OR create the tables and load some sample data
psql -U postgres -h localhost holistic_herd -f sample-database-dump.sql

```

* Edit files ``class/config.php``, ``holistic-config.php``, ``holistic-database.php`` can change the database parameters to match your system.

* Copy the rest of the files to your web server.

* Browse to the index.html file on your web server.

## Testing with docker-compose

This assumes you have docker and docker-compose installed.

Open a command line in the `docker` directory, run `docker-compose up`.

Once the database has loaded the schema, you can try out the manager on `http://localhost:8080` in your browser.

## Acknowledgments

* Thanks to Frank Aragona for his patience and knowledge on holistic herd management.
* Thanks to Sallie Calhoun for the funding that made this phase of development possible.

