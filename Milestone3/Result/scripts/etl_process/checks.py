import configparser as cp
from sqlalchemy import create_engine
import pandas as pd
# --if restaurant exists


config = cp.ConfigParser()
config.read('config.ini')

def CreateDBConnection():
    """ Read database details from config.ini file to create database engine"""
    user = config['DB_Details']['user']
    pwd = config['DB_Details']['password']
    host = config['DB_Details']['host']
    db_name = config['DB_Details']['database']
    port = config['DB_Details']['port']

    conn_string = 'mysql+pymysql://{user}:{password}@{host}:{port}/{database}'.format(user=user, password=pwd,
                                                                                          host=host, database=db_name,port=port)
    # print(conn_string)
    engine = create_engine(conn_string, pool_size=10, max_overflow=20)
    return engine

def ExecuteSQLSelect(sql):
    """ Takes sql select query and executes, return rows fetched from database """
    engine = CreateDBConnection()
    with engine.connect() as con:

        res = con.execute(sql).fetchall()
    return res


def read_table(engine, table_name):
    df = pd.read_sql_table(table_name, engine)
    return df


def rest_check():
    rest_check_query = '''select p.id as id,
        p.post_title as restaurant,
        wt.term_id,
        wt.name as city
        from wp_posts p
        left join wp_term_relationships wtr
        on wtr.object_id = p.id
        left join wp_terms wt
        on wt.term_id= wtr.term_taxonomy_id
        join wp_term_taxonomy wtt 
        on wt.term_id= wtt.term_taxonomy_id
        and wtt.taxonomy='city'
        where p.post_type = 'ristorante'
        and p.post_status = 'publish'; '''

    table = pd.DataFrame(ExecuteSQLSelect(rest_check_query), columns=['id', 'restaurant','term_id','city_name'])
    return table


# --if piatto exists for a restaurant
def piatto_check():
    piatto_check_query = '''
    select p.id as piatto_id,
    p.post_title as piatto,
    r.id,
    r.post_title as restaurant,
    wt.term_id,
    wt.name
    from wp_posts p
    left join wp_postmeta pm
    on p.ID = pm.post_id
    and pm.meta_key = 'restaurant_associated'
    left join wp_posts r
    on pm.meta_value = r.id
    and r.post_type = 'ristorante'
    and r.post_status = 'publish'
    left join wp_term_relationships wtr
    on wtr.object_id = r.id
    left join wp_terms wt
    on wt.term_id= wtr.term_taxonomy_id
    join wp_term_taxonomy wtt
    on wt.term_id= wtt.term_taxonomy_id
    and wtt.taxonomy='city'
    where p.post_type = 'piatto'
    and p.post_status = 'publish';'''

    table = pd.DataFrame(ExecuteSQLSelect(piatto_check_query), columns=['piatto_id', 'piatto', 'post_id','restaurant_name','term_id','city_name'])
    return table


# --if city taxonomy exists
def city_taxonomy_check():
    city_tax_query = '''
        select t.name
        from wp_terms t
        join wp_term_taxonomy tt
        on t.term_id = tt.term_id
        where taxonomy = 'city';'''
    table = pd.DataFrame(ExecuteSQLSelect(city_tax_query), columns=['city_name'])
    return table


# -- A>B
# --if kind of restaurant and its parent exists taxonomy exists
def KORestuarant_parent_check():
    KORestuarant_parent_query = '''
        select t.name as kind_of_restaurant,
        pt.name as parent
        from wp_terms t
        join wp_term_taxonomy tt
        on t.term_id = tt.term_id
        left join wp_term_taxonomy p
        on tt.parent = p.term_id
        left join wp_terms pt
        on pt.term_id = p.term_id
        where tt.taxonomy = 'kind_of_restaurant';
        '''
    table = pd.DataFrame(ExecuteSQLSelect(KORestuarant_parent_query), columns=['city_name'])
    return table

# --if food local and its parent exists taxonomy exists

def food_local_parent_check():
    food_local_parent_query = '''
        select t.name as food_local,
        pt.name as parent
        from wp_terms t
        join wp_term_taxonomy tt
        on t.term_id = tt.term_id
        left join wp_term_taxonomy p
        on tt.parent = p.term_id
        left join wp_terms pt
        on pt.term_id = p.term_id
        where tt.taxonomy = 'food_local'; 
    '''
    table = pd.DataFrame(ExecuteSQLSelect(food_local_parent_query), columns=['food_local','parent'])
    return table




# --if food category and its parent exists taxonomy exists
def food_category_parent_check():
    food_category_parent_query = '''
        select t.name as food_category,
        pt.name as parent
        from wp_terms t
        join wp_term_taxonomy tt
        on t.term_id = tt.term_id
        left join wp_term_taxonomy p
        on tt.parent = p.term_id
        left join wp_terms pt
        on pt.term_id = p.term_id
        where tt.taxonomy = 'food_category';
    '''
    table = pd.DataFrame(ExecuteSQLSelect(food_category_parent_query), columns=['food_local', 'parent'])
    return table

if __name__ == '__main__':
    print(piatto_check().columns)

