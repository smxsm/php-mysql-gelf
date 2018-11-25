# MySQL 2 Graylog

This lib is based on https://github.com/arikogan/mysql-gelf

## Installation and usage

Copy the files to your server, copy _"conf/settings.ini.dist"_ to _"conf/settings.ini"_ and adjust the values for your MySQL database and your Graylog server.

You can set

```bash
OUTPUT_JSON = 1
```

to only echo the GELF Json message. It is not sent to Graylog then - you can use that for polling the messages from the server.

The full settings are:

```bash
MYSQL_HOST = "mysql"
MYSQL_PORT = "3306"
MYSQL_USER = "root"
MYSQL_PASS = "root"
# if OUTPUT_JSON is true, the GELF message is echoed as JSON
# and NOT sent to Graylog! Use for polling from the Graylog server
OUTPUT_JSON = 0
GRAYLOG_SERVER = "my.graylogserver.org"
GRAYLOG_PORT = 12345
DEBUG = 0
```

If you set `DEBUG = 1` the GELF message will always be printed.

## Sending via UDP socket

If you want to send the messages via UDP socket, set `OUTPUT_JSON = 0`. You can setup a Cronjob to call the script e.g. every minute.

## Polling the script from outside

If you want to poll the script from your remote Graylog server, set `OUTPUT_JSON = 1` and just put the folder in some directory which is reachable via URL from outside (you should secure it e.g. via Basic Auth or something!). In your Graylog input you can set the frequency below one minute, so you could call it e.g. every 10 seconds.
For polling, you can use my [Graylog Input Plugin](https://github.com/smxsm/graylog-json-remote-poll).