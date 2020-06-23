from sqlalchemy import create_engine
import configparser as cp
import traceback
import pandas as pd
import json
from clean import del_archive

config = cp.ConfigParser()
config.read('config.ini')

delete_post = config['var']['delete_post']


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
    # print(conn_string)
    engine = create_engine(conn_string, pool_size=10, max_overflow=20)
    return engine


def execute_sql_select(sql):
    """ Takes sql select query and executes, return rows fetched from database """
    engine = create_db_connection()
    with engine.connect() as con:
        res = con.execute(sql).fetchall()

    if len(res) == 1:
        res.append((-999999,))

    result = [x[0] for x in res]
    return tuple(result)


def delete_restaurant_and_food(rest_name, city_name):
    engine = create_db_connection()
    with engine.connect() as con:
        try:
            get_rest_id_query = '''
                 select id from wp_posts where post_type = 'ristorante' and post_title in( '{rest_name}')
                and id in (select object_id from wp_term_relationships where term_taxonomy_id in(select term_id from wp_terms where name ='{city}' and term_id in (select term_id from wp_term_taxonomy where taxonomy='city')
                ))
                '''.format(rest_name=rest_name, city=city_name)

            get_rest_id = execute_sql_select(get_rest_id_query)

            # get_rest_id = (24392, 24420, 24607)

            get_piatto_id_query = '''
                  select id from wp_posts where post_type = 'piatto' and id in (select post_id from wp_postmeta where meta_key = 'restaurant_associated' and meta_value in {rest_id})
                '''.format(rest_id=get_rest_id)

            get_piatto_id = execute_sql_select(get_piatto_id_query)

            delete_association_query = '''
                delete from wp_term_relationships where object_id in {id};
                '''.format(id=get_piatto_id + get_rest_id)

            # print(delete_association_query)

            delete_piatto_rest_query = '''
                delete from wp_posts where id in {id};
                '''.format(id=get_piatto_id + get_rest_id)

            con.execute(delete_association_query)
            con.execute(delete_piatto_rest_query)

            print("Restaurant : ", rest_name, "of city: ", city_name,
                  " has been deleted permanently, also all foods associated with it")
        except:
            print("Restaurant : ", rest_name, "of city: ", city_name,
                  "is not found. Please check before re-run.")
            pass


def delete_food_data(rest_name, city_name, food_name):
    engine = create_db_connection()
    with engine.connect() as con:
        try:
            get_rest_id_query = '''
                         select id from wp_posts where post_type = 'ristorante' and post_title in( '{rest_name}')
                        and id in (select object_id from wp_term_relationships where term_taxonomy_id in(select term_id from wp_terms where name ='{city}' and term_id in (select term_id from wp_term_taxonomy where taxonomy='city')
                        ))
                        '''.format(rest_name=rest_name, city=city_name)

            get_rest_id = execute_sql_select(get_rest_id_query)

            get_piatto_id_query = '''
                      select id from wp_posts where post_type = 'piatto' and post_title in ('{food_name}') and id in (select post_id from wp_postmeta where meta_key = 'restaurant_associated' and meta_value in {rest_id});
                    '''.format(rest_id=get_rest_id, food_name=food_name)

            get_piatto_id = execute_sql_select(get_piatto_id_query)

            delete1 = '''delete from wp_term_relationships where object_id in {id};'''.format(id=get_piatto_id)
            delete2 = '''delete from wp_postmeta where post_id in {id};'''.format(id=get_piatto_id)
            delete3 = '''delete from wp_posts where id in {id};'''.format(id=get_piatto_id)

            con.execute(delete1)
            con.execute(delete2)
            con.execute(delete3)

            print("Data deleted successfully for piatto: ", food_name)
        except:
            print("Not able to find piatto: ", food_name, ". Please check once, before re-run.")
            pass


if __name__ == '__main__':

    try:
        delete_df = pd.read_excel(delete_post, header=1)

        delete_df = delete_df[['Restaurant_Name', 'City', 'Food_Name']]

        delete_df_json_str = delete_df.to_json(orient='records')

        delete_df_json = json.loads(delete_df_json_str)

        for i in range(0, len(delete_df_json)):

            if delete_df_json[i]['Food_Name'] is not None:

                delete_food_data(rest_name=delete_df_json[i]['Restaurant_Name'], city_name=delete_df_json[i]['City'],
                                 food_name=delete_df_json[i]['Food_Name'])
            else:

                delete_restaurant_and_food(rest_name=delete_df_json[i]['Restaurant_Name'],
                                           city_name=delete_df_json[i]['City'])

        success = True

    except Exception as e:
        success = False
        err = e

    if success:
        del_archive()
    else:
        print(err)
