<?php

require_once './vendor/autoload.php';

$cfg = [
    'appId' => 'in the config',
    'appSecret' => 'in the config',
];
require_once './config.php';


function getClient($appName, $scopes, $appId, $appSecret) {
    $client = new Google_Client();
    $client->setApplicationName($appName);
    $client->setScopes($scopes);
    $client->setClientId($appId);
    $client->setClientSecret($appSecret);
    $client->setRedirectUri(getCurrentUrl());

    return $client;
}


function getCurrentUrl($queryParams = false)
{
    $php_request_uri = ($queryParams)
        ? htmlentities(
                substr($_SERVER['REQUEST_URI'], 0, strcspn($_SERVER['REQUEST_URI'], "\n\r")),
                ENT_QUOTES
            )
        : dirname($_SERVER['PHP_SELF']).'/';
    $protocol = (isset($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) == 'on')
        ? 'https://'
        : 'http://';
    $host = $_SERVER['HTTP_HOST'];
    $port = ($_SERVER['SERVER_PORT'] != '' &&
        (($protocol == 'http://' && $_SERVER['SERVER_PORT'] != '80') ||
            ($protocol == 'https://' && $_SERVER['SERVER_PORT'] != '443')))
        ? ':' . $_SERVER['SERVER_PORT']
        : '';
    return $protocol . $host . $port . $php_request_uri;
}



function lastDay($month = '', $year = '', $format = 'Y-m-d H:i:s')
{
   if (empty($month))
      $month = date('m');
   if (empty($year))
      $year = date('Y');
   $result = strtotime("{$year}-{$month}-01");
   $result = strtotime('-1 second', strtotime('+1 month', $result));
   return date($format, $result);
}


session_start();
header('Content-type: text/html; charset=utf-8');

$gClient = getClient(
    'GCal Billing', Google_Service_Calendar::CALENDAR_READONLY,
    $cfg['appId'], $cfg['appSecret']
);

if(!empty($_GET['code'])) {
    try {
        $token = $gClient->authenticate($_GET['code']);
    } catch(Exception $e) {
        $token = '';
    }
    if(!empty($token))
        $_SESSION['googleToken'] = $token;
    header('Location: '.getCurrentUrl());
    exit;
}

