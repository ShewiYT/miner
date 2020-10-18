<?php
$config = [
    'access_token' => '67eee5795c7b937f0b42396e69e8509a72c7cb1231df9220848199ee3ca20262496f835bd6b3cbc5dc9c1',	// Токен от vk admin ( получить тут https://vkhost.github.io/ )
    'reconnect' => true,	// Переподключение: true/on - false/off
    'smartbuy' => false,		// Умный магазин: true/on - false/off (если включен перевод коинов на id тот что ниже то ставить false)
    'send_when' => 0,		// Сумма по достижению которой отправлять на id ниже. (если не надо то 0)
    'send_id' => 0,			// id куда переводить коины при достижение иной суммы указаной выше. (если не надо то 0)
    'v' => '5.103'
];
include('classes.php');
$CMD = new CMD();
function _request($method = '', $params = [], $access_token = '')
{
    global $config;
    if (empty($access_token)) {
        $access_token = $config['access_token'];
    }
    $request_params = ['access_token' => $access_token, 'v' => $config['v']] + $params;
    return file_get_contents('https://api.vk.com/method/' . $method, false, stream_context_create(['http' => ['method' => 'POST', 'header' => 'Content-type: application/x-www-form-urlencoded', 'content' => http_build_query($request_params)]]));
}

$CMD->setTitle('Miner - CoronaCoin', true);
$CMD->write('Проверка валидности access token... ');
$token_result = json_decode(_request('account.getAppPermissions'));
if (isset($token_result->response)) {
    $CMD->writeln('Успех!');
    $my_id = json_decode(_request('users.get'))->response[0]->id;
} else {
    $CMD->writeln('Ошибка! (' . $token_result->error->error_msg . ')');
    $ERROR = TRUE;
}
unset($token_result);
if ($ERROR) exit();
else $CMD->cls();
unset($ERROR);

