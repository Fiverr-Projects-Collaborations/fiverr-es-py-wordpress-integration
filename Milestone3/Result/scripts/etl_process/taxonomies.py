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
                                                                                      host=host, database=db_name,
                                                                                      port=port)
    # print(conn_string)
    engine = create_engine(conn_string, pool_size=10, max_overflow=20)
    return engine


def ExecuteSQLSelect(sql):
    """ Takes sql select query and executes, return rows fetched from database """
    engine = CreateDBConnection()
    with engine.connect() as con:
        res = con.execute(sql).fetchall()
    return res


'''
    select t.term_id,t.name,taxonomy
    from wp_terms t 
    join wp_term_taxonomy tt 
    on t.term_id = tt.term_id
    where taxonomy in( 'city','food_category','food_local','kind_of_restaurant'); 
    '''


def parent_check(parent, table, taxonomy_name):
    df = table.loc[
        (table['taxonomy_name'] == taxonomy_name) & (table['term'] == parent.strip())]
    if df.shape[0] == 1:
        return True
    else:
        return False


def parent_insert(parent, taxonomy_name):
    try:
        engine = CreateDBConnection()
        get_max_term_id = 'select max(term_id) from wp_terms'
        term_id = int(ExecuteSQLSelect(get_max_term_id)[0][0]) + 1
        wp_term_query = '''insert into wp_terms values({term_id}, '{parent}', '{slug}', 0);''' \
            .format(term_id=term_id, parent=parent.strip(),
                    slug=parent.lower().replace(' ', '').replace(' ', '_'))

        wp_term_taxonomy_query = '''insert into wp_term_taxonomy values({term_id},{term_id},'{taxonomy_name}','',{tax_p_id}, 0);''' \
            .format(term_id=term_id, taxonomy_name=taxonomy_name, tax_p_id=0)
        with engine.connect() as con:
            con.execute(wp_term_query)
            con.execute(wp_term_taxonomy_query)
        return True

    except:
        return False


def get_child_parent_id(parent, child, table, taxonomy_name):
    parent_id = table.loc[(table['term'] == parent) & (table['taxonomy_name'] == taxonomy_name)]
    if parent_id.shape[0] == 0:
        return 'Not Found'
    else:
        return parent_id['term_id'].values[0]


def child_check(parent, child, table, param):
    df = table.loc[
        (table['term'] == child.strip()) & (table['taxonomy_name'] == param) & (table['parent_name'] == parent.strip())]
    return df.shape


def insert_child_data(parent, child, parent_id, taxonomy_name):
    engine = CreateDBConnection()
    get_max_term_id = 'select max(term_id) from wp_terms'
    term_id = int(ExecuteSQLSelect(get_max_term_id)[0][0]) + 1

    try:
        wp_term_query = '''insert into wp_terms values({term_id}, '{child}', '{slug}', 0);''' \
            .format(term_id=term_id, child=child.strip(),
                    slug=child.lower().replace(' ', '').replace(' ', '_'))

        wp_term_taxonomy_query = '''insert into wp_term_taxonomy values({term_id},{term_id},'{taxonomy_name}','',{tax_p_id}, 0);''' \
            .format(term_id=term_id, taxonomy_name=taxonomy_name, tax_p_id=parent_id)

        with engine.connect() as con:
            con.execute(wp_term_query)
            con.execute(wp_term_taxonomy_query)
        return True
    except:
        return False


def get_term_id():
    query = '''
            select t.term_id,t.name,taxonomy, p.name as parent_name
            from wp_terms t 
            join wp_term_taxonomy tt 
            on t.term_id = tt.term_id
            left join wp_terms p
            on p.term_id = tt.parent
            where taxonomy in( 'city','food_category','food_local','kind_of_restaurant'); 
            '''
    table = pd.DataFrame(ExecuteSQLSelect(query), columns=['term_id', 'term', 'taxonomy_name', 'parent_name'])
    return table


def term_taxonomies(term, taxonomy_name):
    print(term, taxonomy_name)
    query = '''
        select t.term_id,t.name,taxonomy, p.name as parent_name
        from wp_terms t 
        join wp_term_taxonomy tt 
        on t.term_id = tt.term_id
        left join wp_terms p
        on p.term_id = tt.parent
        where taxonomy in( 'city','food_category','food_local','kind_of_restaurant'); 
        '''
    table = pd.DataFrame(ExecuteSQLSelect(query), columns=['term_id', 'term', 'taxonomy_name', 'parent_name'])

    if taxonomy_name == 'city':

        if parent_check(term.strip(), table, taxonomy_name):
            print("Parent Term Already Exists", term)
        else:
            if parent_insert(term.strip(), taxonomy_name):
                print("Data inserted scusessfully for", "term: ", term, 'taxonomy: ', taxonomy_name)

    elif taxonomy_name == 'food_category':
        if ',' in term:
            foocat = term.split(',')
            for items in foocat:
                if parent_check(items.strip(), table, taxonomy_name):
                    print("Parent Term Already Exists", items)
                else:
                    if parent_insert(items.strip(), taxonomy_name):
                        print("Data inserted scusessfully for", "term: ", items, 'taxonomy: ', taxonomy_name)

        # print("food_category")
    # elif taxonomy_name == 'food_local':
    #     print("food_local")

    elif taxonomy_name == 'kind_of_restaurant' or taxonomy_name == 'food_local':
        kor = []
        if ',' in term:
            kor = term.split(',')
        else:
            kor.append(term)
        for items in kor:
            parent, child = items.split('>')
            parent_ = parent.strip()
            child_ = child.strip()
            if parent_check(parent_, table, taxonomy_name):
                print("Parent Term Already Exists", parent)
            else:
                if parent_insert(parent_, taxonomy_name):
                    print("Data inserted scusessfully for", "term: ", parent_, 'taxonomy: ', taxonomy_name)

            if child_check(parent_, child_, table, taxonomy_name)[0]:
                print("Child Term Already exists.", child_)
            else:
                parent_id = get_child_parent_id(parent_, child_, table, taxonomy_name)
                if parent_id != 'Not Found':
                    if insert_child_data(parent_, child_, parent_id, taxonomy_name):
                        print("Data Sucessfully inserted for: ", 'parent', parent_, 'child', child_, 'parent_id',
                              parent_id, taxonomy_name)
                else:
                    print("Parent ID not Found, insert Parent first", parent_)


# def wp_term(term,table):

if __name__ == '__main__':
    # rest = pd.read_excel('Data/RESTAURANT_v3.xlsx')

    json_ = get_term_id()
    print(json_)
