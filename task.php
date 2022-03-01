<?php
/*
Ознакомительный пример работы с бизнес процессами с помощью REST Api.
Создан для партнерской конференции Битрикс 2016 Зима.
Автор: Строкатый Олег
Разработчик: Антон Янжула, Алексей Кирсанов

email для связи: os@bitrix.ru

© 1С-Битрикс
*/

$protocol = $_SERVER['SERVER_PORT'] == '443' ? 'https' : 'http';
$host = explode(':', $_SERVER['HTTP_HOST']);
$host = $host[0];

define('BP_DB_FILE', dirname(__FILE__).DIRECTORY_SEPARATOR.'task.db');
define('BP_APP_HANDLER', $protocol.'://'.$host.$_SERVER['REQUEST_URI']);

if (!file_exists(BP_DB_FILE))
	@touch(BP_DB_FILE);

if (!is_writable(BP_DB_FILE))
	die('Error: file '.BP_DB_FILE.' must be writable.');

$PDO = new PDO('sqlite:'.BP_DB_FILE);
$PDO->exec('CREATE TABLE IF NOT EXISTS bp_events (
			ID INTEGER  NOT NULL PRIMARY KEY AUTOINCREMENT,
			WORKFLOW_ID VARCHAR(255)  NOT NULL,
			TASK_ID INTEGER  NOT NULL DEFAULT 0,
			EVENT_DATA TEXT NOT NULL)'
);

function callB24Method(array $auth, $method, $params)
{
	$c = curl_init('https://'.$auth['domain'].'/rest/'.$method.'.json');
	$params["auth"] = $auth["access_token"];

	curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($c, CURLOPT_POST, true);
	curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($params));

	$response = curl_exec($c);
	$response = json_decode($response, true);

	return $response['result'];
}

function _log($title, $data)
{
	@file_put_contents(dirname(__FILE__).'/log.txt', '================ '.$title.' ================'.PHP_EOL.print_r($data,1).PHP_EOL);
}

if (!empty($_REQUEST['workflow_id']))
{
	if (empty($_REQUEST['auth']))
		die;

	$taskId = (int) callB24Method($_REQUEST['auth'], 'task.item.add', array(
		'TASKDATA' => array(
			'TITLE' => $_REQUEST['properties']['taskName'],
			'DESCRIPTION' => $_REQUEST['properties']['taskDescription'],
			'CREATED_BY' => str_replace('user_', '', $_REQUEST['properties']['taskCreator']),
			'RESPONSIBLE_ID' => str_replace('user_', '', $_REQUEST['properties']['taskUser'])
		)
	));

	$ar = array();
	foreach ($_REQUEST['properties']['checkList'] as $i => $checklist)
	{
		$ar[] = 'task.checklistitem.add?'.http_build_query(array(
			'TASKID' => $taskId,
			'FIELDS' => array(
				'TITLE' => $checklist,
				'IS_COMPLETE' => 'N',
				'SORT_INDEX' => 10*($i+1)
			)
		));
	}
	callB24Method($_REQUEST['auth'], 'batch', array('cmd' => $ar));

	$PDO->exec('INSERT INTO bp_events (WORKFLOW_ID, TASK_ID, EVENT_DATA) VALUES('
		.$PDO->quote($_REQUEST['workflow_id']).','.$taskId.' ,'.$PDO->quote(serialize($_REQUEST)).')');
	die;
}

