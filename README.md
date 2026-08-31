# ReCodEx Core and REST API

[![Build Status](https://github.com/ReCodEx/api/workflows/CI/badge.svg)](https://github.com/ReCodEx/api/actions)
[![API documentation](https://img.shields.io/badge/docs-OpenAPI-orange.svg)](https://recodex.github.io/api/)
[![codecov](https://codecov.io/gh/ReCodEx/api/branch/master/graph/badge.svg?token=LSWVLRLMH6)](https://codecov.io/gh/ReCodEx/api)
[![GitHub release](https://img.shields.io/github/release/recodex/api.svg)](https://github.com/ReCodEx/wiki/wiki/Changelog)

Business logic core of the application and a REST API that provides access to other modules. This module is also responsible for authentication and data persistence (database and file storage).

## Installation

### Prerequisites

You need a web server with PHP (8.4+, 8.5 currently used) and MySQL or MariaDB database.

Our AlmaLinux 10 installation looks like this:

```
# dnf install dnf-utils http://rpms.remirepo.net/enterprise/remi-release-10.rpm
# dnf module enable php:remi-8.5
# dnf install php php-json php-mysqlnd php-ldap php-pecl-yaml php-pecl-zip php-pecl-zmq php-xml php-intl php-mbstring php-pecl-inotify
```

If you are going to use manual installation or get engaged in development, you will also need `git` and `composer` installed.

### Install as Package (recommended)

This is the recommended way for CentOS/RHEL and Fedora systems.

```
# dnf copr enable semai/ReCodEx
# dnf install recodex-core
```

The module will be installed in `/opt/recodex-core`, the config file will be sym-linked to `/etc/recodex/core-api/config.local.neon`, and the log directory will be created in `/var/log/recodex/core-api`.

### Manual Installation

Clone the repository to an appropriate folder (e.g., `/opt/recodex-core`) as the `recodex` user (or chown it to `recodex:recodex` afterwards).

```
$ git clone https://github.com/ReCodEx/api.git /opt/recodex-core
```

Run composer to install dependencies into `vendor/` directory (do this as the `recodex` user, not as root):

```
$ cd /opt/recodex-core
$ composer install
```

Make sure the whole application directory is readable by the web server user (e.g., `apache` or `www-data`). The `log/` and `temp/` directories must also be writable by the web server user (and remain readable and writable by the `recodex` user). You can do this by running the following commands (as root):

```
# setfacl -R -m u:apache:rx /opt/recodex-core
# setfacl -R -m u:apache:rwx /opt/recodex-core/log /opt/recodex-core/temp
# setfacl -R -d -m u:apache:rwx /opt/recodex-core/log /opt/recodex-core/temp
```

## Post-Install Initialization

First, the configuration needs to be set up. The configuration is stored in `<app-root>/app/config/config.local.neon` file. If you followed the manual installation, you will need to create this file manually by copying the `config.local.neon.example` file.

Execute the `./cleaner` script every time you change the configuration to clear the cache and avoid any issues. The cache will be automatically reconstructed from the new configuration with the first request to the API.

### File storage

The file storage is a set of directories managed by the core component. It is divided into two parts -- hash storage that uses hashes as file names for data deduplication and local storage for regular files. Note that both directories **must be created manually** and you need to make them both readable and writable by the `apache` (or `www-data` in the case of Debian-like systems) user and the `recodex` user (use fs ACLs). The recommended place to store the data would be under `/var/recodex-filestorage` with `hash` and `local` subdirectories. The following commands (executed as root) will do just that:

```
# mkdir /var/recodex-filestorage
# mkdir /var/recodex-filestorage/hash
# mkdir /var/recodex-filestorage/local
# chown -R recodex:recodex /var/recodex-filestorage
# setfacl -R -m u:apache:rwx /var/recodex-filestorage
# setfacl -R -d -m u:apache:rwx /var/recodex-filestorage
# setfacl -R -m u:recodex:rwx /var/recodex-filestorage
# setfacl -R -d -m u:recodex:rwx /var/recodex-filestorage
```

The absolute paths to the two directories must be set in the `config.local.neon` parameters (in `fileStorage` config).

### Database

A MySQL/MariaDB database with a dedicated user is required. You can do this by running `mysql -uroot -p` and then executing these SQL statements:

```
CREATE DATABASE `recodex`;
CREATE USER 'recodex'@'localhost' IDENTIFIED BY 'someSecretPasswordYouNeedToSetYourself';
GRANT ALL PRIVILEGES ON `recodex`.* TO 'recodex'@'localhost';
```

The password used in the SQL command must be set in the `config.local.neon` parameters (in `nettrine.dbal` > `connections` > `default` config).

Set up the database schema by running:

```
$ bin/console migrations:migrate
```

You may optionally fill the database with initial values by running `bin/console db:fill init`, after which the database will contain:

- An instance (with root group)
- An administrator user registered as a local account with the credentials username: `admin@admin.com`, password: `admin`
- A default single hardware group that might be used for workers

Finally, you might want to load some runtime environments (and their configuration pipelines). These are stored in a [separate repository](https://github.com/ReCodEx/runtimes); just follow the instructions there.

### Web Server Configuration

The simplest way to get started is to start the built-in PHP server in the root directory of your project, which is useful for debugging:

```
$ php -S localhost:4000 -t www
```

For production use, it is recommended to use a full-grown server like Apache. Set up a virtual host to point to the `www/` directory of the project and enable PHP processing (we recommend using `php-fpm`).

Finally, you need to configure the web server, so that

1. The `/opt/recodex-core/www` directory is accessible and PHP scripts here are interpreted (we recommend using `php-fpm`).
2. A proper alias is set for `/opt/recodex-core/www` that matches the API path set in other modules, especially the web frontend (e.g., `/api` if ReCodEx runs at the root of a domain).
3. It is **critical** that the whole `app/`, `log/`, and `temp/` directories are not accessible directly via a web browser.
4. It is **highly recommended** to configure HTTPS-only access to the API (using HSTS)
5. It might be necessary to set up CORS headers (a similar thing goes for the web app) as follows:

```
Header always set Access-Control-Allow-Origin "*"
Header always set Access-Control-Allow-Headers "*"
Header always set Access-Control-Allow-Methods "GET, POST, PUT, DELETE, PATCH, OPTIONS"
```

## Configuration

The API can be configured by `.neon` files in the `app/config` directory of the API project source tree. The `config.local.neon` file is the one you can edit; the other files are deployed with the application. If you deployed the core-api module from an RPM package, a symlink to `/etc/recodex/core-api/config.local.neon` is created automatically. If you deployed the module manually, you need to create this file yourself by copying the `config.local.neon.example` template in the config directory.

Please follow the comments in the example config file to set up the configuration. The most important parameters are:

- `fileStorage` paths need to reflect the actual paths to the file storage directories you created in the post-installation step
- `nettrine.dbal` (global key) > `connections` > `default` parameters should reflect your database connection (host, username, password, database name)
- `api` > `address` and `webapp` > `address` parameters should reflect the actual URL of the API and web frontend (fill in your domain)
- `accessManager` > `verificationKey` should be set to a secret string (at least 32 characters long) that is used for signing JWT tokens (necessary for secure authentication)
- `broker`, `monitor`, and `workerFiles` parameters should reflect the actual configuration of the broker module, monitor module, and worker modules
- `mail` (global key) configures your SMTP server and `emails` (parameters) configure other mailing details (sender, admin email).

More details can be found in the main config file `config.neon` (the `config.local.neon` works as an override); however, modifying parameters not explicitly stated in the `.example` file is not recommended.

**Remember to run the `./cleaner` script after any change in the configuration.**

## Troubleshooting

In case of any issues, first remove the Nette cache directory `temp/cache/` and try again. This solves most of the errors. If it does not help, examine the API logs from the `log/` directory of the API source or the logs of your web server.

## Running tests

The tests require `sqlite3` to be installed and accessible through $PATH. Run them with the following command (feel free to adjust the path to php.ini):

```
$ php vendor/bin/tester -c /etc/php/php.ini tests
```

## Periodical commands

Core API offers several command line commands (executed by `./bin/console`) for cleanup, maintenance, and email notifications. It is recommended you set up some periodical execution of these commands -- e.g., by crontab. The actual frequency depends on utilization of your system, but here are some recommendations:

Daily:

- `notifications:assignment-deadlines` will send emails to students (who actually allowed this in their configurations) about approaching deadlines. This is the most important command as it is directly related to ReCodEx operations.
- `fs:cleanup:worker` will remove old files that are exchanged between the core API and the worker backend (they are usually needed only for a few seconds during worker-core communication)
- `db:cleanup:uploads` will remove old uploaded files (files that were uploaded by user and then not used for anything)

Weekly:

- `notifications:general-stats` will send an email to all administrators with brief statistics about ReCodEx usage.

Monthly (or even less frequently):

- `db:cleanup:localized-texts` will remove old texts of groups and exercises
- `db:cleanup:exercise-configs` will remove old exercise configs
- `db:cleanup:exercise-files` will remove unused files (attachments and test files) of deleted exercises/assignments and pipelines
- `db:cleanup:pipeline-configs` will remove old pipeline configs

Annually:

- `users:remove-inactive` will soft-delete and anonymize users who are deemed inactive (i.e., they have not verified their credentials for a period of time that is set in core module config)

In all cases, the commands are recommended to be executed at a time when the system is not heavily used (e.g., at night).

Some details of these periodic commands (e.g., a threshold period of relevant assignment deadlines) can be configured in the neon config file. The thresholds should be set to values that correspond to the frequency of the command execution.

## Adminer

[Adminer](https://www.adminer.org/) is a full-featured database management tool written in PHP, and we have integrated it into this module. To use it, browse to the `/adminer` subdirectory in your project root (i.e., `https://<your-domain>/api/adminer`).

If you do not wish to use Adminer in production or need to provide an extra layer of security (e.g., making it accessible only from particular IPs), use your web server (Apache) configuration to do so.
