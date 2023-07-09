<?php
  require_once(__DIR__ . '/config/bot.php');
  require_once(__DIR__ . '/config/google.php');
  require_once(__DIR__ . '/config/posts.php');
  $status = include(__DIR__ . '/config/status.php');

  if (php_sapi_name() != 'cli') {
    throw new Exception('This application must be run on the command line.');
  }

  // Placeholder Runtime Variables - DO NOT EDIT
  $bearerjwt = '';
  $repodid = '';

  /**
   * Returns an authorized API client.
   * @return Google_Client the authorized client object
   */
  function getClient()
  {
    global $googleAppName, $googleAppKey;
    $client = new Google_Client();
    $client->setApplicationName($googleAppName);
    $client->setDeveloperKey($googleAppKey);
    $client->useApplicationDefaultCredentials();
    $client->setScopes(Google_Service_Calendar::CALENDAR_READONLY);
    return $client;
  }

  /**
   * getEventDate($sourceEvent, $eventType = 'next', $start = true)
   *  Simple function to return the string format of $sourceEvent's date/dateTime
   *  - $sourceEvent = instance of Google_Service_Calendar_Event Object (not an array of events)
   *  - $eventType = 'next' (default): nature of event. Possible options ('next','past'), though 
   *      any argument other than 'next' is treated as 'past'
   *      $eventType is used to nuance a default date if/when none exists.
   *  - $start = obtain the event's start (true) or end (false) time.
   * @return string the event date
   */
  function getEventDate($sourceEvent, $eventType = 'next', $start = true) {
    global $allDayEventEndTime;
    if (empty($sourceEvent)) {
      // Default to today/now in unlikely situation $sourceEvent is empty
      $year = ('next' == $eventType) ? date('Y') + 1 : date('Y') - 1;
      $calcDate = "$year-01-01";
    } else {
      // If dateTime format is availble, prefer it (not an all-day event)
      $calcDate = ($start) ? $sourceEvent->start->dateTime : $sourceEvent->end->dateTime;
      if (empty($calcDate)) {
        // Use date format if dateTime isn't available (an all-day event), add time for "end" dates.
        $calcDate = ($start) ? $sourceEvent->start->date : $sourceEvent->start->date . " " . $allDayEventEndTime;
      }
    }
    return $calcDate;
  }

  // Get the API client and construct the service object.
  $client = getClient();
  $service = new Google_Service_Calendar($client);

  // Obtain most recent (past) event
  $lastResult = $service->events->listEvents($calendarId, $pastParams);
  $lastEvents = $lastResult->getItems();
  $lastEvent = end($lastEvents);
  $leStart = getEventDate($lastEvent, $eventType = 'past', true);
  $leEnd = getEventDate($lastEvent, $eventType = 'past', false);
  
  // Obtain first upcoming event
  $results = $service->events->listEvents($calendarId, $futureParams);
  $events = $results->getItems();
  $nextEvent = reset($events);
  $start = getEventDate($nextEvent, $eventType = 'next', true);
  $end = getEventDate($nextEvent, $eventType = 'next', false);

  $activeEvent = false;
  $eventInterval = date_diff(date_create(($respectEventDuration) ? $leEnd : $leStart), date_create($start));
  $timePassed = date_diff(date_create(($respectEventDuration) ? $leEnd : $leStart), date_create());
  if ($leStart == $start) {
    if ($respectEventDuration) {
      if ($timeAtRun <= $leEnd) {
        // Handle div/0 and calculation inversion situation for an active all-day event
        $eventInterval = date_diff(date_create($leStart), date_create($leEnd));
        $timePassed = date_diff(date_create($leStart), date_create());
        $activeEvent = true;
      }
    } else {
      // Active event but we don't care (post progress), grab the next one and recalculate.
      $nextEvent = next($events);
      $start = getEventDate($nextEvent, $eventType = 'next', true);
      $end = getEventDate($nextEvent, $eventType = 'next', false);
      $eventInterval = date_diff(date_create($leStart), date_create($start));
    }
  }

  $percentComplete = intval((((($timePassed->days * 24) + $timePassed->h) * 60) + $timePassed->i) / (((($eventInterval->days * 24) + $eventInterval->h) * 60) + $eventInterval->i) * 100);
  $completeBars = min(intval($percentComplete / (100 / ($totalBars + 1))), $totalBars);
  $incompleteBars = $totalBars - $completeBars;

  // Debug information if necessary
  if($debug_bot) { $debug_info = array('lastEvent' => $lastEvent, 'nextEvent' => $nextEvent, 'eventInterval' => $eventInterval, 'timePassed' => $timePassed, 'percentComplete' => $percentComplete, 'completeBars' => $completeBars, 'incompleteBars' => $incompleteBars); }

  /**
   * We make a special post when we match that special circumstance when a holiday/event is reached the first time.
   *  Post a little differently (celebrate!)
   *  - this is the FIRST TIME we've seen this event title/summary.
   *      The logic looks weird because $lastEvent->getSummary() is for the _current_ run and $status['lastEventSummary']
   *      is for the _previous_ run of the bot script.
  */
  if ($lastEvent->getSummary() != $status['lastEventSummary']) {
    $postText = "Hooray! It's " . $lastEvent->getSummary() . "!";
    if ($debug_bot) {
      $debug_info['postText'] = $postText;
      $debug_info['postSubmitted'] = 'yes';
    }
    Auth($debug_post);
    $result = Post($postText, $bearerjwt, $repodid, $debug_post);
  /**
   * Prepare to normally post...if we should (see negative logic). We don't normally post when:
   *  - an active event is happening today (no post), unless we don't respect active events
   *  - the percent complete hasn't changed since our last go (no post)
   */
  } else if (!((($respectEventDuration) && ($activeEvent)) || ($percentComplete == $status['lastPercentComplete']))) {
    // Craft a traditional post text
    $postText = "";
    // Progress bar creation...
    $z = 0;
    while ($z < $completeBars) {
      $postText .= $progressCharacter;
      $z++;
    }
    $z = 0;
    while ($z < $incompleteBars) {
      $postText .= $remainingCharacter;
      $z++;
    }

    // Auxiliary/Ancillary text for the post
    $postText .= " $percentComplete%\n\n";
    if ($includeEventSummary) {
      $postText .= "from " . $lastEvent->getSummary();
      if ($includeLastEventDate) { $postText .= " (" . $leStart . ")"; }
      $postText .= " to " . $nextEvent->getSummary();
      if ($includeNextEventDate) { $postText .= " (" . $start . ")"; }
      $postText .= "!";
    } else {
      $postText .= "to the next holiday!";
    }

    if ($debug_bot) {
      $debug_info['postText'] = $postText;
      $debug_info['postSubmitted'] = 'yes';
    }
    Auth($debug_post);
    $result = Post($postText, $bearerjwt, $repodid, $debug_post);
  /**
   * Skip post
   */
  } else {
    // We skipped posting for a reason
    if ($debug_bot) { $debug_info['skippedActiveEvent'] = (($respectEventDuration) && ($activeEvent)) ? 'yes' : 'no'; }
    if ($debug_bot) { $debug_info['skippedSamePercent'] = ($percentComplete == $status['lastPercentComplete']) ? 'yes' : 'no'; }
    if ($debug_bot) { $debug_info['skippedSameSummary'] = ($lastEvent->getSummary() == $status['lastEventSummary']) ? 'yes' : 'no'; }
    // Craft $result response for skipped post
    if ($debug_bot) { $debug_info['postSubmitted'] = 'no'; }
    $result = 'Skipped Post';
  }

  // Handle debugging output
  if ($debug_bot) {
    $debug_info['postResponse'] = $result;
    $debug_info['timestamp'] = date('r');
    if ('json' == $debug_format) {
      print json_encode($debug_info);
    } else {
      print_r($debug_info);
    }
  } else if ($debug_post) {
    print $result;
  }

  // Finally, write out our last status
  $status['lastPercentComplete'] = $percentComplete;
  $status['lastEventDate'] = $leStart;
  $status['lastEventSummary'] = $lastEvent->getSummary();
  file_put_contents(__DIR__ . '/config/status.php', '<?php return ' . var_export($status, true) . '; ?>');

  
  /**
   * Auth($debug = false)
   *  Authenticate against the ATProto host
   * 
   *  - $debug: (default false) enable debug mode for error examination
   */
  function Auth($debug = false) {
    global $atprotoHost, $bearerjwt, $repodid, $accountId, $accountCred;
    $accountLogin = array('identifier'=>$accountId,'password'=>$accountCred);

    $response = curlPost($atprotoHost, 'com.atproto.server.createSession', json_encode($accountLogin), array('Content-Type: application/json'));

    $loginResponse = json_decode($response,true);

    if (key_exists('error',$loginResponse)) {
      if ($debug) { print "\n\n\tAUTHENTICATION ERROR: $loginResponse[message]\n\n"; }
      die();
    } else {
      $repodid = $loginResponse['did'];
      $bearerjwt = $loginResponse['accessJwt'];
    }
  }

  /**
   * Post($postText, $bearerjwt, $repodid, $debug = false)
   *  Post a status of $postText to $repodid
   * 
   *  - $postText: Text of status to post
   *  - $bearerjwt: JWT bearer token
   *  - $repodid: the repo did ("account') to post to/as
   *  - $debug: (default false) enable debug mode for error examination
   *
   * @return JSON string of POST response
  */
  function Post($postText, $bearerjwt, $repodid, $debug = false) {
    global $atprotoHost;
  
    // Create/Submit cURL request
    $jsonPost = json_encode(array(
      'repo'=>$repodid,
      'collection'=>'app.bsky.feed.post',
      'record'=>array(
        '$type'=>'app.bsky.feed.post',
        'text'=>$postText,
        'createdAt'=>date('c',strtotime("now"))
      )
    ));
  
    $response = curlPost($atprotoHost, 'com.atproto.repo.createRecord', $jsonPost, array('Content-Type: application/json','Authorization: Bearer '.$bearerjwt));
  
    // Output is different in debug mode when an error is returned
    if ($debug) {
      if (key_exists("error", json_decode($response, true))) {
        return "Bad Request: $response";
      } else {
        return $response;
      }
    } else {
      return $response;
    }
  }


  /**
   * curlPost($host, $endpoint, $jsonData, $headerData)
   *  Simple cURL POST for ATProto XRPC call to $host/xrpc/$endpoint
   * 
   *  - $host: ATProto/Bluesky server host (e.g. `https://bsky.app` or `http://localhost:2583`)
   *  - $endpoint: XRPC endpoint (e.g. `com.atproto.server.createSession`)
   *  - $jsonData: JSON-encoded POST data
   *  - $headerData: Necessary HTTP header data (including content-type and Authorization tokens)
   *
   * @return JSON string of POST response
  */
  function curlPost($host, $endpoint, $jsonData, $headerData) {
    $curl = curl_init();

    $endpointUrl = $host . '/xrpc/' . $endpoint;
    curl_setopt_array($curl, array(
    CURLOPT_URL => $endpointUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => '',
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 0,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => 'POST',
    CURLOPT_POSTFIELDS => $jsonData,
    CURLOPT_HTTPHEADER => $headerData,
    ));

    $response = curl_exec($curl);

    curl_close($curl);

    return $response;
  }


  /**
   * curlGet($host, $endpoint, $headerData)
   *  Simple cURL GET for ATProto XRPC call to $host/xrpc/$endpoint
   * 
   *  - $host: ATProto/Bluesky server host (e.g. `https://bsky.app` or `http://localhost:2583`)
   *  - $endpoint: XRPC endpoint (e.g. `app.bsky.feed.getTimeline`)
   *  - $headerData: Necessary HTTP header data (including content-type and Authorization tokens)
   *
   * @return JSON string of GET response
  */
  function curlGet($host, $endpoint, $headerData) {
    $curl = curl_init();

    $endpointUrl = $host . '/xrpc/' . $endpoint;
    curl_setopt_array($curl, array(
      CURLOPT_URL => $endpointUrl,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_ENCODING => '',
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_TIMEOUT => 0,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST => 'GET',
      CURLOPT_HTTPHEADER => $headerData,
    ));

    $response = curl_exec($curl);

    curl_close($curl);

    return $response;
  }

?>