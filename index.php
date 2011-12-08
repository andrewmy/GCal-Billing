<?php

require_once 'Zend/Loader.php';
Zend_Loader::loadClass('Zend_Gdata');
Zend_Loader::loadClass('Zend_Gdata_AuthSub');
Zend_Loader::loadClass('Zend_Gdata_ClientLogin');
Zend_Loader::loadClass('Zend_Gdata_HttpClient');
Zend_Loader::loadClass('Zend_Gdata_Calendar');
$_authSubKeyFile = null; // Example value for secure use: 'mykey.pem'
$_authSubKeyFilePassphrase = null;


/**
 * Returns the full URL of the current page, based upon env variables
 *
 * Env variables used:
 * $_SERVER['HTTPS'] = (on|off|)
 * $_SERVER['HTTP_HOST'] = value of the Host: header
 * $_SERVER['SERVER_PORT'] = port number (only used if not http/80,https/443)
 * $_SERVER['REQUEST_URI'] = the URI after the method of the HTTP request
 *
 * @return string Current URL
 */
function getCurrentUrl()
{
  global $_SERVER;

  /**
   * Filter php_self to avoid a security vulnerability.
   */
  $php_request_uri = htmlentities(substr($_SERVER['REQUEST_URI'], 0, strcspn($_SERVER['REQUEST_URI'], "\n\r")), ENT_QUOTES);

  if (isset($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) == 'on') {
    $protocol = 'https://';
  } else {
    $protocol = 'http://';
  }
  $host = $_SERVER['HTTP_HOST'];
  if ($_SERVER['SERVER_PORT'] != '' &&
     (($protocol == 'http://' && $_SERVER['SERVER_PORT'] != '80') ||
     ($protocol == 'https://' && $_SERVER['SERVER_PORT'] != '443'))) {
    $port = ':' . $_SERVER['SERVER_PORT'];
  } else {
    $port = '';
  }
  return $protocol . $host . $port . $php_request_uri;
}

/**
 * Returns the AuthSub URL which the user must visit to authenticate requests
 * from this application.
 *
 * Uses getCurrentUrl() to get the next URL which the user will be redirected
 * to after successfully authenticating with the Google service.
 *
 * @return string AuthSub URL
 */
function getAuthSubUrl()
{
  global $_authSubKeyFile;
  $next = getCurrentUrl();
  $scope = 'http://www.google.com/calendar/feeds/';
  $session = true;
  $secure =  ($_authSubKeyFile != null);
  return Zend_Gdata_AuthSub::getAuthSubTokenUri($next, $scope, $secure, $session);
}

/**
 * Outputs a request to the user to login to their Google account, including
 * a link to the AuthSub URL.
 *
 * Uses getAuthSubUrl() to get the URL which the user must visit to authenticate
 *
 * @return void
 */
function requestUserLogin($linkText)
{
  $authSubUrl = getAuthSubUrl();
  echo "<a href=\"{$authSubUrl}\">{$linkText}</a>";
}

/**
 * Returns a HTTP client object with the appropriate headers for communicating
 * with Google using AuthSub authentication.
 *
 * Uses the $_SESSION['sessionToken'] to store the AuthSub session token after
 * it is obtained.  The single use token supplied in the URL when redirected
 * after the user succesfully authenticated to Google is retrieved from the
 * $_GET['token'] variable.
 *
 * @return Zend_Http_Client
 */
function getAuthSubHttpClient()
{
  global $_SESSION, $_GET, $_authSubKeyFile, $_authSubKeyFilePassphrase;
  $client = new Zend_Gdata_HttpClient();
  if ($_authSubKeyFile != null) {
    // set the AuthSub key
    $client->setAuthSubPrivateKeyFile($_authSubKeyFile, $_authSubKeyFilePassphrase, true);
  }
  if (!isset($_SESSION['sessionToken']) && isset($_GET['token'])) {
    $_SESSION['sessionToken'] =
        Zend_Gdata_AuthSub::getAuthSubSessionToken($_GET['token'], $client);
  }
  $client->setAuthSubToken($_SESSION['sessionToken']);
  return $client;
}