if (isset($_SESSION['googleToken'])) {
    $gClient->setAccessToken($_SESSION['googleToken']);
    if($gClient->isAccessTokenExpired()) {
        try {
            $gClient->refreshToken($gClient->getRefreshToken());
        } catch(Exception $e) {
            header('Location: '.$gClient->createAuthUrl());
            exit;
        }
    }

	$calId = isset($_GET['calendar']) ? $_GET['calendar'] : '';
	$clientName = isset($_GET['client']) ? $_GET['client'] : 'Dego';
	$hourlyRate = isset($_GET['rate']) ? (float)$_GET['rate'] : 0;
	$dateChangeHour = isset($_GET['datechangehour']) ? (int)$_GET['datechangehour'] : 4;

    $calendarService = new Google_Service_Calendar($gClient);
    $calendarList = $calendarService->calendarList->listCalendarList();

	if(!empty($calId)) {
        $isValidCal = false;
		foreach ($calendarList as $calendar) {
			if($calendar->id == $calId)  {
				$isValidCal = true;
				break;
			}
		}
		if(!$isValidCal)
			die(':(');

        $range = @$_GET['range'];
        if(empty($range))
            $range = 'now';
        $firstDay = new DateTime($range);
        $firstDay->setDate($firstDay->format('Y'), $firstDay->format('m'), 1);
        $firstDay->setTime(0, 0, 0);
        $numRange = [
            $firstDay->getTimestamp(),
            (int)lastDay($firstDay->format('m'), $firstDay->format('Y'), 'U')
        ];
        $range = [
            $firstDay->format(DATE_RFC3339),
            lastDay($firstDay->format('m'), $firstDay->format('Y'), DATE_RFC3339)
        ];

        $query = [
            'singleEvents' => true,
            'orderBy' => 'startTime',
            'maxResults' => 2500, // max
            'timeMin' => $range[0],
            'timeMax' => $range[1],
        ];
        if(!empty($clientName))
            $query['q'] = $clientName;
        $events = $calendarService->events->listEvents($calId, $query);
        $eventsList = [];
        while(true) {
            foreach($events->getItems() as $event)
                $eventsList[] = $event;
            $pageToken = $events->getNextPageToken();
            if ($pageToken)
                $events = $calendarService->events->listEvents(
                    $calId,
                    array_merge($query, ['pageToken' => $pageToken])
                );
            else
                break;
        }

        $sum = 0;
        $entries = $entriesByDate = [];
        foreach ($eventsList as $event) {
            if(stripos($event->summary, $clientName) === false)
                continue;
            $startTime = new DateTime($event->start->dateTime);
            $endTime = new DateTime($event->end->dateTime);
            $diff = $endTime->getTimestamp() - $startTime->getTimestamp();
            if($diff >= 24 * 3600)
                continue;
            $sum += $diff;
            $diffMinutes = ($diff % 3600)/60;
            $diffMinutes = ($diffMinutes > 0)
                ? ':'.str_pad($diffMinutes, 2, '0', STR_PAD_LEFT)
                : '';
            $date = $startTime->format('j');
            if($startTime->format('G') < $dateChangeHour && $date > 1)
                $date--;
            $entry = [
                'title' => str_ireplace($clientName, '', $event->summary),
                'date' => $date,
                'startTime' => $startTime->format('H:i'),
                'endTime' => $endTime->format('H:i'),
                'diff' => floor($diff / 3600).$diffMinutes,
            ];
            $entries[] = $entry;
            if(empty($entriesByDate[$date]))
                $entriesByDate[$date] = [];
            $entriesByDate[$date][] = $entry;
        }
        $sumHours = floor($sum / 3600);
        $sumMinutes = ($sum % 3600) / 60;
        $adjustMinutes = 0;

        // rounding to 30 m, >= 15 means +
        $adjustMinutes = $sumMinutes % 30;
        if($adjustMinutes > 0) {
            if($adjustMinutes >= 15) {
                $adjustMinutes = 30 - $adjustMinutes;
                $sumMinutes += $adjustMinutes;
                if($sumMinutes == 60) {
                    $sumMinutes = 0;
                    $sumHours++;
                }
            } else {
                $adjustMinutes = -$adjustMinutes;
                $sumMinutes += $adjustMinutes;
            }
            $sum += $adjustMinutes * 60;
        }
        $sumMinutes = ($sumMinutes > 0) ? ':'.str_pad($sumMinutes, '0', 2) : '';
        $sumWages = number_format($sum * $hourlyRate / 3600, 2);
    }
}

