######## Files included ########

1. ElasticPress plugin updated file:
    a. 7-0.php - contains mapping for elasticsearch
      original location in WP server - /wp-content/plugins/elasticpress/includes/mappings/post/7-0.php
    b. Post.php - contains search query for elasticsearch
      original location in WP server - /wp-content/plugins/elasticpress/includes/classes/Indexable/Post/Post.php
2. Backup/Restore Scripts
    a. backup.sh
        original location in WP server - /home/bitnami/scripts/backup.sh
        Its automated script to take backup at 04:00 hrs and 16:00 hrs each day. Backups might exists for 2 days.
        Backups are stored in /home/bitnami/snapshots with Date directory. Each directory has two files for two backups taken.
    b. restore.sh
        original location in WP server - /home/bitnami/scripts/restore.sh
        command to run: /home/bitnami/scripts/restore.sh 2020-06-06 0400
        NOTE: There is a downtime 0-1 hrs if you try to restore.
3. finalSampleQuery.json - Just a simple query to show how searching a term, creates an elasticsearch query in wordpress.
4. Python ETL Script
    a. script.py - this is placed in /home/bitnami/scripts/etl_process/ in WP server.
    Picks files from /home/bitnami/scripts/etl_process/Data directory in WP Server to load/update/delete data into wp.
    Once script is done, the input files are moved to /home/bitnami/scripts/etl_process/archive in WP server with datetime.
    Generates logs in /home/bitnami/scripts/etl_process/logs directory in WP server.
    b. config.ini - this is also placed in /home/bitnami/scripts/etl_process/ in WP server. Used by py script for external variables.
    c. delete_script.py - to remove piatto/restaurant.
    NOTE: File format to delete is given as in sample file RESTAURANT_v3_D.xlsx
          No food item in the list means all foods to be deleted for the restaurant along with restaurant.
          Otherwise specific food for restaurant is deleted only.
    d. other .py files - dependencies for script.py
5. Installed certificate.txt - This contains info on installation of certificate for AWS bitnami image for wordpress.
6. www.idlike.app-post-migration-20200610-102634-0an3fc.wpress - idlike migration file taken using all-in-one-wp-migration on 10th June 2020
    wp-reset plugin license : 04272C79-0D4784F6-FA67353C

------------------------------------------------------------------------------------------------------------------------------------------------

######## WP/ETL SERVER ########

cron configuration-
00 4 * * * /home/bitnami/scripts/backup.sh

command to check crons -
crontab -l  => list all crons
crontab -e  => edit crons

Directories-
/home/bitnami/scripts/  => to keep scripts (etl_process/ backup/ restore)
/home/bitnami/scripts/etl_process/Data  => to keep input xlsx files. eg: food.csv
/home/bitnami/scripts/archive  => to keep processed xlsx files eg: food_i_2020-06-07.csv, food_d_2020-06-07.csv where i is insert/update, d is delete
/home/bitnami/scripts/logs  => to keep logs for each eligible run eg: job_2020_06_07_08_02_33.log
/home/bitnami/snapshots  => to keep backup of wordpress zip files eg: 2020-06-07/application-backup_1.tar.gz

command to ssh to wp/etl server-
ssh -i idlike-milano.pem bitnami@15.161.6.112  => to ssh into wp/etl server
NOTE: These details can be used to FTP also.

idlike.app
user: migration_account
password: aFi#VuiGz0@SL&&b)M(IUCFC

--stopwords info for reference
https://github.com/6/stopwords-json/tree/master/dist
file to update for stopwords in WP Server -
/wp-content/plugins/elasticpress/includes/classes/Indexable/Post/Post.php  => line 1114


IMPORTANT NOTE: If deleting migration_account, generate key for admin user to use wp apis, so that etl is not effected.
1. go to https://www.base64encode.org
2. paste user:password
eg: migration_account:aFi#VuiGz0@SL&&b)M(IUCFC
3. click on encode.
4. Copy the token in config file.
/home/bitnami/scripts/etl_process/config.ini


FTP
key - idlike-milano.pem
user - bitnami
path for wp dir - /opt/bitnami/apps/wordpress/htdocs
path for etl dir - /home/bitnami/scripts/etl_process


Commands to run etl scripts-
1. insert/update =>
nohup /usr/local/bin/python3.7 /home/bitnami/scripts/etl_process/script.py > logs/logs.txt &
2. delete
nohup /usr/local/bin/python3.7 /home/bitnami/scripts/etl_process/delete_script.py > logs/delete_logs.txt &


------------------------------------------------------------------------------------------------------------------------------------------------

######## ELASTICSEARCH SERVER ########

command to ssh to elasticsearch server-
ssh -i idlike-milano.pem ec2-user@15.161.79.20  => to ssh into elasticsearch server
NOTE: These details can be used to FTP also.

Directories-
/etc/elasticsearch/analysis/synonym.txt  =>  synonyms list
NOTE: Restart elasticsearch if adding synonyms

basic commands for elasticsearch -
systemctl stop elasticsearch => stop elasticsearch
systemctl start elasticsearch => start elasticsearch
systemctl restart elasticsearch => restart elasticsearch
NOTE: You can also start elasticsearch by rebooting ec2

FTP
key - idlike-milano.pem
user - ec2-user
path for synonym file - /etc/elasticsearch/analysis

------------------------------------------------------------------------------------------------------------------------------------------------
