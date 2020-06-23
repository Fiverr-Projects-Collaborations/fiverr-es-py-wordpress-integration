import pandas as pd
import requests
import json
import numpy as np
import urllib.request
import cv2
import checks
from sqlalchemy import create_engine
import configparser as cp
from clean import remove_downloaded_media, rest_archive, food_archive
from taxonomies import term_taxonomies

config = cp.ConfigParser()
config.read('config.ini')

rest_url = config['var']['rest_url']
food_url = config['var']['food_url']
media_url = config['var']['media_url']
auth = config['var']['Auth']
food_path = config['var']['food_insert_file_path']
rest_path = config['var']['rest_insert_file_path']


def create_db_connection():
    """ Read database details from config.ini file to create database engine"""
    user = config['DB_Details']['user']
    pwd = config['DB_Details']['password']
    host = config['DB_Details']['host']
    db_name = config['DB_Details']['database']
    port = config['DB_Details']['port']

    conn_string = 'mysql+pymysql://{user}:{password}@{host}:{port}/{database}'.format(user=user, password=pwd,
                                                                                      port=port,
                                                                                      host=host, database=db_name)
    engine = create_engine(conn_string, pool_size=10, max_overflow=20)
    return engine


def execute_sql_select(sql):
    """ Takes sql select query and executes, return rows fetched from database """
    engine = create_db_connection()
    with engine.connect() as con:
        res = con.execute(sql).fetchall()
    return res


def url_to_image(url):
    # download the image, convert it to a NumPy array, and then read
    # it into OpenCV format
    image_name = url.split('/')[-1]
    resp = urllib.request.urlopen(url)
    image = np.asarray(bytearray(resp.read()), dtype="uint8")
    image = cv2.imdecode(image, cv2.IMREAD_COLOR)
    cv2.imwrite('Images/' + image_name, image)
    return image_name



def media_upload(url_img, title):
    image_name = url_to_image(url=url_img)
    url = media_url  # http://15.161.24.221/

    payload = {
        'title': title,
        'alt_text': title
    }
    files = [
        ('file', open('Images/' + image_name, 'rb'))
    ]
    headers = {
        'Content-Disposition': 'form-data;',
        'Authorization': auth,
        'Cookie': 'digits_countrycode=39'
    }

    response = requests.request("POST", url, headers=headers, data=payload, files=files).json()

    return response


def convert(o):
    if isinstance(o, np.int64): return int(o)
    raise TypeError


def insert_restaurant_data(json_data, url):
    image_list = []
    image_response_id = []
    if ';' in json_data['Restaurant Images']:
        image_list = json_data['Restaurant Images'].split(';')
    else:
        image_list.append(json_data['Restaurant Images'])

    for items in image_list:
        if 'http' in items:
            image_response_id.append(media_upload(items, json_data['Restaurant Title'])['id'])

    data_dict = {
        "title": json_data['Restaurant Title'],
        "content": json_data['City'],
        "status": "publish",
        "city": json_data['city'],
        "kind_of_restaurant": json_data['kind_of_restaurant'],

        'fields': {
            'relationship_key': json_data['Relationship Key'],
            'restaurant_address': json_data['Restaurant Address'],
            'restaurant_telephone': json_data['Restaurant Telephone'],
            'restaurant_ranking_weight': json_data['Restaurant Ranking Weight'],
            'restaurant_service_cost': json_data['Restaurant Service Cost'],
            'restaurant_notes': json_data['Restaurant Notes'],
            'restaurant_images': image_response_id,

        }
    }
    if len(image_response_id) > 0:
        data_dict['featured_media'] = image_response_id[0]

    header = {
        'Content-Type': 'application/json',
        'Authorization': auth
    }

    response = requests.post(url, headers=header, data=json.dumps(data_dict, default=convert)).json()

    return response


def create_food_data(json_data, rest_id, url, rest_name):
    image_list = []
    image_response_id = []
    if ';' in json_data['Food Images']:
        image_list = json_data['Food Images'].split(';')
    else:
        image_list.append(json_data['Food Images'])

    for items_ in image_list:
        if 'http' in items_:
            image_response_id.append(media_upload(items_, json_data['Food title'] + ' di ' + str(rest_name))['id'])

    data_dict = {
        "title": json_data['Food title'],
        "content": json_data['Food Category'],
        "status": "publish",
        'food_local': json_data['food_local'],
        'food_category': json_data['food_category'],
        'fields': {
            'restaurant_associated': rest_id,
            'food_keywords': json_data['Food Keywords'],
            'food_ingredients': json_data['Food Ingredients'],
            'food_description': json_data['Food Description'],
            'food_price': json_data['Food Price'],
            'food_images': image_response_id,
            'food_ranking_weight': json_data['Food Ranking Weight'],
            'food_notes': json_data['Food Notes'],
            'food_ingredients_excerpts': json_data['Food Ingredients Excerpts']
        }
    }

    if len(image_response_id) > 0:
        data_dict['featured_media'] = image_response_id[0]

    header = {
        'Content-Type': 'application/json',
        'Authorization': auth
    }
    response = requests.post(url, headers=header, data=json.dumps(data_dict, default=convert)).json()
    return response