?><!doctype html>
<html>
	<head>
		<title>Billing</title>
		<link href='http://fonts.googleapis.com/css?family=Archivo+Narrow:400,700&subset=latin,cyrillic' rel='stylesheet' type='text/css' />
		<style>
		body {
			margin: 20px 40px;
		}
		html, body, caption, th, td, p, select, option, li, fieldset, button, input {
			font-family: "Archivo Narrow", Tahoma, sans-serif;
			font-size: 1em;
			font-weight: 400;
		}
		th {
			font-weight: 700;
		}
		table, th, td {
			border: 0;
		}
		th, td {
			padding: 5px 10px;
		}
		</style>
		<script>
		function showhide(id) {
			var el = document.getElementById(id);
			el.style.display = (el.style.display == 'none') ? 'block' : 'none';
		}

		var _gaq = _gaq || [];
        _gaq.push(['_setAccount', 'UA-1931660-8']);
        _gaq.push(['_trackPageview']);
        (function() {
			var ga = document.createElement('script'); ga.type = 'text/javascript'; ga.async = true;
			ga.src = ('https:' == document.location.protocol ? 'https://ssl' : 'http://www') + '.google-analytics.com/ga.js';
			var s = document.getElementsByTagName('script')[0]; s.parentNode.insertBefore(ga, s);
		})();
		</script>
	</head>
	<body>
		<h1>Billing</h1>
		<?php
		if (!isset($_SESSION['googleToken'])) { ?>
            <a href="<?=$gClient->createAuthUrl()?>">
                Please login to your Google Account. Nothing is saved on the server.
            </a>
        <?php } elseif(!empty($calId)) { ?>
            <form method="get" action="">
                <fieldset>
                    <legend>Choose events</legend>
                    <p>
                        Calendar:
                        <select name="calendar" onchange="this.form.submit()">
                            <?php foreach ($calendarList as $calendar) { ?>
                                <option value="<?=$calendar->id?>"<?=($calId == $calendar->id ? ' selected' : '')?>><?=$calendar->summary?></option>
                            <?php } ?>
                        </select>&nbsp;
                        Month:
                        <select name="range" onchange="this.form.submit()">
                            <?php for($i = 0; $i < 12; $i++) {
                                $d = new DateTime("first day of -$i month");
                                $d->setTime(0, 0, 0); ?>
                            <option value="<?=$d->format(DATE_ATOM)?>"<?=($d->getTimestamp() == $numRange[0] ? ' selected' : '')?>><?=$d->format('F Y')?></option>
                            <?php } ?>
                        </select>&nbsp;
                        Date change hour: <input type="text" name="datechangehour" value="<?=$dateChangeHour?>" size="2" />:00&nbsp; &nbsp;
                        Rate: <input type="text" name="rate" value="<?=$hourlyRate?>" size="5" />&nbsp;
                        Client: <input type="text" name="client" value="<?=$clientName?>" />&nbsp;
                        <input type="submit" value="Calculate" />
                    </p>
                    <p><small>Expected calendar entry title format: «[Client] [Optional comments in round brackets]».
                        Enter hourly rate to see expected wage.
                        [Only] total time is rounded to the closest 30 minutes, up or down.
                        Date change hour makes sense if it's between 0 and 12.
                    </small></p>
                    <p><small>Privacy policy: nothing is saved on the server.</small></p>
                </fieldset>
            </form>

            <p><button onclick="showhide('t')">Show/hide table</button></p>
            <table id="t" style="display: none">
                <caption>
                    <?=date('Y-m-d', $numRange[0])?>&ndash;<?=date('Y-m-d', $numRange[1])?>
                </caption>
                <thead>
                    <tr><th>Date</th><th>Start</th><th>End</th><th>Time</th><th>Comments</th></tr>
                </thead>
                <tbody>
                    <?php $lastDate = ''; foreach($entries as $item) { ?>
                    <tr>
                        <td><?php if($item['date'] != $lastDate) { echo $item['date']; $lastDate = $item['date']; }?></td>
                        <td><?=$item['startTime']?></td><td><?=$item['endTime']?></td>
                        <td><?=$item['diff']?></td><td><?=$item['title']?></td>
                    </tr>
                    <?php } ?>
                </tbody>
            </table>

            <p><button onclick="showhide('l')">Show/hide list</button></p>
            <ul id="l">
                <?php foreach($entriesByDate as $date => $items){ ?>
                <li>
                    <?=$date?>.
                    <?php foreach($items as $n => $item){ ?>
                        <?=$item['startTime']?>&ndash;<?=$item['endTime']?> =
                        <?=trim( $item['diff'].$item['title'].($n < count($items)-1 ? '; ' : '') )?>
                    <?php } ?>
                </li>
                <?php } ?>
            </ul>

            <p><strong>Total:</strong> <?=$sumHours.$sumMinutes?> h <?=($adjustMinutes != 0 ? " (adjusted by $adjustMinutes m)" : '')?></p>
            <?php if($sumWages > 0) { ?><p><small>(<?=$sumWages?> coins)</small></p><?php } ?>
		
		<?php } else { ?>
            <form method="get" action="">
                <fieldset>
                    <legend>Choose events</legend>
                    <p>Calendar:
                    <select name="calendar" onchange="this.form.submit()">
                        <?php foreach ($calendarList as $calendar) { ?>
                            <option value="<?=$calendar->id?>"><?=$calendar->summary?></option>
                        <?php } ?>
                    </select>
                    <input type="submit" value="Continue" />
                    </p>
                </fieldset>
            </form>
		<?php } ?>
	</body>
</html>