if (!empty($_REQUEST['data']['FIELDS_BEFORE']['ID']))
{
	if (empty($_REQUEST['auth']))
		die;

	$taskId = (int) $_REQUEST['data']['FIELDS_BEFORE']['ID'];

	$workflowData = $PDO->query('SELECT * FROM bp_events WHERE TASK_ID = '.$taskId.' LIMIT 1')->fetch(PDO::FETCH_ASSOC);
	if (!$workflowData)
		die;

	$taskData = callB24Method($_REQUEST['auth'], 'task.item.getdata', array(
		'TASKID' => $taskId
	));

	if ($taskData['REAL_STATUS'] != 5)
		die;

	$checklistData = callB24Method($_REQUEST['auth'], 'task.checklistitem.getlist', array(
		'TASKID' => $taskId
	));

	$checkStatus = 'Y';

	foreach ($checklistData as $item)
	{
		if ($item['IS_COMPLETE'] == 'N')
		{
			$checkStatus = 'N';
			break;
		}
	}

	$workflowEvent = unserialize($workflowData['EVENT_DATA']);

	callB24Method($_REQUEST['auth'], 'bizproc.event.send', array(
		"EVENT_TOKEN" => $workflowEvent["event_token"],
		"RETURN_VALUES" => array(
			'checkStatus' => $checkStatus
		),
		'LOG_MESSAGE' => 'Проверка завершена. Результат: '.($checkStatus=='Y'? 'одобрено' : 'отклонено')
	));

	$PDO->query('DELETE FROM bp_events WHERE ID = '.$workflowData['ID']);
	die;
}

if (!empty($_GET['clean']))
{
	$PDO->query('DELETE FROM bp_events');
	@unlink(dirname(__FILE__).'/log.txt');
	die;
}

header('Content-Type: text/html; charset=UTF-8');

if (!empty($_GET['test']))
{
	$sth = $PDO->query('SELECT * FROM bp_events');
	$rows = $sth->fetchAll(PDO::FETCH_ASSOC);

	foreach ($rows as &$row)
	{
		$row['EVENT_DATA'] = unserialize($row['EVENT_DATA']);
	}

	echo '<pre>';
	print_r($rows);

	if (file_exists(dirname(__FILE__).'/log.txt'))
	{
		echo '------------------------- LOG -------------------------'.PHP_EOL;
		echo htmlspecialchars(file_get_contents(dirname(__FILE__).'/log.txt'));
	}

	die;
}
?><!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<title></title>
</head>
<body>
<script src="//api.bitrix24.com/api/v1/"></script>
<h1>Проверка контрагента</h1>
<button onclick="installActivity1();">Установить действие БП</button>
<button onclick="uninstallActivity1();">Удалить действие</button>
<script type="text/javascript">
	BX24.init(function()
	{
		//BX24 ready.
	});

	function installActivity1()
	{
		var params = {
			'CODE': 'task',
			'HANDLER': '<?=BP_APP_HANDLER?>',
			'AUTH_USER_ID': 1,
			'USE_SUBSCRIPTION': 'Y',
			'NAME': 'Проверка контрагента',
			'DESCRIPTION': 'Задача юристу на проверку контрагента',
			'PROPERTIES': {
				'taskName': {
					'Name': 'Название задачи',
					'Type': 'string',
					'Required': 'Y',
					'Multiple': 'N',
					'Default': 'Проверить контрагента'
				},
				'taskDescription': {
					'Name': 'Описание задачи',
					'Type': 'text',
					'Required': 'Y',
					'Multiple': 'N'
				},
				'taskCreator': {
					'Name': 'Постановщик',
					'Type': 'user',
					'Required': 'Y',
					'Multiple': 'N'
				},
				'taskUser': {
					'Name': 'Исполнитель',
					'Type': 'user',
					'Required': 'Y',
					'Multiple': 'N'
				},
				'checkList': {
					'Name': 'Чек-лист',
					'Type': 'string',
					'Required': 'Y',
					'Multiple': 'Y'
				}
			},
			'RETURN_PROPERTIES': {
				'checkStatus': {
					'Name': 'Одобрено',
					'Type': 'bool',
					'Multiple': 'N',
					'Default': 'N'
				}
			}
		};

		BX24.callMethod(
			'bizproc.activity.add',
			params,
			function(result)
			{
				if(result.error())
					alert("Error: " + result.error());
				else
					alert("Успешно: " + result.data());
			}
		);

		BX24.callBind('OnTaskUpdate', '<?=BP_APP_HANDLER?>');

	}

	function uninstallActivity1()
	{
		var params = {
			'CODE': 'task'
		};

		BX24.callMethod(
			'bizproc.activity.delete',
			params,
			function(result)
			{
				if(result.error())
					alert('Error: ' + result.error());
				else
					alert("Успешно: " + result.data());
			}
		);

		BX24.callUnbind('OnTaskUpdate', '<?=BP_APP_HANDLER?>');
	}
</script>
</body>
</html>