def term_taxonomy_db():
    query = '''
            select t.term_id,t.name,taxonomy, p.name as parent_name
            from wp_terms t 
            join wp_term_taxonomy tt 
            on t.term_id = tt.term_id
            left join wp_terms p
            on p.term_id = tt.parent
            where taxonomy in( 'city','food_category','food_local','kind_of_restaurant'); 
            '''
    table = pd.DataFrame(execute_sql_select(query), columns=['term_id', 'name', 'taxonomy_name', 'parent_name'])
    return table


if __name__ == '__main__':

    try:
        food = pd.read_excel(food_path)
        rest = pd.read_excel(rest_path)

        #################### Taxnomoies #######################

        food_category = food['Food Category'].dropna().to_list()
        food_local = food['Food Local'].dropna().to_list()
        city = rest['City'].dropna().to_list()
        kind_of_restaurant = rest['Kind of Restaurant'].dropna().to_list()

        # ######### Taxonomy checks and inserts ################
        for items in list(set(food_category)):
            term_taxonomies(items, 'food_category')

        for items in list(set(food_local)):
            term_taxonomies(items, 'food_local')

        for items in list(set(city)):
            term_taxonomies(items, 'city')

        for items in list(set(kind_of_restaurant)):
            term_taxonomies(items, 'kind_of_restaurant')

        ################################### Inserting Restaurant and Food Data ##################

        term_taxonomy_id = term_taxonomy_db()

        rest_data = rest.copy()
        food_data = food.copy()

        rest_json_str = rest_data.to_json(orient='records')

        rest_json = json.loads(rest_json_str)

        ########## Inserting Restaurant Data ###################
        restaurant_id_name = []
        food_id_name = []
        for i in range(0, len(rest_json)):
            df_restaurant = pd.DataFrame(checks.rest_check())
            rest_content = rest_json[i]['City']
            rest_title = rest_json[i]['Restaurant Title'].strip()

            ################## City Data ######################################
            city = []
            var = term_taxonomy_id.loc[(term_taxonomy_id['name'] == rest_json[i]['City'].strip()) & (
                    term_taxonomy_id['taxonomy_name'] == 'city')]
            city.append(var['term_id'].values[0])
            rest_json[i]['city'] = city

            ########### Getting Kind of Restuarant term id's ####################
            kor = []
            if rest_json[i]['Kind of Restaurant'] != None:
                if ',' in rest_json[i]['Kind of Restaurant']:
                    kor = rest_json[i]['Kind of Restaurant'].split(',')
                else:
                    kor.append(rest_json[i]['Kind of Restaurant'])

            kor_term_id = []
            if len(kor) != 0:

                for items in kor:
                    parent, child = items.split('>')
                    kor_term_id.append(term_taxonomy_id.loc[(term_taxonomy_id['name'] == parent.strip()) & (
                            term_taxonomy_id['taxonomy_name'] == 'kind_of_restaurant')]['term_id'].values[0])

                    kor_term_id.append(term_taxonomy_id.loc
                                       [
                                           (term_taxonomy_id['name'] == child.strip())
                                           & (term_taxonomy_id['parent_name'] == parent.strip())
                                           & (term_taxonomy_id['taxonomy_name'] == 'kind_of_restaurant')
                                           ]['term_id'].values[0])

            rest_json[i]['kind_of_restaurant'] = list(set(kor_term_id))[::-1]

            ##############################################################################

            rest_json[i]['slug'] = rest_title.strip().replace('-', '').replace(' ', '-')

            rest_city_name = [(rest, city) for rest, city in
                              zip(df_restaurant['restaurant'], df_restaurant['city_name'])]

            rest_flag = False

            if ((rest_title.strip(), rest_content.strip()) not in rest_city_name):

                ########### Inserting new Restaurant Data ###################

                rest_response_ = insert_restaurant_data(rest_json[i], rest_url)
                print('\n\nRestaurant Inserted: ', type(rest_response_), '\n', rest_response_)
                rest_flag = True
            else:

                ######### Will update restaurnat if already exists ###########

                rest_match = df_restaurant.loc[(df_restaurant['restaurant'] == rest_title.strip())
                                               & (df_restaurant['city_name'] == rest_content.strip())]

                update_rest_url = rest_url + '/' + str(rest_match['id'].values[0])
                rest_response_ = insert_restaurant_data(rest_json[i], update_rest_url)
                print('\n\nRestaurant Updated: ', rest_response_)

            # # ##### Inserting Food Data ####################
            # #
            food = food_data.loc[food_data['Restaurant Key'] == rest_json[i]['Relationship Key']]
            food_json_str = food.to_json(orient='records')
            food_json = json.loads(food_json_str)

            for j in range(0, len(food_json)):

                df_food = pd.DataFrame(checks.piatto_check())
                df_restaurant = pd.DataFrame(checks.rest_check())

                food_content = food_json[j]['Food Category']
                food_title = food_json[j]['Food title'].strip()

                ########### Getting foodlocal term id's ####################
                foo_local = []

                if food_json[j]['Food Local'] is not None:
                    if ',' in food_json[j]['Food Local']:
                        foo_local = food_json[j]['Food Local'].split(',')
                    else:
                        foo_local.append(food_json[j]['Food Local'])

                food_local_term_id = []
                if len(foo_local) != 0:

                    for items in foo_local:
                        parent, child = items.split('>')
                        food_local_term_id.append(term_taxonomy_id.loc[(term_taxonomy_id['name'] == parent.strip()) & (
                                term_taxonomy_id['taxonomy_name'] == 'food_local')]['term_id'].values[0])

                        food_local_term_id.append(term_taxonomy_id.loc
                                                  [
                                                      (term_taxonomy_id['name'] == child.strip())
                                                      & (term_taxonomy_id['parent_name'] == parent.strip())
                                                      & (term_taxonomy_id['taxonomy_name'] == 'food_local')
                                                      ]['term_id'].values[0])

                food_json[j]['food_local'] = list(set(food_local_term_id))[::-1]

                food_cat = []
                food_cat_term_id = []

                if food_json[j]['Food Category'] is not None:
                    if ',' in food_json[j]['Food Category']:

                        food_cat = [x.strip() for x in food_json[j]['Food Category'].split(',')]
                        for items in food_cat:
                            var = term_taxonomy_id.loc[(term_taxonomy_id['name'] == items.strip()) & (
                                    term_taxonomy_id['taxonomy_name'] == 'food_category')]
                            food_cat_term_id.append(var['term_id'].values[0])
                    else:
                        var = term_taxonomy_id.loc[
                            (term_taxonomy_id['name'] == food_json[j]['Food Category'].strip()) & (
                                    term_taxonomy_id['taxonomy_name'] == 'food_category')]

                        food_cat_term_id.append(var['term_id'].values[0])

                food_json[j]['food_category'] = food_cat_term_id

                food_rest_name = [(food, rest, city) for food, rest, city in
                                  zip(df_food['piatto'], df_food['restaurant_name'], df_food['city_name'])]

                if (food_title.strip(), rest_title.strip(), rest_content.strip()) not in food_rest_name and rest_flag:

                    food_response = create_food_data(food_json[j], rest_response_['id'], food_url, rest_title.strip())
                    print('\n\nFood Inserted: ', food_response)

                elif (food_title.strip(), rest_title.strip(), rest_content.strip()) not in food_rest_name:

                    rest_id = df_restaurant.loc[df_restaurant['restaurant'] == rest_title.strip()]['id']

                    food_response = create_food_data(food_json[j], rest_id.values[0], food_url, rest_title)
                    print('\n\nFood Inserted: ', food_response)

                elif (food_title.strip(), rest_title.strip(), rest_content.strip()) in food_rest_name:

                    rest_id = \
                        df_restaurant.loc[df_restaurant['restaurant'] == rest_json[i]['Restaurant Title'].strip()]['id']

                    food_match = df_food.loc[(df_food['restaurant_name'] == rest_title.strip()) & (
                            df_food['city_name'] == rest_content.strip())
                                             & (df_food['piatto'] == food_title.strip())]
                    if food_match.shape[0] > 0:
                        update_food_url = food_url + '/' + str(food_match['piatto_id'].values[0])

                        food_response = create_food_data(food_json[j], rest_id.values[0], update_food_url, rest_title)

                        print('\n\nFood Updated: ', food_response)

        ###### Cleaning processed file #########
        remove_downloaded_media()
        rest_archive()
        food_archive()

    except Exception as e:
        print(str(e))