$corona_url = json_decode(_request('apps.get', ['app_id' => '7349811']))->response->items[0]->mobile_iframe_url;
if (!empty($corona_url)) {
    file_get_contents(str_replace('https://corona-coins.ru/', 'https://corona-coins.ru/api/server/', $corona_url) . '&action=mining');
    $corona_wss = str_replace('https://corona-coins.ru/', 'wss://corona-coins.ru/api/', $corona_url);
    $yes_connect = false;
    $pos = 0;
    $coins = 0.000;
    $online = 0;
    $cookies = 0;
    $click_speed = 0;
    $speed = 0;
    $update_rating_time = 0;
    $last_top_coins = 0;
    connect: $client = new WSClient($corona_wss, ['user-agent' => 'Mozilla/5.0 (Linux; Android 5.0; SM-G900P Build/LRX21T) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/81.0.4044.138 Mobile Safari/537.36']);
    $client->connect();
    while (1) {
        if ($client->isConnected()) {
            $response_wss = $client->receive();
            if (!empty($response_wss) and $response_wss !== false) {
                $yes_connect = true;
                $CMD->setTitle("Miner - CoronaCoin | Онлайн: " . number_format($online, 0, '', ' ') . " | Топ: " . number_format($pos, 0, '', ' ') . " | Клик: +" . number_format($click_speed / 1000, 3, ',', ' ') . "/клик | Скорость: +" . number_format($speed / 1000, 3, ',', ' ') . "/сек | Коины: " . number_format($coins / 1000, 3, ',', ' ') . " CC | Печеньки: " . number_format($cookies, 0, '', ' '), true);
                $data = json_decode($response_wss);
                switch ($data->event) {
                    case "init":
                        $coins = $data->data->coins;
                        $cookies = $data->data->cookies;
                        break;
                    case "update_online":
                        $online = $data->online;
                        break;
                    case "update_rating":
                        if (isset($data->current)) {
                            if (isset($data->current->pos)) $pos = $data->current->pos;
                            if (isset($data->current->coins)) $coins = $data->current->coins;
                        } else {
                            $users = json_decode(json_encode($data->data), true);
                            foreach ($users as $key => $user) {
                                if ($user['id'] == $my_id) {
                                    $pos = $key + 1;
                                    if (isset($user['coins']) and $last_top_coins != $user['coins']) {
                                        $last_top_coins = $user['coins'];
                                        $coins = $user['coins'];
                                    }
                                    break;
                                }
                            }
                        }
                        $CMD->writeln('Позиция в топе: ' . number_format($pos, 0, '', ' ') . ' | Коины: ' . number_format($coins / 1000, 3, ',', ' ') . ' CC');
                        break;
                    case "update_info":
                        $coins = $data->data->coins;
                        $cookies = $data->data->cookies;
                        break;
                    case "show_notify":
                        $CMD->writeln('УВЕДОМЛЕНИЕ | ' . $data->notify->message);
                        break;
                    case "new_transfer":
                        $CMD->writeln('Поступил перевод от @id' . $data->from_id . ' на ' . number_format($data->amount / 1000, 3, ',', ' ') . ' CC');
                        $coins += $data->amount;
                        break;
                    case "buy_boosters":
                        if ($data->response === true) {
                            $CMD->writeln("Умным магазином было куплено \"" . $smart_buy['name'] . "\" за " . number_format($smart_buy['need'] / 1000, 3, ',', ' ') . " CC");
                            $coins -= $smart_buy['need'];
                            $last_smart_buy = time();
                            unset($smart_buy);
                        } else $smart_buy['buying'] = false;
                        $CMD->writeln($response_wss);
                        break;
                    case "update_boosters":
                        $speed = $data->data->speed->sec;
                        $click_speed = $data->data->speed->click;
                        $list = json_decode(json_encode($data->data->list), true);
                        unset($items);
                        $items = [];
                        foreach ($list as $item) {
                            array_push($items, ['name' => $item['name'], 'speed' => $item['speed'], 'cost' => $item['price']['sec']['amount'], 'equ' => floor($item['price']['sec']['amount'] / $item['speed'])]);
                        }
                        if ($config['smartbuy'] and !$smart_buy['buying']) {
                            $equ = [];
                            foreach ($items as $item) {
                                array_push($equ, $item['equ']);
                            }
                            $min_equ = min($equ);
                            foreach ($items as $i_id => $item) {
                                if ($item['equ'] == $min_equ) {
                                    break;
                                }
                            }
                            $smart_buy = [
                                'id' => $i_id + 1,
                                'name' => $items[$i_id]['name'],
                                'need' => $items[$i_id]['cost']
                            ];
                            unset($equ);
                            $CMD->writeln("Умным магазином было выявлено, что выгодно будет купить \"" . $smart_buy['name'] . "\" за " . number_format($smart_buy['need'] / 1000, 3, ',', ' ') . " CC");
                        }
                        break;
                }
            }
            if ((time() - $update_rating_time) >= 20) {
                $client->send(json_encode(['action' => 'update_rating', 'rating' => 'users']));
                $update_rating_time = time();
            }
            if ($config['smartbuy'] and $coins >= $smart_buy['need'] and isset($smart_buy) and (time() - $last_smart_buy) >= 5 and !$smart_buy['buying']) {
                $client->send(json_encode(['action' => 'buy_boosters', 'type' => '2', 'booster_id' => $smart_buy['id']]));
                $smart_buy['buying'] = true;
            }
            if ($config['send_id'] > 0 and $config['send_when'] > 0 and $config['send_when'] <= ($coins / 1000) and $coins > 0) {
                $client->send(json_encode(['action' => 'transfer', 'recipient_id' => $config['send_id'], 'amount' => $coins]));
				$coins = 0;
            }
        } else {
            if ($yes_connect and $client->getCloseStatus() !== 4000) {
                if ($config['reconnect']) {
                    $CMD->writeln($client->getCloseStatus());
                    $CMD->writeln('Подключние прервано, переподключаюсь...');
                    sleep(2);
                    unset($client);
                    goto connect;
                } else {
                    $CMD->writeln($client->getCloseStatus());
                    $CMD->writeln('Подключние прервано.');
                }
            } else {
                $CMD->writeln('Уже кто-то сидит в короне, переподключаюсь...');
                sleep(2);
                unset($client);
                goto connect;
            }
        }
    }
} else $CMD->writeln('Ошибка получения ссылки на корону, точно ли указан токен от клевера?');
