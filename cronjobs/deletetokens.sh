#!/bin/bash
mysql --login-path=local it490 < deletetokens.sql
echo "$(date): successfully purged expired tokens." >> /home/lucas/git/cronjobs/log.txt

