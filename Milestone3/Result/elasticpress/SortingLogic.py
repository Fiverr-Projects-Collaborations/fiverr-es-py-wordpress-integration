from itertools import combinations, permutations
import re


def filter_data(data_set, searchText):
    result = []
    for data in data_set:
        present = True
        for x in searchText.split(" "):
            if x in data:
                present = present and True
            else:
                present = present and False
        if present:
            result.append(data)
    return result


def score_data(searchText, data, searchTextList):
    print('###### for data ####', data)
    score = 0
    isFullText = False
    data_stripped = re.sub(r"[^0-9a-zA-Z ]", "", data)
    if searchText in data:
        isFullText = True
    print(searchTextList)
    for y in searchTextList:
        # KPI2
        if y in data:
            score = (score + len(y.split(" ")) / 2)
            print("score 1:", len(y.split(" ")) / 2)
        if y in searchText:
            score = (score + len(y.split(" ")) / 2)
            print("score 2:", len(y.split(" ")) / 2)
    new_l = data.split(",")
    # print(new_l)
    for y in new_l:
        y = y.strip()
        # print("#",searchTextList)
        # print("$",y)
        if y in searchTextList:
            score = (score + len(y.split(" ")))
            print("score 3:", len(y.split(" ")))
        if all(item1 in searchText.split(" ") for item1 in y.split(" ")):
            score = (score + len(y.split(" ")))
            print("score 4:", len(y.split(" ")))
        if any(item1 in searchText.split(" ") for item1 in y.split(" ")):
            score = (score + len(y.split(" ")))
            print("score 4:", len(y.split(" ")))
        if all(item1 in y.split(" ") for item1 in searchText.split(" ")):
            score = (score + len(searchText.split(" ")))
            print("score 5:", len(y.split(" ")))
    if isFullText:
        score = score + (1.5 * len(searchText.split(" ")))
        print("score 6:", (1.5 * len(searchText.split(" "))))
    print('###### for data ####', data)
    return score


if __name__ == '__main__':
    scores = {1: 3.5, 2: 11, 3: 20.5, 4: 34, 5: 52.5, 6: 77, 7: 108.5, 8: 148, 9: 196.5, 10: 255}
    searchText = "funghi cardiofi"
    #data_set = ["A B C", "A B C D", "A B C D", "A B C, D", "A B, C, D", "A C D, B", "A C B, D", "B C D, A", "B D","A B D", "B D A", "B A D"]
    data_set = ["Mozzarella fior di latte di agerola, pomodoro, olio, uovo, carciofi, funghi e prosciutto cotto ~ Pizza, mozzarella fior di latte, pomodoro, olio, uovo, carciofi, funghi, prosciutto cotto, vegetariana", "Mozzarella fior di latte di agerola, pomodoro, olio, prosciutto cotto, funghi e carciofi ~ Pizza quattro stagioni, mozzarella, pomodoro, olio, prosciutto cotto, funghi, carciofi"]
    searchTextList_l = []
    for i in range(2, len(searchText)):
        searchTextList_l.extend([' '.join(list(x)) for x in combinations(searchText.split(" "), i)])
    print(searchTextList_l)
    #result = filter_data(data_set, searchText)
    result = data_set
    print(result)
    result_dic = {x: score_data(searchText, x, searchTextList_l) for x in result}
    print(result_dic)