/**
 * Outputs an HTML unordered list (ul), with each list item representing a
 * calendar in the authenticated user's calendar list.
 *
 * @param  Zend_Http_Client $client The authenticated client object
 * @return void
 */
function outputCalendarList($client)
{
  $gdataCal = new Zend_Gdata_Calendar($client);
  $calFeed = $gdataCal->getCalendarListFeed();
  echo "<h1>" . $calFeed->title->text . "</h1>\n";
  echo "<ul>\n";
  foreach ($calFeed as $calendar) {
    echo "\t<li>" . $calendar->title->text . "</li>\n";
  }
  echo "</ul>\n";
}


function lastday($month = '', $year = '')
{
   if (empty($month))
      $month = date('m');
   if (empty($year))
      $year = date('Y');
   $result = strtotime("{$year}-{$month}-01");
   $result = strtotime('-1 second', strtotime('+1 month', $result));
   return date('Y-m-d H:i:s', $result);
}


session_start();
header('Content-type: text/html; charset=utf-8');

if (isset($_SESSION['sessionToken']) || isset($_GET['token'])) {
	$gclient = getAuthSubHttpClient();
	$gdataCal = new Zend_Gdata_Calendar($gclient);
	$calFeed = $gdataCal->getCalendarListFeed();
	$calName = isset($_GET['calendar']) ? $_GET['calendar'] : '';
	$client = isset($_GET['client']) ? $_GET['client'] : 'Dego';
	$rate = isset($_GET['rate']) ? (float)$_GET['rate'] : 0;
	if(!empty($calName)) {
		$calID = '';
		foreach ($calFeed as $calendar) {
			if($calendar->title->text == $calName)  {
				$calID = $calendar->link[0]->href;
				break;
			}
		}
		if(empty($calID))
			die(':(');
		
	  $query = $gdataCal->newEventQuery($calID);
	  $query->setUser(null); // default
	  $query->setVisibility(null); // 'private'
	  $query->setProjection(null); //'full'
	  $query->setOrderby('starttime');
	  $query->setSortorder('ascending');
	  
	  $range = @$_GET['range'];
	  if(empty($range))
		$range = date('Y-m-01 00:00:00');
	  $rangeTime = strtotime($range);
	  $range = array($range, lastday(date('m', $rangeTime), date('Y', $rangeTime)));
	  $query->setStartMin($range[0]);
	  $query->setStartMax($range[1]);
	  
	  $eventFeed = $gdataCal->getCalendarEventFeed($query);
	  $sum = 0;
	  $entries = array();
	  $entriesByDate = array();
	  foreach ($eventFeed as $event) {
		if(stripos($event->title->text, $client) === false)
			continue;
		$startTime = strtotime($event->when[0]->startTime);
		$endTime = strtotime($event->when[0]->endTime);
		$diff = $endTime - $startTime;
		if($diff >= 24 * 3600)
			continue;
		$sum += $diff;
		$diffM = ($diff % 3600)/60;
		$diffM = ($diffM > 0) ? ':'.str_pad($diffM, '0', 2) : '';
		$date = date('j', $startTime);
		$entry = array(
			'title' => str_ireplace($client, '', $event->title->text),
			'date' => $date,
			'startTime' => date('H:i', $startTime),
			'endTime' => date('H:i', $endTime),
			'diff' => floor($diff / 3600).$diffM,
		);
		$entries[] = $entry;
		if(empty($entriesByDate[$date]))
			$entriesByDate[$date] = array();
		$entriesByDate[$date][] = $entry;
	  }
	  $sumH = floor($sum / 3600);
	  $sumM = ($sum % 3600)/60;
	  $adjustM = 0;
	  
	  // rounding to 30 m, >= 15 means +
	  $adjustM = $sumM % 30;
	  if($adjustM > 0) {
		if($adjustM >= 15) {
			$adjustM = 30 - $adjustM;
			$sumM += $adjustM; 
			if($sumM == 60) {
				$sumM = 0;
				$sumH++;
			}
		} else {
			$adjustM = -$adjustM;
			$sumM += $adjustM;
		}
		$sum += $adjustM * 60;
	  }
	  $sumM = ($sumM > 0) ? ':'.str_pad($sumM, '0', 2) : '';
	  $sumLs = number_format($sum * $rate / 3600, 2);
  }
}

