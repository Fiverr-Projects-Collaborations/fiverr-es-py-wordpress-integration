import configparser as cp
import shutil
from datetime import datetime
import os

config = cp.ConfigParser()
config.read('config.ini')


def rest_archive():
    rest_path = config['var']['rest_insert_file_path']
    rest_file_name = rest_path.split('/')[-1]
    rest_file_name_ar = str(datetime.now()) + '_I_' + rest_file_name
    shutil.move(rest_path, "archive/" + rest_file_name_ar)


def food_archive():
    food_path = config['var']['food_insert_file_path']
    food_file_name = food_path.split('/')[-1]
    food_file_name_ar = str(datetime.now()) + '_I_' + food_file_name
    shutil.move(food_path, "archive/" + food_file_name_ar)


def del_archive():
    delete_post = config['var']['delete_post']
    delete_post_file_name = delete_post.split('/')[-1]
    delete_post_file_name_ar = str(datetime.now()) + '_D_' + delete_post_file_name
    shutil.move(delete_post, "archive/" + delete_post_file_name_ar)


def remove_downloaded_media():
    media_path = config['var']['media_path']
    files = os.listdir(media_path)
    for item in files:
        if item.endswith(".jpeg"):
            os.remove(os.path.join(media_path, item))
