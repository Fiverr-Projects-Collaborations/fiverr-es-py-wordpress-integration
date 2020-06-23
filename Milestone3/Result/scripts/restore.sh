#restore script
#path -> /home/bitnami/scripts/restore.sh
#command to run -> ./restore.sh 2020-01-01 0400
#1st argument to file is date to restore, 2nd argument can be 0400 or 1600 based on when was which backup file of the day
/opt/bitnami/ctlscript.sh stop

dir=$1
cd /home/bitnami/snapshots/$dir
file=$2
rm -rf /tmp/bitnami-backup
sudo mv /opt/bitnami /tmp/bitnami-backup
sudo tar -pxzvf application-backup_$file.tar.gz -C /
sudo chown bitnami:daemon -R /opt/bitnami/*
sudo chmod g+w -R  /opt/bitnami/*
sudo chown root:root /opt/bitnami/nginx/conf/server*
sudo chmod 600 /opt/bitnami/nginx/conf/server*

/opt/bitnami/ctlscript.sh start