?><!doctype html>
<html>
	<head>
		<title>Billing</title>
		<style>
		body {
			margin: 20px 40px;
		}
		h1, h2 {
			font-family: Tahoma;
		}
		caption, th, td, p, select, option, li, fieldset, button {
			font-family: Tahoma;
			font-size: 9pt;
		}
		table, th, td {
			border: 0;
		}
		th, td {
			padding: 5px 10px;
		}
		#t {
			display: none;
		}
		</style>
		<script>
		function showhide(id) {
			var el = document.getElementById(id);
			el.style.display = (el.style.display == 'none') ? 'block' : 'none';
		}
		</script>
	</head>
	<body>
		<h1>Billing</h1>
		<?php
		if (!isset($_SESSION['sessionToken']) && !isset($_GET['token']))
			requestUserLogin('Please login to your Google Account.');
		elseif(!empty($calName)) {
		
		?>
		<form method="get" action="">
			<fieldset>
				<legend>Choose events</legend>
				<p>Calendar:
					<select name="calendar" onchange="this.form.submit()">
						<? foreach ($calFeed as $calendar) { ?>
						<option value="<?=$calendar->title->text?>"<?=($calName == $calendar->title->text ? ' selected' : '')?>><?=$calendar->title->text?></option>
						<? } ?>
					</select>
					Month:
					<select name="range" onchange="this.form.submit()">
						<? for($i = 0; $i < 12; $i++) { $d = strtotime(date('Y-m-01', strtotime("-$i month"))); ?>
						<option value="<?=date('Y-m-d', $d)?>"<?=(date('Y-m-d', $d) == $range[0] ? ' selected' : '')?>><?=date('F Y', $d)?></option>
						<? } ?>
					</select>
					Rate: <input type="text" name="rate" value="<?=$rate?>" />
					Client: <input type="text" name="client" value="<?=$client?>" />
					<input type="submit" value="Calculate" />
				</p>
				<p><small>Expected calendar entry title format: «[Client] [Comments]». Enter hourly rate to see expected wage. [Only] total time is rounded to the closest 30 minutes, up or down.</small></p>
			</fieldset>
		</form>
		
		<p><button onclick="showhide('t')">Show/hide table</button></p>
		<table id="t">
			<caption><?=$range[0]?>&ndash;<?=$range[1]?></caption>
			<thead>
				<tr><th>Date</th><th>Start</th><th>End</th><th>Time</th><th>Comments</th></tr>
			</thead>
			<tbody>
				<? $lastDate = ''; foreach($entries as $item) { ?>
				<tr>
					<td><? if($item['date'] != $lastDate) { echo $item['date']; $lastDate = $item['date']; }?></td>
					<td><?=$item['startTime']?></td><td><?=$item['endTime']?></td>
					<td><?=$item['diff']?></td><td><?=$item['title']?></td>
				</tr>
				<? } ?>
			</tbody>
		</table>
		
		<p><button onclick="showhide('l')">Show/hide list</button></p>
		<ul id="l">
			<? foreach($entriesByDate as $date => $items){ ?>
			<li>
				<?=$date?>.
				<? foreach($items as $n => $item){ ?>
				<?=$item['startTime']?>&ndash;<?=$item['endTime']?> = <?=$item['diff']?>
				<?=$item['title']?><?=($n < count($items)-1 ? '; ' : '')?>
				<? } ?>
			</li>
			<? } ?>
		</ul>
		
		<p><strong>Total:</strong> <?=$sumH.$sumM?> h <?=($adjustM != 0 ? " (adjusted by $adjustM m)" : '')?></p>
		<? if($sumLs > 0) { ?><p><small>(Ls <?=$sumLs?>)</small></p><? } ?>
		
		<? } else { ?>
		<form method="get" action="">
			<fieldset>
				<legend>Choose events</legend>
				<p>Calendar:
				<select name="calendar" onchange="this.form.submit()">
					<? foreach ($calFeed as $calendar) { ?>
					<option value="<?=$calendar->title->text?>"><?=$calendar->title->text?></option>
					<? } ?>
				</select>
				<input type="submit" value="Continue" />
				</p>
			</fieldset>
		</form>
		<? } ?>
	</body>
</html>
