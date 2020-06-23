#backup script
#path -> /home/bitnami/scripts/backup.sh
#command to run -> ./backup.sh

cd /home/bitnami/snapshots
dir=`date "+%Y-%m-%d"`
del_dir=`date --date="3 days ago" +%Y-%m-%d`
file="0400"
if [[ -d $dir ]]; then
  file="1600"
else
  mkdir $dir
fi
cd $dir
if [[ -d /home/bitnami/snapshots/$del_dir ]]; then
  rm -rf /home/bitnami/snapshots/$del_dir
else
  echo "Nothing to delete"
fi
sudo chown bitnami:daemon -R /opt/bitnami/*
sudo chmod g+w -R  /opt/bitnami/*
tar -pczvf application-backup_$file.tar.gz /opt/bitnami
